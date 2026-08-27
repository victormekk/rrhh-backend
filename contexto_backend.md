# Contexto de Cambios — RRHH Backend (Laravel)

> Proyecto: Sistema de Recursos Humanos — Hotel Palma Real y Villas
> Fecha de última actualización: 2026-08-26

---

## Stack tecnológico

| Componente | Versión / detalle |
|---|---|
| PHP | 8.1.10 (Laragon, Windows) |
| Laravel | ^10.10 (framework 10.50.2) |
| Auth | Laravel Sanctum ^3.3 (tokens Bearer, `guard: web` + fallback token) |
| Base de datos | MySQL |
| PDF | `barryvdh/laravel-dompdf` ^3.1 |
| Excel | `maatwebsite/excel` ^3.1 |
| Servidor local | Apache (Laragon), vhost `rrhh-backend.test` |

---

## Infraestructura / entorno local

### OPcache (agregado 2026-08-26)
El `php.ini` de Laragon (`C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.ini`) traía OPcache **completamente deshabilitado** (`;zend_extension=opcache` comentado). Esto obligaba a PHP a recompilar Laravel + vendor completo en cada request, lo cual explicaba la lentitud percibida navegando entre pantallas (aun con pocos registros en la base). Se activó:

```ini
zend_extension=opcache
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```

`validate_timestamps=1` + `revalidate_freq=0` mantiene detección instantánea de cambios de código (apto para desarrollo). **Impacto medido:** primera request tras reiniciar Apache ~22s (compila y llena la caché), requests siguientes ~100-150ms.

> Si el proyecto pasa a producción: cambiar a `opcache.validate_timestamps=0` y ejecutar `opcache_reset()` (o reiniciar PHP-FPM/Apache) en cada deploy para el máximo rendimiento.

Este cambio vive en el `php.ini` del entorno (Laragon), **no en el repositorio** — hay que replicarlo manualmente en cualquier otra máquina/entorno de desarrollo.

### Migraciones de rendimiento
`database/migrations/2026_05_29_000001_add_performance_indexes.php` agrega índices a:
- `solicitudes_vacaciones.fecha_inicio`
- `log_sistema.created_at`, `log_sistema.objeto_actualizado`
- `incidencias.fecha_incidencia`
- `detalle_planillas.nombre_planilla`

Corregida el 2026-08-26: la migración original referenciaba una columna `modulo` inexistente en `log_sistema` (la columna real es `objeto_actualizado`, usada en `LogSistemaController::filtrar()`). Ya está corrida contra la base local.

### ⚠️ Documento raíz del código servido (corregido 2026-08-27)
El vhost de Laragon `rrhh-backend.test` (`C:\laragon\etc\apache2\sites-enabled\auto.rrhh-backend.test.conf`) tenía el `DocumentRoot` apuntando a **`C:\laragon\www\rrhh-backend\public`** — una copia de git **completamente separada** de este repo (`C:\Users\victo\Desktop\rrhh-backend`), congelada en el commit `75fc033` (mayo). Ambas copias comparten la misma base de datos MySQL (`rrhh_hpr`), pero el código PHP servido por Apache era el viejo: **ningún cambio de esta sesión (ni de sesiones previas) estuvo realmente corriendo en `rrhh-backend.test` hasta este fix**.

Se corrigió el `DocumentRoot`/`Directory` del `.conf` para apuntar a `C:/Users/victo/Desktop/rrhh-backend/public` (requiere reiniciar Apache desde Laragon para tomar el cambio). **Riesgo:** el archivo se llama `auto.*.conf` — Laragon podría regenerarlo automáticamente a partir de las carpetas que encuentre en `www\` y revertir el fix. Si `rrhh-backend.test` vuelve a comportarse "raro" (cambios de código que no aparecen), lo primero a revisar es este `DocumentRoot`. La carpeta vieja `C:\laragon\www\rrhh-backend` no se borró — sigue ahí, sin usarse.

---

## Módulos y endpoints (`routes/api.php`)

### Auth (`AuthController`)
```
POST /api/login    — valida credenciales, responde 503 controlado si falla la conexión a BD,
                      mensaje genérico "Correo o contraseña incorrectos" (no revela si el email existe)
