# Migración del sistema anterior (HPR_RRHH, SQL Server)

## Cuando llegue un .bak nuevo

1. Copiar el `.bak` nuevo a `rrhh_frontend/src/assets/recursos/`.
2. Correr (usa el `.bak` más reciente de esa carpeta y deja ahí los reportes):

   ```
   python -I C:\Users\victo\Desktop\rrhh-backend\database\migracion_legacy\migrar_empleados.py --restaurar
   ```

3. Revisar `Cambios_Conservados.md` (en `recursos`): dice qué se conservó del sistema nuevo y si alguna
   edición no se pudo aplicar.

## Qué pasa con lo que ya se hizo en el sistema nuevo

**No se pierde.** Antes de cargar, el script compara la base con lo que cargó la vez anterior
(`ultima_carga.json`) y:

- Lo **creado** en el sistema nuevo (empleados, planillas, movimientos, cargos, incidencias, vacaciones,
  aguinaldos, marcaciones...) no se toca.
- Lo **editado** o **borrado** de lo que vino de la migración (ceses, DNIs corregidos, cargos cambiados...)
  se guarda en `cambios_sistema_nuevo.json` y se vuelve a aplicar encima del .bak nuevo. Si un dato cambió
  en los dos sistemas, gana el del sistema nuevo.
- Si el .bak trae a alguien que ya se registró a mano en el sistema nuevo (mismo DNI), se usa ese registro y
  no se crea otro.
- Los IDs de empleados se mantienen entre cargas (`mapa_ids_empleados.json`); los nuevos van al final.

## Archivos que NO se deben borrar (en `rrhh_frontend/src/assets/recursos/`)

| Archivo | Para qué |
|---|---|
| `ultima_carga.json` | Foto de la última carga: sin ella no se puede saber qué se cambió en el sistema nuevo (el script se detiene). |
| `cambios_sistema_nuevo.json` | Ediciones y borrados acumulados del sistema nuevo. |
| `mapa_ids_empleados.json` | ID del sistema anterior → ID nuevo, para que los IDs no se corran. |

La carpeta `recursos` está en el `.gitignore` del frontend (tiene datos personales), así que no van al
repositorio: conviene respaldarla junto con la base de datos.

## Correcciones confirmadas por RRHH

Están en `recursos/correcciones_rrhh.json` (DNIs, fusiones de la misma persona, fechas de inicio y de
nacimiento, liquidaciones; las claves son el idEmpleado del sistema anterior) y se aplican en cada carga.
No están en el código porque tienen datos personales y el repositorio es público. Lo que se corrija desde el
sistema nuevo ya no hace falta agregarlo ahí: se conserva solo.

## Primera carga desde cero

`--sin-preservar` vacía todo lo relacionado con empleados y carga el .bak limpio (borra lo hecho en el
sistema nuevo). Solo para empezar de cero.
