"""
Conserva lo que se hizo en el sistema nuevo cuando se vuelve a migrar con un .bak más reciente.

Cómo funciona:
  - Después de cada carga se guarda en `ultima_carga.json` exactamente lo que la migración metió en la base.
  - En la siguiente corrida se compara la base contra esa foto:
      * filas que no estaban en la foto  -> las creó alguien en el sistema nuevo: NO se tocan.
      * filas de la foto que cambiaron   -> ediciones hechas en el sistema nuevo: se guardan en
                                            `cambios_sistema_nuevo.json` (acumulativo).
      * filas de la foto que ya no están -> se borraron en el sistema nuevo: también se guardan.
  - Se reemplazan solo las filas que vinieron de la migración por las del .bak nuevo y, encima, se
    vuelven a aplicar las ediciones y borrados guardados. Si un dato cambió en los dos lados, gana el
    del sistema nuevo.

Las filas se reconocen entre corridas por su id cuando el id es estable (empleados, información laboral,
cabeceras de planilla, catálogos) y por una clave natural en las demás (detalle de planilla, historial,
otros ingresos y deducciones).
"""
import json
import os
import subprocess
import sys

MYSQL = r"C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe"
AQUI = os.path.dirname(os.path.abspath(__file__))
# Junto al .bak, en la carpeta "recursos" del frontend (no va al repositorio).
RECURSOS = os.path.normpath(os.path.join(AQUI, "..", "..", "..", "rrhh_frontend", "src", "assets", "recursos"))
RUTA_BASE = os.path.join(RECURSOS, "ultima_carga.json")
RUTA_CAMBIOS = os.path.join(RECURSOS, "cambios_sistema_nuevo.json")

# Tablas que llena la migración, en orden para insertar (los catálogos y padres primero).
TABLAS = ("departamentos", "cargos", "bancos", "informacion_laboral", "empleados", "historial_laboral",
          "cabecera_planillas", "detalle_planillas", "otros_ingresos", "otras_deducciones")
IGNORAR = {"created_at", "updated_at"}

CLAVE = {
    "detalle_planillas": ("id_cabecera_planilla", "id_empleado", "nombre_planilla"),
    "historial_laboral": ("id_empleado", "tipo_evento", "fecha"),
    "otros_ingresos": ("id_empleado", "nombre_planilla", "fecha", "descripcion", "monto"),
    "otras_deducciones": ("id_empleado", "nombre_planilla", "fecha", "descripcion", "monto"),
}


def _norm(v):
    if isinstance(v, bool):
        return int(v)
    if isinstance(v, (int, float)):
        return round(float(v), 2)
    if isinstance(v, str) and len(v) >= 19 and v[4] == "-" and v[10] in " T":
        return v[:19].replace("T", " ")  # timestamps: sin microsegundos
    return v


def iguales(a, b):
    return _norm(a) == _norm(b)


def clave(tabla, fila):
    if tabla not in CLAVE:
        return str(fila["id"])
    return json.dumps([_norm(fila.get(c)) for c in CLAVE[tabla]], ensure_ascii=False)


def mysql(db, sql, entrada=None):
    args = [MYSQL, "-uroot", "--default-character-set=utf8mb4", "-N", "-B", "--raw", db]
    if entrada is None:
        args += ["-e", sql]
    r = subprocess.run(args, input=entrada.encode("utf-8") if entrada else None, capture_output=True)
    if r.returncode != 0:
        sys.exit(f"Error en MySQL:\n{r.stderr.decode('utf-8', 'replace')}")
    return r.stdout.decode("utf-8")


def leer_tabla(db, tabla):
    cols = mysql(db, f"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{db}' "
                     f"AND TABLE_NAME='{tabla}' ORDER BY ORDINAL_POSITION").split()
    sql = "SELECT JSON_OBJECT(" + ",".join(f"'{c}',`{c}`" for c in cols) + f") FROM `{tabla}`"
    return [json.loads(l) for l in mysql(db, sql).splitlines() if l.strip()]