POST /api/logout   (auth)
GET  /api/me       (auth)
```

### Dashboard (`DashboardController`)
```
GET /api/dashboard/stats            — empleados_total, activos, fijos, extras, cumpleanos_mes
GET /api/dashboard/planillas-chart  — suma de salario_neto por mes (Fijos/Extras), últimos 12 meses
```

### Cumpleaños (`CumpleanosController`)
```
GET /api/cumpleanos?mes=  — empleados activos que cumplen años ese mes, con dias_para/es_hoy
```

### Empleados (`EmpleadoController`)
```
apiResource /api/empleados   (index con search/id_departamento/estado, show, store, update, destroy=desactivar)
POST /api/empleados/{id}/foto
```
- `store`/`update` crean/actualizan `Empleado` + `InformacionLaboral` en una transacción DB.
- Si `usa_salario_minimo` está marcado, el salario base se toma de `CampoVariable('salario_minimo')` en vez del valor enviado en el request (corregido 2026-08-26 — antes se ignoraba el flag).
- Salarios derivados (quincenal, diario, por hora) se recalculan siempre a partir del salario base.
- `destroy` no elimina: pone `informacion_laboral.estado = 'Inactivo'`.

### Catálogos (`DepartamentoController`, `PuestoController`, `BancoController`)
```
apiResource /api/departamentos
apiResource /api/puestos
apiResource /api/bancos
```

### Vacaciones (`VacacionController`)
```
GET    /api/vacaciones                 — listado paginado (per_page configurable), filtros id_empleado/search
GET    /api/vacaciones/saldo/{id}      — saldo calculado + datos del empleado
POST   /api/vacaciones                 — crea solicitud (valida saldo disponible)
PUT    /api/vacaciones/{id}            — actualiza (valida saldo efectivo = saldo + dias_tomados originales)
DELETE /api/vacaciones/{id}
GET    /api/vacaciones/{id}/pdf        — constancia PDF
```
**Cálculo de saldo (`calcularSaldo`, corregido 2026-08-26):**
- Los días ganados se **acumulan año por año** (1°, 2°, 3°, 4°+ según `Vacacion` config), no una tasa plana sobre el año actual.
- Se calcula un período aniversario (`periodo_inicio`/`periodo_fin`) entre el último y próximo aniversario de `fecha_inicio` del empleado.
- Una sola query con `SUM` condicional obtiene `dias_tomados` (histórico) y `dias_tomados_periodo` (dentro del período actual), en vez de 2 queries separadas.
- `dias_previos` = días ganados antes del período actual menos lo tomado antes de ese período (con piso en 0 si agotó todo).
- `saldo` = acumulado total − tomado total.

### Incidencias (`IncidenciaController`)
```
apiResource /api/incidencias
GET /api/incidencias/{id}/pdf   — constancia PDF (agregado 2026-08-26, vista: resources/views/incidencias/constancia.blade.php)
```

### Planillas (`PlanillaController`)
```
apiResource /api/planillas (except update)   — filtros tipo/estado, paginado
GET  /api/planillas/{id}/pdf
GET  /api/planillas/{id}/excel               — agregado 2026-08-27, ver abajo
POST /api/planillas/{id}/cerrar              — incrementa cuotas_aplicadas de DeduccionCuota, marca Completado si llega al total
PUT  /api/planillas/{id}/detalles/{detalle}
```
- **IHSS:** valor fijo tomado de Campos Variables (por defecto L 297.58). **RAP e ISR:** ya NO se calculan automático (corregido 2026-08-27) — arrancan en 0 y se editan a mano por empleado, porque en la nómina real de Hotel Palma Real y Villas se escriben a mano y no coinciden con una fórmula progresiva. `calcularIsr()` fue eliminado del controlador.
- `store()` corregido 2026-08-26: precarga `OtroIngreso` y `DeduccionCuota` por planilla en 2 queries agrupadas (`groupBy('id_empleado')`) en vez de 2 queries por empleado dentro del loop (N+1).
- **Columnas agregadas 2026-08-27** a `detalle_planillas` (migración `2026_08_27_055636_...`): `horas_extras` (cantidad), `monto_horas_extras` (siempre recalculado server-side en `updateDetalle()` como `salario_diario del empleado / 8 × horas_extras` — nunca se confía en un monto enviado por el cliente) e `i_vecinal`. `uniforme`/`garden` se mantienen aunque no aparezcan en el Excel real de Fijos (decisión explícita: "por si acaso").
- **`GET /api/planillas/{id}/excel`** (agregado 2026-08-27): genera un `.xlsx` (PhpSpreadsheet, ya usado por `barryvdh/laravel-dompdf`/`maatwebsite/excel`) con 2 columnas — `Empleado` y `Salario Neto` (2 decimales) — pensado para armar el archivo de pago del banco. No usa las clases `Export` de Laravel Excel, construye el `Spreadsheet` directamente en el controlador (mismo patrón que `exportPdf`).
- El PDF de planilla (`resources/views/planillas/pdf.blade.php`) agrupa por departamento con fila de subtotal por grupo (`->groupBy('departamento')`), igual que el Excel real de nómina.

### Aguinaldo (`AguinaldoController`)
```
GET    /api/aguinaldo                    — lotes agrupados por nombre_aguinaldo (tipo "Ambos" si existe en fijos y extras)
POST   /api/aguinaldo                    — genera lote (Fijos/Extras/Ambos) para todos los empleados activos
GET    /api/aguinaldo/{nombre}
PUT    /api/aguinaldo/fijos/{id}
PUT    /api/aguinaldo/extras/{id}
POST   /api/aguinaldo/{nombre}/cerrar
DELETE /api/aguinaldo/{nombre}
GET    /api/aguinaldo/{nombre}/pdf
```
- Fijos: `(salario_base / 365) × dias_trabajados − anticipo`.
- Extras: `diario × dias_promedio + antiguedad − anticipos` (dias_promedio = promedio histórico de días trabajados en planillas "Extras" del año, default 15).
- Rutas con `{nombre}` usan `.where('nombre', '.*')` (nombres con espacios).

### Campos Variables (`CamposVariablesController`, solo admin)
```
GET /api/campos-variables   — { ihss, salario_minimo }
PUT /api/campos-variables   — actualiza ambos; si cambia salario_minimo, recalcula salarios de todos los empleados activos con usa_salario_minimo=true y registra log
```

### Estadística Laboral (`EstadisticaLaboralController`)
```
GET /api/estadistica-laboral              — filas agregadas por empleado (dias/salario/deducciones), paginado, con totales globales
GET /api/estadistica-laboral/{empleado}   — detalle quincena por quincena de un empleado
GET /api/estadistica-laboral/pdf
```
Basado en `DetallePlanilla` con joins a `empleados`/`departamentos`, filtrable por rango de fecha y búsqueda por nombre.

### Log del Sistema (`LogSistemaController`)
```
GET /api/log-sistema      — paginado, filtros modulo(objeto_actualizado)/accion/search/fecha_desde/fecha_hasta
GET /api/log-sistema/pdf  — agregado 2026-08-26, reusa el mismo filtro que index() vía método privado filtrar()
```

### Usuarios (`UsuarioController`, solo admin)
```
apiResource /api/usuarios (except show)
```
Contraseñas: el modelo `User` tiene `'password' => 'hashed'` cast, que hashea automáticamente al guardar (idempotente si ya viene hasheado). El controlador además llama `Hash::make()` explícitamente por claridad — no hay doble-hash porque el cast detecta si el valor ya está hasheado.

---

## Vistas PDF (Blade + DomPDF)

Todas comparten identidad visual **Hotel Palma Real y Villas** (rebrand 2026-08-26): logo en `public/images/hpr_logo.png`, colores marrón `#3b2b16` y dorado `#b9921a` extraídos del logotipo (antes: azul genérico `#1d4ed8`).

