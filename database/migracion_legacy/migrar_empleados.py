"""
Migración del sistema viejo (SQL Server, HPR_RRHH) al sistema nuevo (MySQL, Laravel):
empleados y el histórico de planillas de pago.

Flujo:
  1. (opcional, --restaurar) restaura el .bak en SQL Server LocalDB, usando el respaldo más reciente del archivo.
  2. Exporta empleados, catálogos, planillas, otros ingresos y otras deducciones a JSON.
  3. Depura: duplicados por DNI (se queda el registro más reciente = idEmpleado más alto),
     DNIs de relleno (111, 1111, 0...) se registran igual con un DNI temporal único.
     Los empleados de la "Planilla Especial" (ene-may 2020) quedan fuera; esa planilla se archiva en Excel.
     Los aguinaldos del sistema viejo no se migran: son dos lotes abiertos con todos los montos en 0.
  4. Carga catálogos, informacion_laboral, empleados, historial_laboral, planillas (cabecera + detalle),
     otros ingresos y otras deducciones en la base MySQL destino. Las quincenas con 0 días también se
     migran, para que quede el registro.
     Antes vacía esas tablas y todo lo que cuelga de los empleados (planillas, aguinaldos, incidencias,
     vacaciones, marcaciones...), así que se puede correr cuantas veces haga falta.
     No toca usuarios, campos variables ni el log.
  5. Genera los reportes .md, el Excel de la planilla especial y el mapa de IDs viejos -> nuevos.

Los IDs de empleados e información laboral se conservan iguales a los del sistema viejo.

Uso:
  python migrar_empleados.py --restaurar --bak "ruta\\HPR_RRHH.bak" --destino rrhh_hpr
"""
import argparse
import glob
import collections
import json
import os
import re
import subprocess
import sys
import unicodedata

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import preservar  # noqa: E402

RUTA_MAPA = os.path.join(preservar.RECURSOS, "mapa_ids_empleados.json")
from datetime import date, datetime, timedelta

AQUI = os.path.dirname(os.path.abspath(__file__))
MYSQL = r"C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe"
SQLCMD = r"C:\Program Files\SqlCmd\sqlcmd.exe"
SQLLOCALDB = r"C:\Program Files\Microsoft SQL Server\170\Tools\Binn\SqlLocalDB.exe"
HOY = date.today()
ID_USUARIO = 1  # todo lo migrado queda a nombre del admin (informatica@)
FECHA_INGRESO_GENERICA = "2019-08-06"  # 47 registros la tienen: era el valor de relleno del sistema viejo

SALARIO_MINIMO = 16317.60  # vigente (Campos Variables): a quienes lo ganan se les marca "usa salario mínimo"
GAP_SEPARACION = 60  # días sin trabajar entre dos quincenas para considerar que el empleado salió y volvió


def _correcciones():
    """Correcciones confirmadas por RRHH (claves: idEmpleado del sistema anterior). Viven en
    recursos/correcciones_rrhh.json porque tienen datos personales y el repositorio es público:
      dni              -> DNI correcto (se aplica antes de buscar duplicados)
      fusiones         -> misma persona con DNI distinto: id que sobra -> id que se conserva; sus planillas con
                          días pasan al conservado y las quincenas en 0 del que sobra no se migran
      fecha_inicio     -> fecha de inicio correcta
      fecha_nacimiento -> fecha de nacimiento correcta
      liquidaciones    -> [fecha de cese, observación]: se le liquidó al cambiar de puesto; en el historial queda
                          un cese liquidado y un reintegro en la fecha de inicio nueva"""
    try:
        with open(os.path.join(preservar.RECURSOS, "correcciones_rrhh.json"), encoding="utf-8") as fh:
            c = json.load(fh)
    except OSError:
        c = {}
    ids = lambda nombre, conv=lambda v: v: {int(k): conv(v) for k, v in c.get(nombre, {}).items()}
    return (ids("dni"), ids("fusiones", int), ids("fecha_inicio"), ids("fecha_nacimiento"),
            ids("liquidaciones", tuple))


CORRECCIONES_DNI, FUSIONES_MANUALES, CORRECCIONES_INICIO, CORRECCIONES_NACIMIENTO, LIQUIDACIONES = _correcciones()

# ---------------------------------------------------------------- SQL Server


def pipe_localdb():
    out = subprocess.run([SQLLOCALDB, "info", "MSSQLLocalDB"], capture_output=True, text=True,
                         encoding="cp850", errors="replace").stdout
    if "np:" not in out:
        subprocess.run([SQLLOCALDB, "start", "MSSQLLocalDB"], check=True, capture_output=True)
        out = subprocess.run([SQLLOCALDB, "info", "MSSQLLocalDB"], capture_output=True, text=True,
                             encoding="cp850", errors="replace").stdout
    return re.search(r"(np:\S+)", out).group(1)