def leer_json(ruta, defecto):
    try:
        with open(ruta, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return defecto


def detectar(db):
    """Compara la base con la última carga. Devuelve lo que se creó en el sistema nuevo (por tabla) y deja
    guardadas en cambios_sistema_nuevo.json las ediciones y borrados de filas migradas."""
    base = leer_json(RUTA_BASE, None)
    if base is None:
        sys.exit("No existe ultima_carga.json: no se puede saber qué se cambió en el sistema nuevo.\n"
                 "Para la primera carga use --sin-preservar.")
    cambios = leer_json(RUTA_CAMBIOS, {})
    nuevos = {}
    resumen = {}
    for tabla in TABLAS:
        actual = {f["id"]: f for f in leer_tabla(db, tabla)}
        anterior = {f["id"]: f for f in base["filas"].get(tabla, [])}
        nuevos[tabla] = [f for i, f in actual.items() if i not in anterior]
        mod = cambios.setdefault(tabla, {}).setdefault("modificados", {})
        borr = set(cambios[tabla].setdefault("borrados", []))
        n_mod = n_borr = 0
        for i, fila in anterior.items():
            k = clave(tabla, fila)
            if i not in actual:
                borr.add(k)
                mod.pop(k, None)
                n_borr += 1
                continue
            borr.discard(k)
            dif = {c: actual[i][c] for c in fila if c not in IGNORAR and c in actual[i]
                   and not iguales(fila[c], actual[i][c])}
            if dif:
                mod[k] = dif
                n_mod += 1
            else:
                mod.pop(k, None)
        cambios[tabla]["borrados"] = sorted(borr)
        resumen[tabla] = (len(nuevos[tabla]), n_mod, n_borr)
    with open(RUTA_CAMBIOS, "w", encoding="utf-8") as f:
        json.dump(cambios, f, ensure_ascii=False, indent=1)
    return {"base": base, "nuevos": nuevos, "cambios": cambios, "resumen": resumen}


def sql_valor(v):
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return str(int(v))
    if isinstance(v, (int, float)):
        return repr(v)
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"


def sentencias_borrado(base):
    """Borra de la base solo las filas que puso la migración anterior."""
    out = []
    for tabla in reversed(TABLAS):
        ids = [f["id"] for f in base["filas"].get(tabla, [])]
        for i in range(0, len(ids), 1000):
            out.append(f"DELETE FROM {tabla} WHERE id IN ({','.join(map(str, ids[i:i + 1000]))});")
    return out


def sentencias_reaplicar(cambios, filas_nuevas):
    """Vuelve a aplicar sobre la carga nueva las ediciones y borrados hechos en el sistema nuevo.
    filas_nuevas: {tabla: [filas insertadas con su id]}. Devuelve (sentencias, sin_destino)."""
    out, sin_destino = [], []
    for tabla in TABLAS:
        por_clave = {}
        for f in filas_nuevas.get(tabla, []):
            por_clave.setdefault(clave(tabla, f), f["id"])
        c = cambios.get(tabla, {})
        for k, dif in c.get("modificados", {}).items():
            if k not in por_clave:
                sin_destino.append((tabla, k, dif))
                continue
            sets = ", ".join(f"`{col}` = {sql_valor(v)}" for col, v in dif.items())
            out.append(f"UPDATE {tabla} SET {sets} WHERE id = {por_clave[k]};")
        for k in c.get("borrados", []):
            if k in por_clave:
                out.append(f"DELETE FROM {tabla} WHERE id = {por_clave[k]};")
    return out, sin_destino


def guardar_base(filas_nuevas, claves):
    with open(RUTA_BASE, "w", encoding="utf-8") as f:
        json.dump({"filas": filas_nuevas, "claves": claves}, f, ensure_ascii=False)