| Vista | Archivo | Papel |
|---|---|---|
| Aguinaldo | `resources/views/aguinaldo/pdf.blade.php` | Letter landscape |
| Planilla | `resources/views/planillas/pdf.blade.php` | Letter landscape |
| Estadística laboral | `resources/views/estadistica/pdf.blade.php` | Letter landscape |
| Constancia de vacaciones | `resources/views/vacaciones/solicitud.blade.php` | Letter portrait |
| Constancia de incidencia | `resources/views/incidencias/constancia.blade.php` (nuevo) | Letter portrait |
| Log del sistema | `resources/views/log-sistema/pdf.blade.php` (nuevo) | Letter landscape |

---

## Historial de sesiones / cambios

### 2026-08-27 — commit `0e4c2d3`
- **Corregido el `DocumentRoot` del vhost de Apache** — `rrhh-backend.test` servía una copia de código vieja y separada (ver sección Infraestructura). Causa raíz de que los fixes de sesiones anteriores no se vieran reflejados en el sitio.
- **Planilla Fijos rediseñada** para calzar con el Excel real de nómina: RAP e ISR dejan de calcularse automático (se editan a mano), se agregan `horas_extras`/`monto_horas_extras` e `i_vecinal` a `detalle_planillas`, y se elimina `calcularIsr()`.
- Nuevo endpoint `GET /api/planillas/{id}/excel` — exporta Empleado + Salario Neto a `.xlsx` para el archivo de pago del banco.
- El PDF de planilla ahora agrupa por departamento con subtotales, igual que el Excel real.

### 2026-08-26 — commit `2b5469d`
- **OPcache activado** en el entorno local (ver sección Infraestructura) — causa raíz de la lentitud percibida entre pantallas.
- Corregido cálculo de saldo de vacaciones (acumulado por año, `dias_previos`, `dias_tomados_periodo`, validación de saldo también al editar).
- Eliminado N+1 en `PlanillaController::store()`.
- Corregido `EmpleadoController` para respetar el flag `usa_salario_minimo`.
- Agregada exportación a PDF de incidencias y del log del sistema.
- Mejorado `AuthController` (error controlado de conexión a BD, mensaje genérico de credenciales).
- Rebrand de plantillas PDF a identidad Hotel Palma Real y Villas.
- Migración `add_performance_indexes` corregida (columna real `objeto_actualizado`, no `modulo`) y corrida.
- Revisado y confirmado que `UsuarioController` sigue hasheando contraseñas correctamente (`Hash::make` + cast `hashed` del modelo).

### Commits previos
| Commit | Descripción |
|---|---|
| `ea4d63f` | Cambios generales en empleados, banco, planillas, usuarios y demás módulos |
| `3a33b9c` | Carga de contexto .md |
| `a9c2c26` | Módulo 3 — Planillas, Aguinaldo e Incidencias |
| `cd8c94b` | Módulo 2 — Empleados (backend completo) |
| `f31323a` | Módulo 1 — Autenticación y base del sistema |