def sqlcmd(pipe, query, db="master", salida=None):
    args = [SQLCMD, "-S", pipe, "-E", "-d", db, "-y", "0", "-h", "-1", "-Q", "SET NOCOUNT ON; " + query]
    if salida:
        args += ["-o", salida]
    r = subprocess.run(args, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if r.returncode != 0:
        sys.exit(f"Error en sqlcmd:\n{r.stdout}\n{r.stderr}")
    return r.stdout


def restaurar(pipe, bak):
    datos = os.path.join(os.path.expanduser("~"), "sqldata")
    os.makedirs(datos, exist_ok=True)
    # El .bak acumula varios respaldos (2022 -> hoy): se restaura el último.
    cab = subprocess.run([SQLCMD, "-S", pipe, "-E", "-W", "-s", "|", "-h", "-1", "-Q",
                          f"SET NOCOUNT ON; RESTORE HEADERONLY FROM DISK=N'{bak}'"],
                         capture_output=True, text=True, encoding="utf-8", errors="replace").stdout
    posiciones = [int(l.split("|")[5]) for l in cab.splitlines() if l.count("|") > 20]
    ultimo = max(posiciones)
    print(f"  {len(posiciones)} respaldos en el archivo, se restaura el #{ultimo}")
    sqlcmd(pipe, f"RESTORE DATABASE HPR_RRHH FROM DISK=N'{bak}' WITH FILE={ultimo}, "
                 f"MOVE 'HPR_RRHH' TO N'{datos}\\HPR_RRHH.mdf', "
                 f"MOVE 'HPR_RRHH_log' TO N'{datos}\\HPR_RRHH_log.ldf', REPLACE")


def exportar(pipe, carpeta):
    consultas = {
        "Empleado": "SELECT idEmpleado,NombresEmpleado,ApellidosEmpleado,Cedula,RTN,Genero,FechaNacimiento,"
                    "EstadoCivil,NHijos,Nacionalidad,Residencia,Telefonos,ContactoEmergencia,TelefonoContacto,"
                    "Correo,TipoSangre,idBanco,idInfoLaboral,idPuesto,idDepartamento FROM dbo.Empleado",
        "InformacionLaboral": "SELECT * FROM dbo.InformacionLaboral",
        "Departamento": "SELECT * FROM dbo.Departamento",
        "Puesto": "SELECT * FROM dbo.Puesto",
        "Banco": "SELECT * FROM dbo.Banco",
        "PlanillaResumen": "SELECT idEmpleado, COUNT(*) n, MAX(FechaGeneradaPlanilla) ultima "
                           "FROM dbo.Planilla GROUP BY idEmpleado",
        "Planilla": "SELECT * FROM dbo.Planilla",
        "OtrosIngresos": "SELECT * FROM dbo.OtrosIngresos",
        "OtrasDeducciones": "SELECT * FROM dbo.OtrasDeducciones",
        "PlanillaEspecial": "SELECT * FROM dbo.PlanillaEspecial",
        # Día en que se registró cada empleado en el sistema anterior (el log empieza en ago/2021).
        "LogAltas": "SELECT idObjetoInvolucrado idEmpleado, MIN(Fecha) fecha FROM dbo.LogSistema "
                    "WHERE RTRIM(Accion) = 'Empleado Insert' GROUP BY idObjetoInvolucrado",
    }
    datos = {}
    for nombre, q in consultas.items():
        ruta = os.path.join(carpeta, nombre + ".json")
        sqlcmd(pipe, f"SELECT ({q} FOR JSON PATH, INCLUDE_NULL_VALUES)", db="HPR_RRHH", salida=ruta)
        with open(ruta, encoding="utf-8-sig") as f:
            txt = f.read().strip()
        datos[nombre] = json.loads(txt) if txt else []
    return datos


# ---------------------------------------------------------------- limpieza

ACENTOS = {
    "ADMINISTRACION": "ADMINISTRACIÓN", "ANIMACION": "ANIMACIÓN", "JARDINERIA": "JARDINERÍA",
    "LAVANDERIA": "LAVANDERÍA", "ACUATICO": "ACUÁTICO", "RECEPCION": "RECEPCIÓN",
    "INFORMATICA": "INFORMÁTICA", "MERCADOLOGA": "MERCADÓLOGA", "AGRONOMO": "AGRÓNOMO",
    "GRAFICA": "GRÁFICA", "ELECTRONICAS": "ELECTRÓNICAS", "SEGUIRDAD": "SEGURIDAD",
}
ESTADO_CIVIL = {"Soltero(a)": "Soltero/a", "Casado(a)": "Casado/a", "Divorciado(a)": "Divorciado/a",
                "Viudo(a)": "Viudo/a", "Union Libre": "Unión Libre"}
RELLENO = {"", "NA", "N/A", "SD", "S/D", "-", ".", "X", "NO", "NINGUNO", "NINGUNA", "NULL", "0"}


def txt(v):
    """Una sola línea, sin espacios dobles."""
    return re.sub(r"\s+", " ", str(v or "")).strip()


def mayus(v):
    return " ".join(ACENTOS.get(p, p) for p in txt(v).upper().split(" ")) if txt(v) else ""


def digitos(v):
    return re.sub(r"\D", "", str(v or ""))


def sin_acentos(v):
    return "".join(c for c in unicodedata.normalize("NFD", v) if unicodedata.category(c) != "Mn")


def es_relleno(v):
    t = txt(v).upper()
    if t in RELLENO or len(t) < 3:
        return True
    letras = re.sub(r"[^A-ZÁÉÍÓÚÑ]", "", t)
    return not letras or not re.search(r"[AEIOUÁÉÍÓÚ]", letras)  # "DFDF", "DSF", "1234"


def telefono_valido(v):
    d = digitos(v)
    return len(d) == 8 and len(set(d)) > 1


def dni_relleno(d):
    """0, 111, 1111, 11111111...: no identifica a nadie, varias personas comparten el mismo."""
    return len(d) != 13 or len(set(d)) == 1


def dni_formato_ok(d):
    return 1 <= int(d[:2]) <= 18 and 1900 <= int(d[4:8]) <= HOY.year


def fecha(v):
    return datetime.strptime(v[:10], "%Y-%m-%d").date() if v else None


def edad(nac):
    return HOY.year - nac.year - ((HOY.month, HOY.day) < (nac.month, nac.day))


def fmt(d):
    return d.strftime("%d/%m/%Y") if d else "—"


def motivo_cese(texto):
    t = sin_acentos(txt(texto).lower())
    if "renuncia" in t:
        return "Renuncia"
    if "despido" in t:
        return "Despido"
    if "fijo" in t:
        return "Fin de contrato"  # "Pasó a fijos": se cerró su contrato de extra
    if "mutuo" in t:
        return "Mutuo acuerdo"
    return "Sin especificar"


# ---------------------------------------------------------------- transformación


def inicio_quincena(f):
    return f.replace(day=1) if f.day <= 20 else f.replace(day=16)


def tramos_trabajados(filas):
    """filas: [(fecha planilla, tipo, días)] -> tramos [(desde, hasta, [(fecha, tipo)])] separados por más de
    GAP_SEPARACION días sin trabajar."""
    trabajadas = sorted((fecha(f), t) for f, t, dias in filas if dias)
    tramos = []
    for f, t in trabajadas:
        if tramos and (f - tramos[-1][1]).days <= GAP_SEPARACION:
            tramos[-1][1] = f
            tramos[-1][2].append((f, t))
        else:
            tramos.append([f, f, [(f, t)]])
    return tramos


CONTRATO_DE = {"Fijos": "Fijo", "Extras": "Extra"}


def cambios_de_tipo(quincenas):
    """Cambios Extras <-> Fijos que se sostienen al menos 2 quincenas: [(fecha, anterior, nuevo)]."""
    cambios, actual = [], quincenas[0][1]
    for i, (f, t) in enumerate(quincenas):
        if t != actual and all(q[1] == t for q in quincenas[i:i + 2]) and len(quincenas[i:i + 2]) == 2:
            cambios.append((f, CONTRATO_DE[actual], CONTRATO_DE[t]))
            actual = t
    return cambios


def historial_empleado(id_emp, filas, inicio, contrato, activo, cese, motivo_texto, liquidacion=None):
    """Movimientos de personal deducidos de las planillas, sin cambiar la fecha de inicio ni el contrato."""
    obs_deducido = "Deducido de las planillas del sistema anterior"
    ev = lambda tipo, f, **k: {"id_empleado": id_emp, "tipo_evento": tipo, "fecha": f.isoformat(),
                               "tipo_contrato_anterior": k.get("ant"), "tipo_contrato_nuevo": k.get("nuevo"),
                               "fecha_inicio_anterior": k.get("fi_ant"), "fecha_inicio_nueva": k.get("fi_nueva"),
                               "motivo_cese": k.get("motivo"), "liquidacion": k.get("liq"),
                               "observaciones": k.get("obs")}
    eventos = []
    if liquidacion:
        # Lo trabajado hasta la liquidación es un período cerrado; desde la fecha de inicio, uno nuevo.
        corte, obs_liq = fecha(liquidacion[0]), liquidacion[1]
        previas = [q for q in filas if fecha(q[0]) <= corte]
        filas = [q for q in filas if fecha(q[0]) > corte]
        antes = tramos_trabajados(previas)
        if antes:
            arranque = inicio_quincena(antes[0][0])
            eventos.append(ev("Ingreso", arranque, nuevo=CONTRATO_DE[antes[0][2][0][1]],
                              fi_nueva=arranque.isoformat(), obs=obs_deducido))
            eventos.append(ev("Cese", corte, motivo="Fin de contrato", liq="Sí", obs=obs_liq))
            eventos.append(ev("Reintegro", inicio, ant=CONTRATO_DE[antes[-1][2][-1][1]], nuevo=contrato,
                              fi_ant=arranque.isoformat(), fi_nueva=inicio.isoformat(), obs=obs_liq))
            if not activo:
                eventos.append(ev("Cese", cese or inicio, motivo=motivo_cese(motivo_texto),
                                  obs=mayus(motivo_texto)[:500] or None))
            return eventos
    tramos = tramos_trabajados(filas)
    # El tramo que corresponde a la fecha de inicio oficial: el último que empezó antes (con 45 días de margen).
    k = 0
    for i, (desde, _, _) in enumerate(tramos):
        if inicio_quincena(desde) <= inicio + timedelta(days=45):
            k = i
    inicio_anterior = None
    for i, (desde, hasta, quincenas) in enumerate(tramos[:k + 1] if tramos else []):
        arranque = inicio_quincena(desde)
        tipo0 = CONTRATO_DE[quincenas[0][1]]
        cambios = cambios_de_tipo(quincenas)
        if i < k:
            if i == 0:
                eventos.append(ev("Ingreso", arranque, nuevo=tipo0, fi_nueva=arranque.isoformat(), obs=obs_deducido))
            else:
                eventos.append(ev("Reintegro", arranque, nuevo=tipo0, fi_ant=inicio_anterior,
                                  fi_nueva=arranque.isoformat(), obs=obs_deducido))
            eventos += [ev("Cambio de contrato", inicio_quincena(fc), ant=a, nuevo=n, obs=obs_deducido)
                        for fc, a, n in cambios]
            eventos.append(ev("Cese", hasta, motivo="Sin especificar", obs=obs_deducido))
            inicio_anterior = arranque.isoformat()
            continue
        # tramo actual (el de la fecha de inicio oficial)
        if inicio > arranque + timedelta(days=45):
            # Ya trabajaba antes de su fecha de inicio, sin cortes: la fecha de inicio es la de un cambio
            # de contrato (p. ej. pasó de Extra a Fijo) o un ajuste de fecha posterior a su ingreso/reintegro.
            if i == 0:
                eventos.append(ev("Ingreso", arranque, nuevo=tipo0, fi_nueva=arranque.isoformat(), obs=obs_deducido))
            else:
                eventos.append(ev("Reintegro", arranque, nuevo=tipo0, fi_ant=inicio_anterior,
                                  fi_nueva=arranque.isoformat(), obs=obs_deducido))
            previo = [c for c in cambios if inicio_quincena(c[0]) <= inicio + timedelta(days=45)]
            eventos += [ev("Cambio de contrato", inicio_quincena(fc), ant=a, nuevo=n, obs=obs_deducido)
                        for fc, a, n in previo[:-1]]
            if previo:
                eventos.append(ev("Cambio de contrato", inicio, ant=previo[-1][1], nuevo=previo[-1][2],
                                  fi_ant=arranque.isoformat(), fi_nueva=inicio.isoformat(),
                                  obs="Fecha de inicio actual del empleado. " + obs_deducido))
            else:
                eventos.append(ev("Cambio de fecha", inicio, fi_ant=arranque.isoformat(), fi_nueva=inicio.isoformat(),
                                  obs="Trabajaba desde antes sin cortes; su fecha de inicio quedó en esta fecha. "
                                      + obs_deducido))
            cambios = [c for c in cambios if inicio_quincena(c[0]) > inicio + timedelta(days=45)]
        elif i == 0:
            eventos.append(ev("Ingreso", inicio, nuevo=contrato if not cambios else tipo0,
                              fi_nueva=inicio.isoformat(), obs="Migrado del sistema anterior"))
        else:
            eventos.append(ev("Reintegro", inicio, nuevo=contrato if not cambios else tipo0,
                              fi_ant=inicio_anterior, fi_nueva=inicio.isoformat(), obs="Migrado del sistema anterior"))
        eventos += [ev("Cambio de contrato", inicio_quincena(fc), ant=a, nuevo=n, obs=obs_deducido)
                    for fc, a, n in cambios if inicio_quincena(fc) > inicio]
    if not tramos:
        eventos.append(ev("Ingreso", inicio, nuevo=contrato, fi_nueva=inicio.isoformat(),
                          obs="Migrado del sistema anterior"))
    if not activo:
        eventos.append(ev("Cese", cese or inicio, motivo=motivo_cese(motivo_texto),
                          obs=mayus(motivo_texto)[:500] or None))
    return eventos


def transformar(d, reservas=None):
    info = {r["idInfoLaboral"]: r for r in d["InformacionLaboral"]}
    planilla = {r["idEmpleado"]: r for r in d["PlanillaResumen"] if r.get("idEmpleado") is not None}
    deptos = {r["idDepartamento"]: r for r in d["Departamento"]}
    empleados = sorted(d["Empleado"], key=lambda e: e["idEmpleado"])
    for e in empleados:
        if e["idEmpleado"] in CORRECCIONES_DNI:
            e["Cedula"] = CORRECCIONES_DNI[e["idEmpleado"]]
    # Los de la Planilla Especial (ene-may 2020) no pasan al sistema nuevo.
    de_especial = {r["idEmpleado"] for r in d["PlanillaEspecial"]}
    excluidos = [e for e in empleados if e["idEmpleado"] in de_especial]
    empleados = [e for e in empleados if e["idEmpleado"] not in de_especial]

    # --- catálogos
    cargos, mapa_cargo = {}, {}
    for p in sorted(d["Puesto"], key=lambda p: p["idPuesto"]):
        nombre = mayus(p["NPuesto"])
        if nombre not in cargos:  # "Eventos" y "Ayudante de Cocina" estaban repetidos
            cargos[nombre] = len(cargos) + 1
        mapa_cargo[p["idPuesto"]] = cargos[nombre]
    nombre_cargo = {v: k for k, v in cargos.items()}
    bancos = {b["idBanco"]: ("Banco Ficohsa" if "ficohsa" in b["NBanco"].lower() else txt(b["NBanco"]))
              for b in d["Banco"]}

    # --- duplicados por DNI (solo DNIs válidos; los de relleno son personas distintas)
    grupos = collections.defaultdict(list)
    for e in empleados:
        dni = digitos(e["Cedula"])
        if not dni_relleno(dni):
            grupos[dni].append(e)
    duplicados = {dni: g for dni, g in grupos.items() if len(g) > 1}
    descartados = {}  # idEmpleado viejo -> idEmpleado que se conserva
    for g in duplicados.values():
        conservado = max(g, key=lambda e: e["idEmpleado"])  # el registro más reciente
        for e in g:
            if e is not conservado:
                descartados[e["idEmpleado"]] = conservado["idEmpleado"]
    por_id = {e["idEmpleado"]: e for e in empleados}
    fusiones = [(por_id[v], por_id[c]) for v, c in FUSIONES_MANUALES.items() if v in por_id and c in por_id]
    descartados.update({v["idEmpleado"]: c["idEmpleado"] for v, c in fusiones})

    # Planillas de cada empleado conservado (incluye las de sus duplicados descartados)
    quincenas_de = collections.defaultdict(list)
    for r in d["Planilla"]:
        if r["idEmpleado"] in FUSIONES_MANUALES and not r["DiasTrabajados"]:
            continue
        conservado = descartados.get(r["idEmpleado"], r["idEmpleado"])
        quincenas_de[conservado].append((r["FechaGeneradaPlanilla"], r["TipoPlanilla"], r["DiasTrabajados"] or 0))
    alta_en_log = {r["idEmpleado"]: fecha(r["fecha"]) for r in d["LogAltas"]}
    fechas_corregidas = []  # (id viejo, campo, antes, después, de dónde sale)

    # --- empleados a importar
    filas_emp, filas_il, filas_hist, problemas, temporales = [], [], [], {}, []
    cuentas = collections.defaultdict(list)
    for e in empleados:
        if e["idEmpleado"] in descartados:
            continue
        il = info[e["idInfoLaboral"]]
        p = []  # problemas de este empleado
        dni_orig = txt(e["Cedula"])
        dni = digitos(dni_orig)
        if not dni_relleno(dni):
            cedula = dni
            if not dni_formato_ok(dni):
                p.append(f"DNI con formato sospechoso (`{dni}`: municipio `{dni[:4]}`, año `{dni[4:8]}`)")
        else:
            cedula = f"0000-0000-{len(temporales) + 1:05d}"
            temporales.append((cedula, e, il))
            p.append(f"DNI inválido o de relleno (`{dni_orig or 'vacío'}`) → se registró con DNI temporal `{cedula}`")

        nombres, apellidos = mayus(e["NombresEmpleado"]), mayus(e["ApellidosEmpleado"])
        if re.search(r"\d", nombres + apellidos):
            p.append("Nombre o apellido contiene números")
        if not apellidos:
            p.append("Sin apellidos")
        elif len(apellidos.split()) == 1:
            p.append("Solo tiene un apellido registrado")
        if apellidos and nombres.split() and nombres.split()[-1] == apellidos.split()[0]:
            p.append(f"El apellido parece estar repetido dentro de los nombres (`{nombres}` / `{apellidos}`)")
        for campo, v, lim in (("Nombres", nombres, 30), ("Apellidos", apellidos, 30)):
            if len(v) > lim:
                p.append(f"{campo} excede {lim} caracteres (se recortó)")

        nac = fecha(e["FechaNacimiento"])
        ingreso = fecha(il["FechaInicio"])
        invalida = nac.year > HOY.year - 15 or nac.year < 1940
        if e["idEmpleado"] in CORRECCIONES_NACIMIENTO:
            nuevo = fecha(CORRECCIONES_NACIMIENTO[e["idEmpleado"]])
            p.append(f"Fecha de nacimiento {fmt(nac)} → {fmt(nuevo)} (confirmada por RRHH)")
            fechas_corregidas.append((e["idEmpleado"], "Fecha de nacimiento", nac, nuevo, "confirmada por RRHH"))
            nac = nuevo
        elif invalida and not dni_relleno(dni) and dni_formato_ok(dni):
            # El año de nacimiento va en el DNI después del código de municipio (dígitos 5 a 8).
            try:
                nuevo = nac.replace(year=int(dni[4:8]))
            except ValueError:  # 29 de febrero en un año no bisiesto
                nuevo = nac.replace(year=int(dni[4:8]), day=28)
            p.append(f"Fecha de nacimiento inválida ({fmt(nac)}) → {fmt(nuevo)} (año tomado del DNI)")
            fechas_corregidas.append((e["idEmpleado"], "Fecha de nacimiento", nac, nuevo, "año tomado del DNI"))
            nac = nuevo
        elif invalida:
            p.append(f"Fecha de nacimiento inválida ({fmt(nac)})")
        elif ingreso and (ingreso.year - nac.year) < 14:
            p.append(f"Habría ingresado con menos de 14 años (nació {fmt(nac)}, ingresó {fmt(ingreso)})")
        if not dni_relleno(dni) and dni_formato_ok(dni) and abs(int(dni[4:8]) - nac.year) > 2:
            # el año del DNI es el de inscripción: 1-2 años de diferencia es normal
            p.append(f"El año del DNI ({dni[4:8]}) no coincide con el año de nacimiento ({nac.year})")

        rtn = digitos(e["RTN"]) or None
        if rtn and (len(rtn) != 14 or (not dni_relleno(dni) and rtn[:13] != dni)):
            p.append(f"RTN `{e['RTN']}` no corresponde al DNI")

        if not telefono_valido(e["Telefonos"]):
            p.append(f"Teléfono inválido o incompleto (`{txt(e['Telefonos']) or 'vacío'}`)")
        if es_relleno(e["ContactoEmergencia"]):
            p.append(f"Sin contacto de emergencia (`{txt(e['ContactoEmergencia']) or 'vacío'}`)")
        if not telefono_valido(e["TelefonoContacto"]):
            p.append(f"Teléfono de emergencia inválido o incompleto (`{txt(e['TelefonoContacto']) or 'vacío'}`)")
        residencia = mayus(e["Residencia"])
        if es_relleno(residencia):
            p.append(f"Sin residencia (`{txt(e['Residencia']) or 'vacío'}`)")
        if len(residencia) > 60:
            p.append("Residencia excede 60 caracteres (se recortó)")
        correo = txt(e["Correo"]).lower() or None
        if correo and not re.fullmatch(r"[^@\s]+@[^@\s]+\.[a-z]{2,}", correo):
            p.append(f"Correo inválido (`{correo}`) → no se importó")
            correo = None

        # --- información laboral
        contrato = il["Contrato"]
        if contrato == "Especial":
            p.append("Contrato `Especial` (ya no existe en el sistema nuevo) → se importó como `Extra`")
            contrato = "Extra"
        activo = il["Estado"] == "Activo"
        cese = None if activo else fecha(il["FechaCese"])
        tramos = tramos_trabajados(quincenas_de[e["idEmpleado"]])
        alta = alta_en_log.get(e["idEmpleado"])
        # Fecha de ingreso genérica: el formulario viejo ponía 06/08/2019 por defecto.
        if il["FechaInicio"] == FECHA_INGRESO_GENERICA:
            if alta:
                nuevo, fuente = alta, "día en que se registró en el sistema anterior"
            elif tramos and tramos[-1][0] > date(2020, 1, 31):
                nuevo, fuente = inicio_quincena(tramos[-1][0]), "inicio de su primera quincena trabajada"
            else:
                nuevo, fuente = None, None
            if nuevo:
                fechas_corregidas.append((e["idEmpleado"], "Fecha de inicio", ingreso, nuevo, fuente))
                p.append(f"Fecha de ingreso genérica 06/08/2019 → se corrigió a {fmt(nuevo)} ({fuente})")
                ingreso = nuevo
            else:
                p.append("Fecha de ingreso genérica 06/08/2019 y sin forma de saber la real "
                         "(ya trabajaba en ene/2020, antes del primer registro): **verificar con administración**")
        if e["idEmpleado"] in CORRECCIONES_INICIO:
            nuevo = fecha(CORRECCIONES_INICIO[e["idEmpleado"]])
            fechas_corregidas.append((e["idEmpleado"], "Fecha de inicio", ingreso, nuevo, "confirmada por RRHH"))
            p.append(f"Fecha de inicio {fmt(ingreso)} → {fmt(nuevo)} (confirmada por RRHH)")
            ingreso = nuevo
        # Fecha de cese que el sistema viejo ponía solo (el día del registro) o anterior al ingreso.
        if cese and ingreso and (cese < ingreso or (alta and cese == alta)):
            ultimo = tramos[-1][1] if tramos else None
            nuevo = ultimo if ultimo and ultimo >= ingreso else ingreso + timedelta(days=1)
            fuente = "fin de su última quincena trabajada" if nuevo == ultimo else "un día después del ingreso"
            fechas_corregidas.append((e["idEmpleado"], "Fecha de cese", cese, nuevo, fuente))
            p.append(f"Fecha de cese {fmt(cese)} imposible (anterior al ingreso o puesta sola al registrarlo) → "
                     f"se corrigió a {fmt(nuevo)} ({fuente})")
            cese = nuevo
        elif cese and tramos and (tramos[-1][1] - cese).days > 20:
            p.append(f"Trabajó hasta {fmt(tramos[-1][1])}, después de su fecha de cese ({fmt(cese)}): revisar")
        num_cuenta = txt(il["NumCuenta"]).upper()
        if num_cuenta and len(set(digitos(num_cuenta) or "0")) == 1 and num_cuenta != "0":
            p.append(f"Número de cuenta de relleno (`{num_cuenta}`) → se dejó vacío")
            num_cuenta = None
        num_cuenta = None if num_cuenta in ("", "0") else num_cuenta
        forma = "Transferencia" if il["FormadePago"] == "Cuenta de Banco" else "Cheque"
        id_banco = e["idBanco"] if forma == "Transferencia" else None
        if forma == "Transferencia" and not num_cuenta:
            p.append("Paga por transferencia pero no tiene número de cuenta")
        if forma == "Transferencia" and not id_banco:
            p.append("Paga por transferencia pero no tiene banco asignado")
        if num_cuenta and activo:
            cuentas[num_cuenta].append(e["idEmpleado"])
        if il["SalarioBase"] <= 0:
            p.append("Salario base en 0")
        ult = planilla.get(e["idEmpleado"])
        if activo and not ult:
            p.append("Figura Activo pero nunca aparece en una planilla")
        elif activo and (HOY - fecha(ult["ultima"])).days > 60:
            p.append(f"Figura Activo pero su última planilla fue el {fmt(fecha(ult['ultima']))}")
        if e["idDepartamento"] not in deptos:
            p.append("Departamento inexistente")

        filas_il.append({
            "id": il["idInfoLaboral"], "tipo_contrato": contrato, "fecha_inicio": ingreso.isoformat(),
            "fecha_cese": cese.isoformat() if cese else None,
            "motivo_cese": mayus(il["MotivoCese"])[:300] or None,
            "estado": "Activo" if activo else "Inactivo",
            "moneda": "Dólares" if "dolar" in il["Moneda"].lower() else "Lempiras",
            "forma_de_pago": forma, "num_cuenta": num_cuenta,
            "salario_base": round(il["SalarioBase"], 2), "salario_quincenal": round(il["Quincenal"], 2),
            "salario_diario": round(il["Diario"], 2), "salario_por_hora": round(il["Hora"], 2),
            # Quien gana exactamente el salario mínimo queda marcado para que suba solo cuando se actualice.
            "usa_salario_minimo": int(abs(il["SalarioBase"] - SALARIO_MINIMO) < 0.01),
            "sin_promedio_dias": 0, "id_banco": id_banco, "id_usuario": ID_USUARIO,
        })
        filas_emp.append({
            "id": e["idEmpleado"], "nombres": nombres[:30], "apellidos": apellidos[:30], "cedula": cedula,
            "rtn": rtn if rtn and len(rtn) == 14 else None, "genero": e["Genero"],
            "fecha_nacimiento": nac.isoformat(), "edad": edad(nac),
            "estado_civil": ESTADO_CIVIL.get(e["EstadoCivil"], e["EstadoCivil"]),
            "num_hijos": int(digitos(e["NHijos"]) or 0),
            "nacionalidad": ("HONDUREÑA" if e["Genero"] == "Femenino" else "HONDUREÑO")
                            if "hondu" in e["Nacionalidad"].lower() else mayus(e["Nacionalidad"]),
            "residencia": residencia[:60] or "NA", "telefono": txt(e["Telefonos"])[:20],
            "contacto_emergencia": mayus(e["ContactoEmergencia"])[:50],
            "telefono_emergencia": txt(e["TelefonoContacto"])[:30],
            "correo": correo, "tipo_sangre": e["TipoSangre"], "id_info_laboral": il["idInfoLaboral"],
            "id_cargo": mapa_cargo[e["idPuesto"]], "id_departamento": e["idDepartamento"],
            "id_usuario": ID_USUARIO,
        })
        filas_hist += historial_empleado(e["idEmpleado"], quincenas_de[e["idEmpleado"]], ingreso, contrato,
                                         activo, cese, il["MotivoCese"], LIQUIDACIONES.get(e["idEmpleado"]))
        problemas[e["idEmpleado"]] = p

    for cuenta, ids in cuentas.items():
        if len(ids) > 1:
            for i in ids:
                problemas[i].append(f"Número de cuenta `{cuenta}` compartido con otro empleado activo "
                                    f"(IDs anteriores {', '.join(map(str, ids))})")

    # --- IDs nuevos (1, 2, 3...) en el orden en que se crearon en el sistema anterior. Se respetan los de la
    # corrida anterior (mapa_ids_empleados.json) para que no se corran al fusionar o corregir registros:
    # si alguien deja de migrarse queda su hueco, y los nuevos van al final. La información laboral lleva el mismo número.
    previo = {}
    try:
        with open(RUTA_MAPA, encoding="utf-8") as fh:
            previo = {int(k): v for k, v in json.load(fh)["empleados"].items() if v}
    except (OSError, ValueError, KeyError):
        pass
    # Empleados creados en el sistema nuevo: sus IDs no se reutilizan y, si el .bak trae a la misma persona
    # (mismo DNI), se usa el registro del sistema nuevo en vez de crear otro.
    reservas = reservas or {}
    emp_sistema = {f["id"] for f in reservas.get("empleados", [])}
    il_sistema = {f["id"] for f in reservas.get("informacion_laboral", [])}
    por_cedula = {digitos(f["cedula"]): f["id"] for f in reservas.get("empleados", []) if digitos(f["cedula"])}
    nuevo_id, usados, ya_registrados = {}, set(emp_sistema), set()
    for f in filas_emp:
        if digitos(f["cedula"]) in por_cedula and not f["cedula"].startswith("0000-0000-"):
            nuevo_id[f["id"]] = por_cedula[digitos(f["cedula"])]
            ya_registrados.add(f["id"])
    for f in filas_emp:
        n = previo.get(f["id"])
        if f["id"] not in nuevo_id and n and n not in usados:
            nuevo_id[f["id"]] = n
            usados.add(n)
    siguiente = max(usados, default=0)
    for f in filas_emp:
        if f["id"] not in nuevo_id:
            siguiente += 1
            nuevo_id[f["id"]] = siguiente
    # La información laboral lleva el mismo número que el empleado, salvo que ese número ya lo use una
    # información laboral creada en el sistema nuevo.
    il_de = {f["id"]: f["id_info_laboral"] for f in filas_emp}
    nuevo_il, siguiente_il = {}, max(il_sistema | set(nuevo_id.values()), default=0)
    for v, n in nuevo_id.items():
        if v in ya_registrados:
            continue
        if n in il_sistema:
            siguiente_il += 1
            n = siguiente_il
        nuevo_il[il_de[v]] = n
    il_omitidos = {il_de[v] for v in ya_registrados}
    filas_emp = [f for f in filas_emp if f["id"] not in ya_registrados]
    filas_il = [f for f in filas_il if f["id"] not in il_omitidos]
    filas_hist = [h for h in filas_hist if h["id_empleado"] not in ya_registrados]
    for f in filas_emp:
        f["id"], f["id_info_laboral"] = nuevo_id[f["id"]], nuevo_il[f["id_info_laboral"]]
    for f in filas_il:
        f["id"] = nuevo_il[f["id"]]
    for f in filas_hist:
        f["id_empleado"] = nuevo_id[f["id_empleado"]]
    filas_hist.sort(key=lambda h: (h["id_empleado"], h["fecha"]))

    return {
        "nuevo_id": nuevo_id, "fechas_corregidas": fechas_corregidas, "quincenas_de": quincenas_de,
        "ya_registrados": ya_registrados,
        "empleados_viejos": {e["idEmpleado"]: e for e in empleados}, "info": info, "planilla": planilla,
        "deptos": deptos, "cargos": cargos, "nombre_cargo": nombre_cargo, "mapa_cargo": mapa_cargo,
        "bancos": bancos, "duplicados": duplicados, "descartados": descartados, "fusiones": fusiones,
        "filas_emp": filas_emp, "filas_il": filas_il, "filas_hist": filas_hist,
        "problemas": problemas, "temporales": temporales, "excluidos": excluidos,
    }


# "HORAS EXTRAS: 3", "HORAS EXTRAS", "HORA EXTRA: 2", "HE", "H.E. 4"... (no incluye horas/turnos nocturnos)
HORAS_EXTRAS = re.compile(r"^(HORAS?\s+EXTRAS?|H\.?\s?E\.?)\s*:?\s*(\d+(?:[.,]\d+)?)?\s*(HORAS?|HRS?)?$")


def horas_extras(descripcion):
    """None si no es hora extra; si lo es, la cantidad de horas escrita (o 0 si no la trae)."""
    m = HORAS_EXTRAS.match(sin_acentos(txt(descripcion).upper()))
    if not m:
        return None
    return float(m.group(2).replace(",", ".")) if m.group(2) else 0.0


def separar_horas_extras(r, registros):
    """El sistema viejo metía las horas extras dentro de "otros ingresos". Devuelve
    (horas, monto_horas, otros_ingresos, descripcion, ids_absorbidos, como)."""
    otros = round(r["OtrosIngresos"] or 0, 2)
    tarifa = (r["SalarioDiario"] or 0) / 8
    desc_original = txt(r["DescrpIngresos"])[:100] or None
    if otros <= 0:
        return 0, 0, otros, desc_original, [], None

    def horas_de(n, monto):
        # Sin cantidad, o con el monto escrito donde iban las horas ("HORAS EXTRAS: 485.64" por L 485.64).
        if not n or (n > 24 and abs(n - monto) < 0.01):
            return round(monto / tarifa, 2) if tarifa else 0
        return n

    # 1) Con los registros de otros ingresos de esa planilla y empleado, si suman lo mismo que la fila.
    if registros and abs(sum(m["Monto"] or 0 for m in registros) - otros) < 0.05:
        horas = monto = 0.0
        resto, absorbidos = [], []
        for m in registros:
            n = horas_extras(m["Descripcion"])
            if n is None:
                resto.append(txt(m["Descripcion"]))
            else:
                monto += m["Monto"] or 0
                horas += horas_de(n, m["Monto"] or 0)
                absorbidos.append(m["idOtrosIngresos"])
        if not absorbidos:
            return 0, 0, otros, desc_original, [], None
        monto = round(monto, 2)
        return (round(horas, 2), monto, round(otros - monto, 2),
                ", ".join(x for x in resto if x)[:100] or None, absorbidos, "registros")

    # 2) Sin registros: solo si la descripción de la fila es únicamente horas extras.
    partes = [p for p in (txt(x) for x in (r["DescrpIngresos"] or "").split(",")) if p]
    marcas = [horas_extras(p) for p in partes]
    if partes and all(n is not None for n in marcas):
        horas = sum(marcas) if all(marcas) else horas_de(0, otros)
        return round(horas, 2), otros, 0, None, [], "descripcion"
    if any(n is not None for n in marcas):
        return 0, 0, otros, desc_original, [], "mezclado"
    return 0, 0, otros, desc_original, [], None


def transformar_planillas(d, t):
    def destino(id_viejo):
        return t["nuevo_id"].get(t["descartados"].get(id_viejo, id_viejo))

    deptos = {r["Departamento"]: mayus(r["Departamento"]) for r in t["deptos"].values()}
    filas = sorted(d["Planilla"], key=lambda r: r["idPlanilla"])
    ingresos_de = collections.defaultdict(list)  # (planilla, id viejo) -> registros de otros ingresos
    for m in d["OtrosIngresos"]:
        if not m["Deleted"]:
            ingresos_de[(txt(m["NombrePlanilla"]).upper(), m["idEmpleado"])].append(m)
    absorbidos = set()  # otros ingresos que pasaron a ser horas extras de una fila
    he = collections.Counter()
    he_inconsistentes = []

    # --- cabeceras: una por (nombre, tipo), en orden cronológico
    fechas = collections.defaultdict(set)
    for r in filas:
        fechas[(txt(r["NombrePlanilla"])[:50], r["TipoPlanilla"])].add(r["FechaGeneradaPlanilla"])
    cabeceras, id_cab = [], {}
    for clave in sorted(fechas, key=lambda k: (max(fechas[k]), k[1], k[0])):
        id_cab[clave] = len(cabeceras) + 1
        f = max(fechas[clave])
        cabeceras.append({"id": id_cab[clave], "nombre_planilla": clave[0], "tipo_planilla": clave[1],
                          "estado": "Cerrado", "fecha_generada": f, "id_usuario": ID_USUARIO,
                          "created_at": f + " 00:00:00", "updated_at": f + " 00:00:00"})

    # --- detalle: se migran todas las filas, también las quincenas con 0 días (los extras que no trabajaron)
    detalles, huerfanas, ajustadas, repetidas = [], [], 0, []
    vistos = set()
    componentes = ("IHSS", "RetencionAhorro", "Crefisa", "Transporte", "Radios", "Uniforme", "Garden",
                   "OtrasDeducciones")
    for r in filas:
        clave = (txt(r["NombrePlanilla"])[:50], r["TipoPlanilla"])
        if r["idEmpleado"] in FUSIONES_MANUALES and not r["DiasTrabajados"]:
            continue  # quincenas en 0 del registro que sobraba: el conservado ya sale en esa planilla
        emp = destino(r["idEmpleado"])
        if emp is None:
            huerfanas.append(r)
            continue
        if (clave, emp) in vistos:
            repetidas.append((r, emp))
        vistos.add((clave, emp))
        ded = {c: round(r[c] or 0, 2) for c in componentes}
        # Con 0 días el sistema viejo mostraba IHSS etc. pero no los descontaba (deducción neta y neto en 0).
        if not r["DeduccionNeta"] and sum(ded.values()) > 0.05:
            ded = {c: 0 for c in componentes}
            ajustadas += 1
        horas, monto_he, otros, desc_ing, ids, como = separar_horas_extras(
            r, ingresos_de.get((txt(r["NombrePlanilla"]).upper(), r["idEmpleado"])))
        tarifa = (r["SalarioDiario"] or 0) / 8
        if monto_he and abs(horas * tarifa - monto_he) > 1:
            # Pagadas distinto a horas × (diario ÷ 8): recargo 25% (1.25x), nocturnas (2.333x) o a mano.
            # El sistema nuevo no tiene recargo, así que van completas como otros ingresos con su concepto.
            formula = round(horas * tarifa, 2)
            razon = monto_he / formula if formula else 0
            concepto = (f"HORAS EXTRAS {horas:g} H CON RECARGO 25%" if abs(razon - 1.25) < 0.01 else
                        f"HORAS EXTRAS NOCTURNAS {horas:g} H" if abs(razon - 7 / 3) < 0.01 else
                        f"HORAS EXTRAS {horas:g} H")
            he_inconsistentes.append((r, horas, monto_he, formula, razon))
            otros = round(otros + monto_he, 2)
            desc_ing = ", ".join(x for x in (concepto, desc_ing) if x)[:100]
            horas, monto_he, ids = 0, 0, []
        absorbidos.update(ids)
        if como:
            he[como] += 1
        if monto_he:
            he["filas"] += 1
            he["horas"] += horas
            he["monto"] += monto_he
        cuenta = txt(r["CtaBanco"])
        detalles.append({
            "id_cabecera_planilla": id_cab[clave], "id_empleado": emp, "nombre_planilla": clave[0],
            "departamento": deptos.get(r["Departamento"], mayus(r["Departamento"]))[:50],
            "tipo_planilla": r["TipoPlanilla"], "dias_trabajados": r["DiasTrabajados"] or 0,
            "salario_diario": round(r["SalarioDiario"] or 0, 2),
            # En el sistema viejo SalarioB era el sueldo mensual; en el nuevo es lo devengado en la quincena.
            "salario_base": round((r["SalarioDiario"] or 0) * (r["DiasTrabajados"] or 0), 2),
            "desc_ingresos": desc_ing,
            "otros_ingresos": otros, "horas_extras": horas, "monto_horas_extras": monto_he,
            "ihss": ded["IHSS"], "retencion_ahorro": ded["RetencionAhorro"], "crefisa": ded["Crefisa"], "isr": 0,
            "transporte": ded["Transporte"], "radios": ded["Radios"], "uniforme": ded["Uniforme"],
            "garden": ded["Garden"], "i_vecinal": 0,
            "desc_otras_deducciones": txt(r["OtrasDeduccionesDescrip"])[:100] or None,
            "otras_deducciones": ded["OtrasDeducciones"], "deduccion_neta": round(r["DeduccionNeta"] or 0, 2),
            "salario_neto": round(r["SalarioNeto"] or 0, 2),
            "cuenta_banco": None if cuenta in ("", "0") else cuenta[:50],
            "fecha_generada": r["FechaGeneradaPlanilla"], "id_usuario": ID_USUARIO,
            "created_at": r["FechaGeneradaPlanilla"] + " 00:00:00",
            "updated_at": r["FechaGeneradaPlanilla"] + " 00:00:00",
        })

    # --- otros ingresos / otras deducciones (los borrados en el sistema viejo no se migran)
    def movimientos(tabla):
        filas_m, sin_emp, borrados = [], [], 0
        for m in d[tabla]:
            if m["Deleted"]:
                borrados += 1
                continue
            if tabla == "OtrosIngresos" and m["idOtrosIngresos"] in absorbidos:
                continue  # ya está en las horas extras de su planilla
            emp = destino(m["idEmpleado"])
            if emp is None:
                sin_emp.append(m)
                continue
            filas_m.append({"descripcion": txt(m["Descripcion"])[:255] or "SIN DESCRIPCIÓN",
                            "monto": round(m["Monto"] or 0, 2), "nombre_planilla": txt(m["NombrePlanilla"])[:50],
                            "fecha": m["Fecha"], "id_empleado": emp, "deleted_at": None,
                            "created_at": m["Fecha"] + " 00:00:00", "updated_at": m["Fecha"] + " 00:00:00"})
        return filas_m, sin_emp, borrados

    ingresos, ing_sin_emp, ing_borrados = movimientos("OtrosIngresos")
    deducciones, ded_sin_emp, ded_borrados = movimientos("OtrasDeducciones")
    return {"cabeceras": cabeceras, "detalles": detalles, "huerfanas": huerfanas, "ajustadas": ajustadas,
            "repetidas": repetidas, "ingresos": ingresos, "ing_sin_emp": ing_sin_emp, "ing_borrados": ing_borrados,
            "deducciones": deducciones, "ded_sin_emp": ded_sin_emp, "ded_borrados": ded_borrados,
            "total_filas": len(filas), "he": he, "he_inconsistentes": he_inconsistentes,
            "he_absorbidos": len(absorbidos), "cero_dias": sum(1 for x in detalles if not x["dias_trabajados"])}


# ---------------------------------------------------------------- carga MySQL


def sql_valor(v):
    if v is None:
        return "NULL"
    if isinstance(v, (int, float)):
        return repr(v)
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"


def inserts(tabla, filas, extra):
    if not filas:
        return ""
    cols = list(filas[0].keys()) + list(extra.keys())
    out = []
    for i in range(0, len(filas), 200):
        valores = ",\n".join("(" + ",".join(sql_valor(v) for v in list(f.values()) + list(extra.values())) + ")"
                             for f in filas[i:i + 200])
        out.append(f"INSERT INTO {tabla} ({','.join(cols)}) VALUES\n{valores};")
    return "\n".join(out)


# Primera carga (--sin-preservar): se vacía todo lo que apunta a empleados.
TABLAS_A_VACIAR = (
    "detalle_planillas", "cabecera_planillas", "otros_ingresos", "otras_deducciones", "deducciones_cuotas",
    "aguinaldo_fijos", "aguinaldo_extras", "incidencias", "solicitudes_vacaciones", "marcaciones",
    "documentos_generados", "historial_laboral", "empleados", "informacion_laboral",
    "cargos", "departamentos", "bancos",
)


def asignar_ids(claves, previos, propuestos, ocupados):
    """IDs estables: el de la carga anterior si sigue libre; si no, el propuesto; si no, el siguiente libre.
    `ocupados` son los IDs de filas creadas en el sistema nuevo, que nunca se pisan."""
    res, usados = {}, set(ocupados)
    for k in claves:
        p = previos.get(k)
        if p is not None and p not in usados:
            res[k] = p
            usados.add(p)
    siguiente = 0
    for k in claves:
        if k in res:
            continue
        q = propuestos.get(k)
        if q is None or q in usados:
            siguiente = max(siguiente, 0) + 1
            while siguiente in usados:
                siguiente += 1
            q = siguiente
        res[k] = q
        usados.add(q)
    return res


def ejecutar(destino, sql, forzar=False):
    args = [MYSQL, "-uroot", "--default-character-set=utf8mb4", destino] + (["--force"] if forzar else [])
    r = subprocess.run(args, input=sql.encode("utf-8"), capture_output=True)
    if r.returncode != 0 and not forzar:
        sys.exit("Error cargando en MySQL:" + chr(10) + r.stderr.decode("utf-8", "replace"))
    return r.stderr.decode("utf-8", "replace")


def cargar(t, pl, destino, carpeta, prev=None, escribir=True):
    """prev: lo que devuelve preservar.detectar() (None = primera carga, se vacían las tablas).
    escribir=False solo guarda la foto de la carga (ultima_carga.json) sin tocar la base."""
    ahora = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    ts = {"created_at": ahora, "updated_at": ahora}
    nuevos = prev["nuevos"] if prev else {}
    claves_previas = prev["base"].get("claves", {}) if prev else {}
    ocupados = lambda tabla: {f["id"] for f in nuevos.get(tabla, [])}

    # --- catálogos con IDs estables
    dep_k = [str(i) for i in sorted(t["deptos"])]
    dep_id = asignar_ids(dep_k, claves_previas.get("departamentos", {}), {k: int(k) for k in dep_k},
                         ocupados("departamentos"))
    car_k = list(t["cargos"])
    car_id = asignar_ids(car_k, claves_previas.get("cargos", {}), dict(t["cargos"]), ocupados("cargos"))
    ban_k = [str(i) for i in t["bancos"]]
    ban_id = asignar_ids(ban_k, claves_previas.get("bancos", {}), {k: int(k) for k in ban_k}, ocupados("bancos"))
    deptos = [{"id": dep_id[str(i)], "nombre": mayus(r["Departamento"]), "estado": r["Estado"],
               "id_usuario": ID_USUARIO, **ts} for i, r in sorted(t["deptos"].items())]
    cargos = [{"id": car_id[n], "nombre": n, "estado": "Activo", "id_usuario": ID_USUARIO, **ts} for n in car_k]
    bancos = [{"id": ban_id[str(i)], "nombre": n, "estado": "Activo", "id_usuario": ID_USUARIO, **ts}
              for i, n in t["bancos"].items()]
    cargo_de_id = {i: car_id[n] for n, i in t["cargos"].items()}
    empleados = [{**f, "id_departamento": dep_id[str(f["id_departamento"])], "id_cargo": cargo_de_id[f["id_cargo"]],
                  "id_usuario": ID_USUARIO, **ts} for f in t["filas_emp"]]
    info = [{**f, "id_banco": ban_id[str(f["id_banco"])] if f["id_banco"] is not None else None, **ts}
            for f in t["filas_il"]]

    # --- planillas: cabeceras con ID estable por (nombre, tipo); el resto con IDs que no pisen los del sistema
    cab_k = [f"{c['nombre_planilla']}|{c['tipo_planilla']}" for c in pl["cabeceras"]]
    cab_id = asignar_ids(cab_k, claves_previas.get("cabecera_planillas", {}),
                         {k: c["id"] for k, c in zip(cab_k, pl["cabeceras"])}, ocupados("cabecera_planillas"))
    viejo_a_cab = {c["id"]: cab_id[k] for k, c in zip(cab_k, pl["cabeceras"])}
    cabeceras = [{**c, "id": viejo_a_cab[c["id"]]} for c in pl["cabeceras"]]

    def numerar(tabla, filas):
        ids = asignar_ids(list(range(len(filas))), {}, {}, ocupados(tabla))
        return [{"id": ids[i], **f} for i, f in enumerate(filas)]

    historial = numerar("historial_laboral", [{**h, "id_usuario": ID_USUARIO, **ts} for h in t["filas_hist"]])
    detalles = numerar("detalle_planillas", [{**d, "id_cabecera_planilla": viejo_a_cab[d["id_cabecera_planilla"]]}
                                             for d in pl["detalles"]])
    ingresos = numerar("otros_ingresos", pl["ingresos"])
    deducciones = numerar("otras_deducciones", pl["deducciones"])

    filas = {"departamentos": deptos, "cargos": cargos, "bancos": bancos, "informacion_laboral": info,
             "empleados": empleados, "historial_laboral": historial, "cabecera_planillas": cabeceras,
             "detalle_planillas": detalles, "otros_ingresos": ingresos, "otras_deducciones": deducciones}
    if prev:
        limpiar = preservar.sentencias_borrado(prev["base"])
    else:
        limpiar = [f"TRUNCATE TABLE {x};" for x in TABLAS_A_VACIAR]
    sql = chr(10).join(["SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;", *limpiar,
                     *(inserts(tb, filas[tb], {}) for tb in preservar.TABLAS),
                     "SET FOREIGN_KEY_CHECKS=1;"])
    with open(os.path.join(carpeta, "carga.sql"), "w", encoding="utf-8") as f:
        f.write(sql)
    if escribir:
        ejecutar(destino, sql)

    # --- lo hecho en el sistema nuevo, encima de la carga
    sin_destino, errores = [], ""
    if prev and escribir:
        reaplicar, sin_destino = preservar.sentencias_reaplicar(prev["cambios"], filas)
        if reaplicar:
            errores = ejecutar(destino, "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;" + chr(10) + chr(10).join(reaplicar)
                               + chr(10) + "SET FOREIGN_KEY_CHECKS=1;", forzar=True)
    sin_ts = {tb: [{c: v for c, v in f.items() if c not in preservar.IGNORAR} for f in fs] for tb, fs in filas.items()}
    preservar.guardar_base(sin_ts, {"departamentos": dep_id, "cargos": car_id, "bancos": ban_id,
                                    "cabecera_planillas": cab_id})
    return sin_destino, errores


# ---------------------------------------------------------------- reportes


def linea_emp(e, t, extra=""):
    il = t["info"][e["idInfoLaboral"]]
    ult = t["planilla"].get(e["idEmpleado"])
    depto = mayus(t["deptos"].get(e["idDepartamento"], {}).get("Departamento", "?"))
    nuevo = t["nuevo_id"].get(e["idEmpleado"])
    ident = f"ID {nuevo} (antes {e['idEmpleado']})" if nuevo else f"ID anterior {e['idEmpleado']}"
    return (f"{ident} · {txt(e['NombresEmpleado'])} {txt(e['ApellidosEmpleado'])} · "
            f"{depto} · {il['Contrato']} · {il['Estado']} · ingreso {fmt(fecha(il['FechaInicio']))} · "
            f"nac. {fmt(fecha(e['FechaNacimiento']))} · "
            f"{(str(ult['n']) + ' planillas, última ' + fmt(fecha(ult['ultima']))) if ult else 'sin planillas'}{extra}")


def seccion_planillas(t, pl):
    fechas = [c["fecha_generada"] for c in pl["cabeceras"]]
    he_filas = pl["he"]["filas"]
    o = ["## 4. Planillas de pago migradas", "",
         "| | Cantidad |", "|---|---|",
         f"| Planillas (cabeceras, todas cerradas) | {len(pl['cabeceras'])} — "
         f"Fijos {sum(c['tipo_planilla'] == 'Fijos' for c in pl['cabeceras'])}, "
         f"Extras {sum(c['tipo_planilla'] == 'Extras' for c in pl['cabeceras'])} |",
         f"| Período | {fmt(fecha(min(fechas)))} a {fmt(fecha(max(fechas)))} |",
         f"| Filas de empleados en el sistema anterior | {pl['total_filas']} |",
         f"| Filas migradas | {len(pl['detalles'])} (incluye {pl['cero_dias']} quincenas con 0 días) |",
         f"| Otros ingresos migrados | {len(pl['ingresos'])} ({pl['ing_borrados']} borrados en el sistema anterior no se migraron) |",
         f"| Otras deducciones migradas | {len(pl['deducciones'])} ({pl['ded_borrados']} borrados en el sistema anterior no se migraron) |",
         "",
         "**Cómo se pasaron los montos:**", "",
         "- Se respetaron los montos pagados del sistema anterior: deducción neta y salario neto son exactamente los mismos.",
         "- `Salario base` en el sistema nuevo es lo devengado en la quincena (días × salario diario); en el anterior "
         "era el sueldo mensual.",
         "- Las quincenas con **0 días** se migraron todas para que quede el registro (normalmente extras que no "
         f"trabajaron esa quincena). En {pl['ajustadas']} de ellas el sistema anterior mostraba IHSS u otras "
         "deducciones que en realidad no se descontaron (neto 0): esas deducciones se dejaron en 0.",
         f"- **Horas extras:** el sistema anterior las registraba como \"otros ingresos\" (`HORAS EXTRAS: 3`, "
         f"`HORAS EXTRAS`, `HE`...). Se pasaron a las columnas de horas extras en {he_filas} filas "
         f"({pl['he']['horas']:,.2f} horas, L {pl['he']['monto']:,.2f}); el resto (retroactivos, bonos, comisiones, "
         "turnos, eventos...) quedó como otros ingresos con su concepto. Cuando no traía la cantidad de horas, se "
         "calculó con el monto pagado ÷ (salario diario ÷ 8). Horas y turnos nocturnos quedaron como otros ingresos.",
         f"- {pl['he_absorbidos']} registros de otros ingresos que eran horas extras ya no se repiten en la tabla de "
         "otros ingresos: viven en la planilla como horas extras.",
         "- ISR e impuesto vecinal no existían como columna en el sistema anterior: quedan en 0.",
         "- Las filas de los duplicados descartados se asignaron al registro que se conservó.",
         ""]
    if pl["repetidas"]:
        o += ["**Empleados que quedaron dos veces en la misma planilla** (al fusionar duplicados):", ""]
        for r, emp in pl["repetidas"]:
            o.append(f"- {txt(r['NombrePlanilla'])} — ID nuevo {emp} {txt(r['NombreEmpleado'])} {txt(r['ApellidoEmpleado'])} "
                     f"(registro viejo {r['idEmpleado']}), neto L {r['SalarioNeto']:,.2f}")
        o.append("")
    if pl["he"]["mezclado"]:
        o += [f"**{pl['he']['mezclado']} filas** tienen horas extras mezcladas con otro concepto en la misma "
              "descripción (ej. `HORAS EXTRAS,RETROACTIVO`) y sin el detalle para separarlas: quedaron completas "
              "como otros ingresos.", ""]
    if pl["he_inconsistentes"]:
        o += [f"**Horas extras pagadas distinto a la fórmula del sistema nuevo** (horas × salario diario ÷ 8): "
              f"{len(pl['he_inconsistentes'])} filas. Se pasaron completas a **otros ingresos** con su concepto, "
              "respetando el monto pagado. 1.25x = recargo del 25%; 2.33x = probable hora extra nocturna "
              "(diario ÷ 6 × 1.75); el resto parece escrito a mano y conviene revisarlo.", "",
              "| Planilla | Empleado | Horas | Pagado | Según fórmula | Veces |", "|---|---|---|---|---|---|"]
        for r, h, pagado, formula, razon in sorted(pl["he_inconsistentes"], key=lambda x: (round(x[4], 2), x[0]["idPlanilla"])):
            o.append(f"| {txt(r['NombrePlanilla'])} | {txt(r['NombreEmpleado'])} {txt(r['ApellidoEmpleado'])} | "
                     f"{h:g} | L {pagado:,.2f} | L {formula:,.2f} | {razon:.2f}x |")
        o.append("")
    o += ["### Filas que NO se migraron (su empleado no existe)", ""]
    if pl["huerfanas"]:
        o.append("| Planilla | Empleado (según la planilla) | ID viejo | Días | Neto |")
        o.append("|---|---|---|---|---|")
        for r in pl["huerfanas"]:
            o.append(f"| {txt(r['NombrePlanilla'])} | {txt(r['NombreEmpleado'])} {txt(r['ApellidoEmpleado'])} | "
                     f"{r['idEmpleado']} | {r['DiasTrabajados']} | L {r['SalarioNeto']:,.2f} |")
    else:
        o.append("_Ninguna._")
    sin_emp = pl["ing_sin_emp"] + pl["ded_sin_emp"]
    if sin_emp:
        o.append("")
        o.append(f"Además, {len(pl['ing_sin_emp'])} otros ingresos y {len(pl['ded_sin_emp'])} otras deducciones "
                 "de esos mismos empleados inexistentes.")
    o += ["", "### Lo que se dejó fuera a propósito", "",
          "- **Planilla Especial** (9 quincenas, ene-may 2020): no existe en el sistema nuevo. Quedó archivada en "
          "`Planilla_Especial_2020_Archivo.xlsx`. Sus empleados tampoco se migraron:", ""]
    for e in t["excluidos"]:
        il = t["info"][e["idInfoLaboral"]]
        o.append(f"  - ID {e['idEmpleado']} · {mayus(e['NombresEmpleado'])} {mayus(e['ApellidosEmpleado'])} · "
                 f"DNI `{txt(e['Cedula'])}` · {il['Estado']}")
    o += ["",
          "- **Aguinaldos y catorceavos** del sistema anterior: eran dos lotes abiertos (dic/2022 y jun/2024) con "
          "todos los montos en 0; no hay nada que migrar.",
          "- **Días trabajados** (tabla aparte del sistema anterior): repetía los días que ya vienen en cada planilla.",
          ""]
    return o


def reporte_depuracion(t, ruta, pl):
    V, P = t["empleados_viejos"], t["problemas"]
    viejo = {n: v for v, n in t["nuevo_id"].items()}
    total = len(V)
    o = [f"# Depuración de empleados — migración del sistema anterior",
         "",
         f"> Generado el {fmt(HOY)} desde el respaldo `HPR_RRHH_09102026.bak` (respaldo #10, del 09/10/2026).",
         f"> Script: `rrhh-backend/database/migracion_legacy/migrar_empleados.py`",
         "",
         "## Resumen",
         "",
         "| | Cantidad |",
         "|---|---|",
         f"| Empleados en el sistema anterior | {total + len(t['excluidos'])} |",
         f"| Excluidos por ser de la Planilla Especial 2020 | {len(t['excluidos'])} — ver sección 4 |",
         f"| Grupos de DNI duplicado | {len(t['duplicados'])} |",
         f"| Registros duplicados descartados (se conservó el más reciente) | {len(t['descartados'])} |",
         f"| Empleados importados | {len(t['filas_emp'])} |",
         f"| Importados con DNI temporal (DNI de relleno) | {len(t['temporales'])} — ver `Empleados_DNI_Temporal.md` |",
         f"| Importados con al menos un dato erróneo o incompleto | {sum(1 for p in P.values() if p)} |",
         "",
         "**Criterio de duplicado:** mismo DNI (comparando solo los dígitos). El registro que se conserva es el "
         "más reciente (el `idEmpleado` más alto, que en todos los casos es también el que tiene la planilla más reciente). "
         "Los DNIs de relleno (`0`, `111`, `1111`, `11111`...) **no** se consideran duplicados: son personas "
         "distintas y se importaron todas.",
         "",
         "## 1. Duplicados por DNI",
         ""]
    n = 0
    for dni, g in sorted(t["duplicados"].items(), key=lambda kv: txt(max(kv[1], key=lambda e: e["idEmpleado"])["ApellidosEmpleado"])):
        n += 1
        g = sorted(g, key=lambda e: -e["idEmpleado"])
        nombres = {sin_acentos(mayus(e["NombresEmpleado"] + " " + e["ApellidosEmpleado"])) for e in g}
        nacs = {e["FechaNacimiento"] for e in g}
        avisos = []
        if len(nombres) > 1:
            avisos.append("el nombre cambia entre registros")
        if len(nacs) > 1:
            avisos.append("la fecha de nacimiento cambia entre registros")
        a = set(sin_acentos(mayus(g[0]["ApellidosEmpleado"])).split())
        if any(not a & set(sin_acentos(mayus(e["ApellidosEmpleado"])).split()) for e in g[1:]):
            avisos.append("**los apellidos son totalmente distintos: confirmar que el DNI no esté mal digitado**")
        o.append(f"### 1.{n}. DNI `{dni}` — {len(g)} registros" + (f" ⚠️ {'; '.join(avisos)}" if avisos else ""))
        o.append("")
        o.append(f"- ✅ **Se conserva:** {linea_emp(g[0], t)}")
        for e in g[1:]:
            o.append(f"- ❌ Descartado: {linea_emp(e, t)}")
        o.append("")

    if t["fusiones"]:
        o += ["### Fusionados a mano (misma persona con DNI distinto, confirmado por RRHH)", ""]
        for sobra, conservado in t["fusiones"]:
            o.append(f"- ✅ **Se conserva:** DNI `{txt(conservado['Cedula'])}` · {linea_emp(conservado, t)}")
            o.append(f"- ❌ Fusionado: DNI `{txt(sobra['Cedula'])}` · {linea_emp(sobra, t)} — sus quincenas "
                     "trabajadas pasan al registro conservado")
        o.append("")

    o += ["## 2. Posibles duplicados que NO se fusionaron (revisar a mano)", "",
          "Mismo nombre completo pero DNI distinto o de relleno. Como la regla es guiarse por el DNI, se importaron "
          "como personas distintas; revisar si son la misma persona.", ""]
    por_nombre = collections.defaultdict(list)
    for f in t["filas_emp"]:
        por_nombre[sin_acentos(f["nombres"] + " " + f["apellidos"])].append(V[viejo[f["id"]]])
    n = 0
    for nombre, g in sorted(por_nombre.items()):
        if len(g) > 1:
            n += 1
            o.append(f"{n}. **{nombre}**")
            for e in g:
                o.append(f"   - DNI `{txt(e['Cedula'])}` · {linea_emp(e, t)}")
    if not n:
        o.append("_Ninguno._")
    o.append("")

    o += ["## 3. Empleados con información errónea o incompleta", "",
          "Solo los empleados importados (los duplicados descartados no se listan). Primero los activos, después "
          "los inactivos, por apellido.", "",
          "**Correcciones que se aplicaron a todos automáticamente** (no se listan por empleado):", "",
          "- Nombres, apellidos, residencia y contacto de emergencia en MAYÚSCULAS, sin espacios dobles ni saltos de línea.",
          "- La edad se recalculó con la fecha de nacimiento (en el sistema viejo estaba congelada).",
          "- Los empleados **activos** tenían una fecha de cese de relleno (ej. 08/04/2020): se dejó vacía.",
          "- Estado civil `Soltero(a)` → `Soltero/a`, `Union Libre` → `Unión Libre`, etc.",
          "- Forma de pago `Cuenta de Banco` → `Transferencia`; `Cheques` → `Cheque` (y su cuenta `0` se dejó vacía).",
          "- Cargos repetidos (`Eventos`, `Ayudante de Cocina`) se unificaron.",
          "- El parentesco del contacto de emergencia no existía en el sistema viejo: queda vacío para todos.",
          "- Las fotos no se migraron: 388 de 389 eran la imagen genérica por defecto.",
          ""]
    orden = sorted((f for f in t["filas_emp"] if P[viejo[f["id"]]]),
                   key=lambda f: (t["info"][V[viejo[f["id"]]]["idInfoLaboral"]]["Estado"] != "Activo", f["apellidos"], f["nombres"]))
    estado_actual = None
    for i, f in enumerate(orden, 1):
        e = V[viejo[f["id"]]]
        est = t["info"][V[viejo[f["id"]]]["idInfoLaboral"]]["Estado"]
        if est != estado_actual:
            estado_actual = est
            o += [f"### {'Activos' if est == 'Activo' else 'Inactivos'}", ""]
        o.append(f"{i}. **{f['apellidos']}, {f['nombres']}** — DNI `{f['cedula']}` — {linea_emp(e, t)}")
        for prob in P[viejo[f["id"]]]:
            o.append(f"   - {prob}")
    o.append("")
    o += seccion_planillas(t, pl)
    o += ["## 5. Fechas corregidas automáticamente", "",
          "- **Fecha de inicio 06/08/2019:** era el valor que el formulario del sistema anterior ponía por defecto "
          "(aparece igual en todos los respaldos desde 2022). Se cambió por el día en que se registró al empleado "
          "en el sistema anterior (según su log) o, si no había registro, por el inicio de su primera quincena trabajada.",
          "- **Fecha de cese imposible:** el sistema anterior ponía sola la fecha de cese el día en que se registraba "
          "al empleado. Si el cese quedó antes del ingreso o en ese mismo día, se cambió por el fin de su última "
          "quincena trabajada.", "",
          "| ID (anterior) | Empleado | Estado | Dato | Antes | Ahora | De dónde sale |", "|---|---|---|---|---|---|---|"]
    for id_v, campo, antes, despues, fuente in t["fechas_corregidas"]:
        e = V[id_v]
        o.append(f"| {t['nuevo_id'][id_v]} ({id_v}) | {mayus(e['NombresEmpleado'])} {mayus(e['ApellidosEmpleado'])} | "
                 f"{t['info'][e['idInfoLaboral']]['Estado']} | {campo} | {fmt(antes)} | **{fmt(despues)}** | {fuente} |")
    n_ev = collections.Counter(h["tipo_evento"] for h in t["filas_hist"])
    o += ["", "## 6. Historial laboral (Movimientos de Personal)", "",
          "Se armó con las planillas del sistema anterior, sin cambiar la fecha de inicio ni el contrato de nadie: "
          f"si el empleado dejó de salir en planillas más de {GAP_SEPARACION} días y después volvió, se registró un "
          "cese y un reintegro; si pasó de planillas de Extras a Fijos, un cambio de contrato. Los movimientos "
          "deducidos dicen *\"Deducido de las planillas del sistema anterior\"* en observaciones y su motivo de "
          "cese es *Sin especificar*.", "",
          "| Movimiento | Cantidad |", "|---|---|"]
    o += [f"| {k} | {v} |" for k, v in n_ev.most_common()]
    o.append("")
    with open(ruta, "w", encoding="utf-8") as fh:
        fh.write("\n".join(o))


def excel_planilla_especial(filas, ruta):
    from openpyxl import Workbook
    from openpyxl.styles import Font

    wb = Workbook()
    ws = wb.active
    ws.title = "Planilla Especial 2020"
    cols = ["NombrePlanillaE", "FechaGenerada", "Departamento", "NombresEmpleado", "ApellidosEmpleado",
            "DiasTrabajados", "SalarioDiario", "SalarioBruto", "IHSS", "ISR", "OtrasDeducciones",
            "DeduccionesNetas", "OtrosIngresos", "SalarioNeto", "CuentaBanco", "Estado", "idEmpleado"]
    ws.append(["Planilla", "Fecha", "Departamento", "Nombres", "Apellidos", "Días", "Salario diario",
               "Salario mensual", "IHSS", "ISR", "Otras deducciones", "Deducción neta", "Otros ingresos",
               "Salario neto", "Cuenta", "Estado", "ID empleado (sistema anterior)"])
    for c in ws[1]:
        c.font = Font(bold=True)
    for r in sorted(filas, key=lambda r: r["idPlanillaEspecial"]):
        ws.append([round(r[c], 2) if isinstance(r[c], float) else (txt(r[c]) if isinstance(r[c], str) else r[c])
                   for c in cols])
    for col in ws.columns:
        ws.column_dimensions[col[0].column_letter].width = max(len(str(c.value or "")) for c in col) + 2
    ws.freeze_panes = "A2"
    wb.save(ruta)


def reporte_extra_a_fijo(t, ruta):
    filas = []
    for id_v, nuevo in t["nuevo_id"].items():
        e = t["empleados_viejos"][id_v]
        il = t["info"][e["idInfoLaboral"]]
        quincenas = [q for tramo in tramos_trabajados(t["quincenas_de"][id_v]) for q in tramo[2]]
        if not quincenas:
            continue
        a_fijo = [c for c in cambios_de_tipo(quincenas) if c[2] == "Fijo"]
        if not a_fijo:
            continue
        primera_extra = next((f for f, tp in quincenas if tp == "Extras"), None)
        inicio = next(x["fecha_inicio"] for x in t["filas_il"] if x["id"] == nuevo)
        filas.append((mayus(t["deptos"][e["idDepartamento"]]["Departamento"]), mayus(e["NombresEmpleado"]),
                      mayus(e["ApellidosEmpleado"]), nuevo, id_v, il["Estado"], il["Contrato"],
                      primera_extra, a_fijo[-1][0], fecha(inicio)))
    filas.sort()
    o = ["# Empleados que pasaron de Extra a Fijo", "",
         f"> Generado el {fmt(HOY)} a partir de las planillas del sistema anterior: empleados que salían en planillas "
         "de **Extras** y después pasaron a planillas de **Fijos** (al menos dos quincenas seguidas).", "",
         "Para consultar con administración: **¿la antigüedad cuenta desde que entró como extra o desde que pasó a fijo?** "
         "Hoy el sistema calcula vacaciones, aguinaldo y antigüedad con la *fecha de inicio actual*.", "",
         "| # | ID (anterior) | Empleado | Departamento | Estado | Contrato hoy | Extra desde | Fijo desde | Fecha de inicio actual |",
         "|---|---|---|---|---|---|---|---|---|"]
    for i, (dep, nom, ape, nuevo, id_v, est, contrato, extra, fijo, inicio) in enumerate(filas, 1):
        o.append(f"| {i} | {nuevo} ({id_v}) | {nom} {ape} | {dep} | {est} | {contrato} | "
                 f"{fmt(inicio_quincena(extra)) if extra else '—'} | {fmt(inicio_quincena(fijo))} | {fmt(inicio)} |")
    o += ["", "*Extra desde / Fijo desde*: inicio de la primera quincena en que aparece en cada tipo de planilla "
          "(las planillas empiezan en enero 2020, así que \"Extra desde 01/01/2020\" puede ser antes).", ""]
    with open(ruta, "w", encoding="utf-8") as fh:
        fh.write("\n".join(o))
    return len(filas)


def reporte_conservado(prev, sin_destino, errores, t, ruta):
    nombres = {"departamentos": "Departamentos", "cargos": "Cargos", "bancos": "Bancos",
               "informacion_laboral": "Información laboral", "empleados": "Empleados",
               "historial_laboral": "Historial laboral", "cabecera_planillas": "Planillas",
               "detalle_planillas": "Detalle de planillas", "otros_ingresos": "Otros ingresos",
               "otras_deducciones": "Otras deducciones"}
    o = ["# Cambios del sistema nuevo conservados en la migración", "",
         f"> Generado el {fmt(HOY)}. Lo que se hizo en el sistema nuevo desde la carga anterior se mantuvo y se "
         "volvió a aplicar encima del .bak. Si un dato cambió en los dos lados, quedó el del sistema nuevo. "
         "Todo queda guardado en `cambios_sistema_nuevo.json` para las próximas cargas.", "",
         "| Tabla | Creados en el sistema nuevo | Ediciones aplicadas (acumuladas) | Borrados aplicados (acumulados) |",
         "|---|---|---|---|"]
    for tabla in preservar.TABLAS:
        c = prev["cambios"].get(tabla, {})
        o.append(f"| {nombres[tabla]} | {len(prev['nuevos'][tabla])} | {len(c.get('modificados', {}))} | "
                 f"{len(c.get('borrados', []))} |")
    if t["ya_registrados"]:
        o += ["", f"**{len(t['ya_registrados'])} empleado(s) del .bak ya estaban registrados a mano en el sistema "
              "nuevo (mismo DNI):** se usó ese registro y sus planillas se le asignaron."]
    if sin_destino:
        o += ["", "## Ediciones que no se pudieron aplicar", "",
              "La fila ya no viene en el .bak nuevo (se fusionó, se excluyó o cambió de clave). Revisar a mano:", ""]
        o += [f"- `{tb}` {k}: {json.dumps(dif, ensure_ascii=False)}" for tb, k, dif in sin_destino]
    if errores.strip():
        o += ["", "## Errores al aplicar", "", "```", errores.strip()[:4000], "```"]
    o.append("")
    with open(ruta, "w", encoding="utf-8") as fh:
        fh.write(chr(10).join(o))


def reporte_temporales(t, ruta):
    o = ["# Empleados registrados con DNI temporal", "",
         f"> Generado el {fmt(HOY)}. En el sistema anterior tenían un DNI de relleno (`0`, `111`, `1111`...) o un DNI "
         "mal formado. Se registraron con un DNI temporal único, en este orden; búsquelos por ese número en Empleados "
         "y reemplácelo por el DNI real.", "",
         "| # | DNI temporal | DNI en sistema anterior | ID (anterior) | Nombre | Departamento | Contrato | Estado | Ingreso |",
         "|---|---|---|---|---|---|---|---|---|"]
    for i, (ced, e, il) in enumerate(t["temporales"], 1):
        depto = mayus(t["deptos"].get(e["idDepartamento"], {}).get("Departamento", "?"))
        o.append(f"| {i} | `{ced}` | `{txt(e['Cedula']) or 'vacío'}` | {t['nuevo_id'][e['idEmpleado']]} ({e['idEmpleado']}) | "
                 f"{mayus(e['NombresEmpleado'])} {mayus(e['ApellidosEmpleado'])} | {depto} | {il['Contrato']} | "
                 f"{il['Estado']} | {fmt(fecha(il['FechaInicio']))} |")
    o.append("")
    with open(ruta, "w", encoding="utf-8") as fh:
        fh.write("\n".join(o))


# ---------------------------------------------------------------- main


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--bak", help="ruta del .bak (por defecto, el .bak más reciente de la carpeta recursos)")
    ap.add_argument("--restaurar", action="store_true", help="restaurar el .bak antes de exportar")
    ap.add_argument("--destino", default="rrhh_hpr", help="base MySQL destino")
    ap.add_argument("--reportes", default=preservar.RECURSOS, help="carpeta donde dejar los .md (recursos)")
    ap.add_argument("--sin-preservar", action="store_true",
                    help="primera carga: vacía todo y no conserva lo hecho en el sistema nuevo")
    ap.add_argument("--solo-crear-base", action="store_true",
                    help="no toca la base: solo guarda ultima_carga.json con lo que cargaría")
    a = ap.parse_args()

    carpeta = os.path.join(AQUI, "exportado")
    os.makedirs(carpeta, exist_ok=True)
    pipe = pipe_localdb()
    if a.restaurar:
        if not a.bak:
            baks = sorted(glob.glob(os.path.join(preservar.RECURSOS, "*.bak")), key=os.path.getmtime)
            if not baks:
                sys.exit(f"No hay ningún .bak en {preservar.RECURSOS}")
            a.bak = baks[-1]
        print(f"Restaurando respaldo {os.path.basename(a.bak)}...")
        restaurar(pipe, a.bak)
    print("Exportando del sistema anterior...")
    datos = exportar(pipe, carpeta)
    prev = None
    if not a.sin_preservar and not a.solo_crear_base:
        print("Buscando lo que se cambió en el sistema nuevo desde la última carga...")
        prev = preservar.detectar(a.destino)
        for tabla, (n, m, b) in prev["resumen"].items():
            if n or m or b:
                print(f"  {tabla}: {n} creados en el sistema (se conservan), {m} editados, {b} borrados")
    print("Depurando...")
    t = transformar(datos, prev["nuevos"] if prev else None)
    pl = transformar_planillas(datos, t)
    print(f"Cargando en MySQL ({a.destino})..." if not a.solo_crear_base else "Guardando ultima_carga.json...")
    sin_destino, errores = cargar(t, pl, a.destino, carpeta, prev, escribir=not a.solo_crear_base)
    if prev:
        reporte_conservado(prev, sin_destino, errores, t, os.path.join(a.reportes, "Cambios_Conservados.md"))
    with open(RUTA_MAPA, "w", encoding="utf-8") as f:
        mapa = {e: t["nuevo_id"].get(t["descartados"].get(e, e)) for e in t["empleados_viejos"]}
        mapa.update({e["idEmpleado"]: None for e in t["excluidos"]})
        json.dump({"_nota": "idEmpleado del sistema anterior -> id nuevo (los duplicados apuntan al registro "
                             "conservado; null = no se migró)",
                   "empleados": mapa, "cargos": t["mapa_cargo"]}, f, indent=1)
    reporte_depuracion(t, os.path.join(a.reportes, "Depuracion_Empleados_Migracion.md"), pl)
    reporte_temporales(t, os.path.join(a.reportes, "Empleados_DNI_Temporal.md"))
    n_fijos = reporte_extra_a_fijo(t, os.path.join(a.reportes, "Empleados_Extra_a_Fijo.md"))
    excel_planilla_especial(datos["PlanillaEspecial"], os.path.join(a.reportes, "Planilla_Especial_2020_Archivo.xlsx"))
    print(f"Listo: {len(t['filas_emp'])} empleados importados, {len(t['descartados'])} duplicados descartados, "
          f"{len(t['temporales'])} con DNI temporal, {len(t['excluidos'])} excluidos (planilla especial).")
    print(f"       {len(t['fechas_corregidas'])} fechas corregidas, {len(t['filas_hist'])} movimientos de historial, "
          f"{n_fijos} pasaron de Extra a Fijo.")
    print(f"       {len(pl['cabeceras'])} planillas, {len(pl['detalles'])} filas de detalle, "
          f"{len(pl['ingresos'])} otros ingresos, {len(pl['deducciones'])} otras deducciones; "
          f"{len(pl['huerfanas'])} filas sin empleado no migradas.")


if __name__ == "__main__":
    main()
