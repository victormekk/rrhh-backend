# Contexto de Cambios — RRHH Backend (Laravel)

> Proyecto: Sistema de Recursos Humanos — Hotel Palma Real y Villas
> Fecha de última actualización: 2026-09-14

---

## Stack tecnológico — qué es cada cosa y para qué se usa

| Tecnología | Versión | Qué hace en este proyecto |
|---|---|---|
| **PHP** | 8.1.10 (Laragon, Windows) | Runtime del backend. |
| **Laravel** | ^10.10 (framework 10.50.2) | Framework principal: rutas (`routes/api.php`), Eloquent ORM, migraciones, validación, colas de eventos. Toda la API es JSON (`api.php`), no hay vistas Blade renderizadas al usuario salvo los PDFs. |
| **Laravel Sanctum** | ^3.3 | Autenticación por **token Bearer** (no cookies de sesión) — el frontend SPA manda `Authorization: Bearer <token>` en cada request. |
| **MySQL** | — | Base de datos relacional (`rrhh_hpr`). |
| **barryvdh/laravel-dompdf** | ^3.1 | Genera todos los PDFs del sistema: renderiza una vista Blade a HTML y la convierte a PDF (motor DomPDF). Cada documento tiene su propio `.blade.php` en `resources/views/`. |
| **maatwebsite/excel** (PhpSpreadsheet) | ^3.1 | Exportación a `.xlsx`. El código actual usa `PhpOffice\PhpSpreadsheet` directamente en el controlador (no las clases `Export` de Laravel Excel) para el archivo de pago del banco. |
| **Servidor local** | Apache (Laragon) / `php artisan serve` | Ver notas de infraestructura abajo — históricamente hubo confusión entre dos copias del código sirviendo en paralelo. |

---

## Infraestructura / entorno local

### OPcache
El `php.ini` de Laragon traía OPcache deshabilitado, obligando a recompilar Laravel + vendor en cada request. Se activó (`opcache.enable=1`, `validate_timestamps=1`, `revalidate_freq=0` — detección instantánea de cambios, apto para desarrollo). Este cambio vive en el `php.ini` del entorno, **no en el repositorio** — hay que replicarlo en cualquier otra máquina.

> Si el proyecto pasa a producción: `opcache.validate_timestamps=0` + `opcache_reset()` en cada deploy.

### Migraciones de rendimiento
`database/migrations/2026_05_29_000001_add_performance_indexes.php` agrega índices a `solicitudes_vacaciones.fecha_inicio`, `log_sistema.created_at`/`objeto_actualizado`, `incidencias.fecha_incidencia`, `detalle_planillas.nombre_planilla`.

### ⚠️ Documento raíz del código servido
El vhost de Laragon `rrhh-backend.test` tenía el `DocumentRoot` apuntando a una copia de git **separada y congelada** en `C:\laragon\www\rrhh-backend`. Se corrigió para apuntar a `C:/Users/victo/Desktop/rrhh-backend/public`. **Riesgo:** el `.conf` es `auto.*` — Laragon puede regenerarlo. Si `rrhh-backend.test` "no refleja los cambios", revisar esto primero. La copia vieja no se borró, sigue sin usarse.

### ⚠️ Fotos de empleado rotas
Causa: faltaba `php artisan storage:link` y `APP_URL` no tenía el puerto correcto (`8000`, el de `php artisan serve`, que es el que consume el frontend). Corregido; ninguno de los dos cambios está versionado (hay que repetirlos en cualquier clon nuevo).

---

## Traits reutilizables (`app/Traits/`)

| Trait | Qué hace | Usado por |
|---|---|---|
| `LogsActividad` | `logActividad($accion, $modulo, $descripcion, $idObjeto)` — escribe en `log_sistema` usando `auth()->id()`. Envuelto en try/catch: un fallo de log nunca debe romper la operación principal. | Casi todos los controladores que generan documentos o hacen cambios sensibles. |
| `NombraArchivos` | `nombreArchivo('Tipo', 'Nombre', 'ext')` → `Tipo_Nombre_ddmmaaaa.ext`; `sanitizarNombreArchivo()` quita acentos/caracteres inválidos conservando espacios (para archivos con nombre propio como Planilla o Aguinaldo). | Todo controlador que ofrece descarga de PDF/Excel. |
| `SoloAdmin` | Gate de rol: aborta 403 JSON si el usuario autenticado no es admin. | Acciones de "eliminar" (hard delete) en Planillas, Departamentos, Cargos. |
| `GeneraCorrelativo` (nuevo, 2026-09-14) | `siguienteCorrelativo('tipo', $referenciaId)` — devuelve el siguiente número de 5 dígitos (`00001`, `00002`...) para un tipo de documento, con `lockForUpdate()` para evitar duplicados en generación simultánea. Ver sección "Correlativo de documentos" abajo. | Todos los controladores que generan un PDF. |

---

## Módulos y endpoints (`routes/api.php`)

### Auth (`AuthController`)
```
POST /api/login    — 503 controlado si falla la conexión a BD; mensaje genérico si las
                      credenciales no son válidas (no revela si el email existe)
POST /api/logout   (auth)
GET  /api/me       (auth)
```

### Dashboard (`DashboardController`)
```
GET /api/dashboard/stats            — empleados_total, activos, fijos, extras, cumpleanos_mes
GET /api/dashboard/planillas-chart  — suma de salario_neto por mes (Fijos/Extras), últimos 12 meses
```

### Empleados (`EmpleadoController`)
```
apiResource /api/empleados   (index con search incluye cédula; filtros id_departamento/tipo_contrato/estado)
POST /api/empleados/{id}/foto
```
- `index()` ordena por departamento (alfabético) y luego por apellidos del empleado.
- `store`/`update`: si `usa_salario_minimo`, el salario base se toma de `CampoVariable('salario_minimo')`.
- `destroy` no elimina: pone `informacion_laboral.estado = 'Inactivo'`.

### Catálogos (`DepartamentoController`, `CargoController`, `BancoController`)
```
apiResource /api/departamentos
DELETE      /api/departamentos/{id}/eliminar   (solo admin, hard delete)
apiResource /api/cargos                         (renombrado de "Puestos" — tabla, columnas, modelo, controlador y rutas)
DELETE      /api/cargos/{id}/eliminar          (solo admin, hard delete)
apiResource /api/bancos
```
- **`destroy` (desactivar, cualquier rol)**: bloqueado si tiene empleados **activos** asignados (mensaje lista los nombres).
- **`eliminar` (hard delete, solo admin, trait `SoloAdmin`)**: requiere `estado = 'Inactivo'` y **cero** empleados asignados (activos o no).
- Migración `2026_09_14_011221_rename_puestos_to_cargos.php` usa `DB::statement('ALTER TABLE ... CHANGE ...')` en vez de `Schema::renameColumn()` porque `doctrine/dbal` no está instalado (requerido por Laravel 10 para ese método). MySQL actualiza las FK automáticamente al renombrar.

### Vacaciones (`VacacionController`)
```
GET    /api/vacaciones                 — listado paginado, filtros id_empleado/search
GET    /api/vacaciones/saldo/{id}      — saldo calculado + datos del empleado
POST   /api/vacaciones                 — crea solicitud (valida saldo disponible)
PUT    /api/vacaciones/{id}            — actualiza (valida saldo efectivo)
DELETE /api/vacaciones/{id}
GET    /api/vacaciones/{id}/pdf        — constancia PDF (correlativo propio)
```
Mensajes de error reformulados: "No hay suficientes días disponibles..." (antes "Saldo insuficiente"). Cálculo de saldo acumulado año por año, con período aniversario según `fecha_inicio` del empleado.

### Incidencias (`IncidenciaController`)
```
apiResource /api/incidencias
GET /api/incidencias/{id}/pdf   — constancia PDF (correlativo propio, ya no usa el id interno)
```

### Constancias (`ConstanciaController`) — Constancia Laboral + Voucher de Pago
```
GET /api/constancias/laboral/{id}/pdf                      — constancia laboral, con log de quién la solicitó
GET /api/constancias/voucher/{empleado}/planillas          — planillas CERRADAS donde aparece ese empleado
GET /api/constancias/voucher/{empleado}/{planilla}/pdf     — genera el voucher de esa quincena
```
El **Voucher de Pago** (agregado 2026-09-14) muestra los datos completos de esa planilla para ese empleado: días trabajados, salario base/diario, horas extra, otros ingresos, IHSS/RAP/ISR/Crefisa y demás deducciones, deducción neta, salario neto, espacio de firma (con 1cm extra de aire antes de la línea). Nombre de archivo: `NombrePlanilla_NombreEmpleado.pdf`.

### Planillas (`PlanillaController`)
```
apiResource /api/planillas (except update)   — filtros tipo/estado, paginado
GET    /api/planillas/{id}/pdf
GET    /api/planillas/{id}/excel             — Empleado + Salario Neto, para el archivo de pago del banco
GET    /api/planillas/{id}/pago              — Excel simple "Generar Pago"
POST   /api/planillas/{id}/cerrar            — incrementa cuotas_aplicadas de DeduccionCuota
PUT    /api/planillas/{id}/detalles/{detalle}
DELETE /api/planillas/{id}/eliminar-cerrada  — solo admin, requiere reingresar contraseña (Hash::check)
```
- `store()` filtra empleados por `tipo_contrato` correspondiente al `tipo_planilla` (Fijos→Fijo, Extras→Extra) — antes incluía a todos sin filtrar.
- **IHSS:** valor fijo de Campos Variables. **RAP e ISR:** se editan a mano por empleado (no calzan con fórmula automática en la nómina real). Personal "Extras" no cotiza IHSS.
- Todas las consultas de detalle (`show`, `exportPdf`, `exportPago`, `exportExcel`) usan `ordenarPorDeptoYNombre($query)`: ordena por `departamento` (alfabético) y luego por **nombres** del empleado (no apellidos — corregido para calzar con el formato "Nombres Apellidos" que se muestra en toda la app).
- `calcularTotales()` incluye `dias_trabajados` (antes faltaba la fila TOTAL GENERAL de días en el PDF).
- PDF (`resources/views/planillas/pdf.blade.php`): columnas reajustadas (nombre más angosto, columnas de deducción más anchas) para que montos de más dígitos no monten el layout; fuente más chica en filas de subtotal/total (suman muchos empleados, pueden dar cifras de 7 dígitos).

### Aguinaldo (`AguinaldoController`)
```
GET    /api/aguinaldo
POST   /api/aguinaldo                    — genera lote (Fijos/Extras/Ambos)
GET    /api/aguinaldo/{nombre}
PUT    /api/aguinaldo/fijos/{id}
PUT    /api/aguinaldo/extras/{id}
POST   /api/aguinaldo/{nombre}/cerrar
DELETE /api/aguinaldo/{nombre}
GET    /api/aguinaldo/{nombre}/pdf
```
- **Base de cálculo: 360 días (12×30)**, no 365 — decisión de negocio explícita ("nunca se cuentan los meses de 31 días"). Campo `fecha_corte` agregado, independiente de `fecha_generada` (permite usar un corte distinto, ej. para el "catorceavo").
- `store()` clasifica correctamente Fijos/Extras/Ambos sin duplicar conteo.
- Orden: `departamento` → **nombres** → apellidos (mismo criterio que Planillas).
- PDF: tabla de Fijos reordenada para calzar con el Excel real de referencia (Nombre, Cuenta, Cargo, Fecha Inicio, Salario Mensual, Días Año, Anticipo, Aguinaldo a Pagar), agrupada por departamento; Extras agrupado igual, sin tocar su fórmula (es distinta a la de Fijos).
- Columna `puesto` renombrada a `cargo` en `aguinaldo_fijos`.

### Campos Variables (`CamposVariablesController`, solo admin)
```
GET /api/campos-variables   — { ihss, salario_minimo }
PUT /api/campos-variables   — si cambia salario_minimo, recalcula salarios de empleados con usa_salario_minimo=true
```

### Estadística Laboral (`EstadisticaLaboralController`)
```
GET /api/estadistica-laboral              — filas agregadas por empleado, paginado, con totales
GET /api/estadistica-laboral/{empleado}   — detalle quincena por quincena
GET /api/estadistica-laboral/pdf          — correlativo propio
```
Filtro exacto por `id_empleado` agregado (para el buscador tipo typeahead del frontend) además de búsqueda parcial por nombre/cédula.

### Log del Sistema (`LogSistemaController`)
```
GET /api/log-sistema      — paginado, filtros modulo(objeto_actualizado)/accion/search/fecha_desde/fecha_hasta
GET /api/log-sistema/pdf  — correlativo propio
```

### Usuarios (`UsuarioController`, solo admin)
```
apiResource /api/usuarios (except show)
```

---

## Correlativo de documentos (agregado 2026-09-14)

Todos los PDFs generados por el sistema muestran un número de documento de **5 dígitos** (`N° 00001`, `N° 00002`...), **independiente por tipo de documento** — cada tipo lleva su propia secuencia:

`voucher`, `constancia_laboral`, `incidencia`, `vacacion`, `planilla`, `aguinaldo`, `estadistica_laboral`, `log_sistema`.

**Por qué existe:** antes, algunos documentos (el Voucher de Pago) mostraban el `id` interno de `detalle_planillas` como número de documento — esa tabla acumula una fila por cada empleado de cada planilla generada, incluidas pruebas internas de rendimiento, así que el número llegaba a valores como `01065` sin ninguna relación con cuántos vouchers se habían emitido realmente. Otros documentos (Incidencias, Vacaciones) usaban el `id` de su propia tabla, que es razonable pero no seguía el mismo formato de 5 dígitos ni el mismo criterio entre módulos.

**Cómo funciona:**
- Tabla `documentos_generados`: `id`, `tipo`, `correlativo` (único por `tipo`), `referencia_id` (opcional, para auditoría), `created_at`.
- Trait `GeneraCorrelativo::siguienteCorrelativo($tipo, $referenciaId)`: dentro de una transacción, bloquea (`lockForUpdate`) las filas de ese `tipo`, toma el máximo actual + 1, inserta el registro y devuelve el número ya formateado a 5 dígitos.
- Cada controlador que genera un PDF llama a este método antes de renderizar la vista y pasa `$correlativo` al Blade.
- **Cada generación de un mismo documento consume un número nuevo** (si se vuelve a descargar la misma constancia, se emite un correlativo distinto) — mismo criterio para los 8 tipos.

---

## Vistas PDF (Blade + DomPDF)

Todas comparten identidad visual Hotel Palma Real y Villas: logo en `public/images/hpr_logo.png`, colores marrón `#3b2b16` y dorado `#b9921a`. Todas muestran su correlativo de 5 dígitos.

| Vista | Archivo | Papel |
|---|---|---|
| Aguinaldo | `resources/views/aguinaldo/pdf.blade.php` | Letter landscape |
| Planilla | `resources/views/planillas/pdf.blade.php` | Letter landscape |
| Estadística laboral | `resources/views/estadistica/pdf.blade.php` | Letter landscape |
| Constancia de vacaciones | `resources/views/vacaciones/solicitud.blade.php` | Letter portrait |
| Constancia de incidencia | `resources/views/incidencias/constancia.blade.php` | Letter portrait |
| Constancia laboral | `resources/views/constancias/laboral.blade.php` | Letter portrait |
| Voucher de Pago | `resources/views/constancias/voucher.blade.php` (nuevo) | Letter portrait |
| Log del sistema | `resources/views/log-sistema/pdf.blade.php` | Letter landscape |

**Técnica de verificación visual usada en desarrollo** (no hay poppler-utils instalado para convertir PDF a imagen): renderizar la vista Blade a HTML crudo (`view(...)->render()`), servirla con `php -S 127.0.0.1:PUERTO -t public` (para que resuelvan las rutas de imágenes), abrir en Chrome y capturar pantalla.

---

## Datos de catálogo — fusiones de nombres duplicados (2026-09-14)

Con el tiempo quedaron nombres de **departamento** y **cargo** duplicados conceptualmente (ej. "Ama de Llaves" vs "Pisos"/"Camarera" — mismo puesto, dos registros distintos). Se resolvió por **fusión**, no por borrado:
1. Reasignar todos los empleados del registro obsoleto al registro que se mantiene (`Empleado.id_departamento` / `Empleado.id_cargo`).
2. Actualizar el texto guardado en `detalle_planillas.departamento` (snapshot histórico) y en `aguinaldo_fijos`/`aguinaldo_extras` (`departamento`, `id_departamento`) donde aplique.
3. Desactivar (no eliminar) el registro obsoleto — queda en `estado = 'Inactivo'`, sin empleados, para no perder el historial ni romper una FK.
4. Registrar la fusión en `log_sistema` para auditoría.

Este es el procedimiento a seguir si aparece otro caso similar.

---

## Historial de sesiones / cambios

### 2026-09-14 — sesión larga (múltiples commits)
Ver el detalle de negocio en `contexto_frontend.md` (la mayoría de los cambios son full-stack). Resumen específico de backend:
- Filtro de `tipo_contrato` corregido en generación de Planillas y Aguinaldo.
- Renombre completo `Puesto` → `Cargo` (tabla, columnas, modelo, controlador, rutas) vía migración con SQL crudo.
- Permisos: `SoloAdmin` en endpoints de eliminación (hard delete) de Departamentos/Cargos/Planillas cerradas; validaciones para no desactivar/eliminar con empleados asignados.
- `eliminar-cerrada` de Planillas: requiere reingresar contraseña (`Hash::check`).
- Log de auditoría (`LogsActividad`) agregado a `ConstanciaController::laboral()`.
- Nuevos endpoints de **Voucher de Pago** en `ConstanciaController`.
- Orden de empleados dentro de cada departamento (Planillas/Aguinaldo) corregido de apellidos a nombres.
- **Sistema de correlativo de 5 dígitos** para los 8 tipos de documento PDF (tabla `documentos_generados`, trait `GeneraCorrelativo`) — reemplaza el uso de ids internos (inflados por datos de prueba) como número de documento.
- 1cm extra de espacio antes de la línea de firma en el Voucher de Pago.
- 200 empleados sintéticos (`9999...`) y planillas/incidencias de prueba generadas para pruebas de rendimiento.
- 10 planillas reales (Fijos + Extras, Junio 1ra/2da, Julio 1ra/2da, Agosto 1ra) generadas con empleados reales para completar el histórico.
- Fusión de departamento "Ama de Llaves" → "Pisos" y cargo "Ama de Llaves" → "Camarera" (ver sección arriba).

> **Nota de seguridad de datos:** durante las pruebas de esta sesión se detectó un caso donde una acción destructiva se probó por error contra un registro real (un departamento) en vez de un registro de prueba — se restauró de inmediato. Desde entonces, toda prueba de una acción destructiva usa registros creados específicamente para la prueba (ej. `TEST-ELIMINAR-CERRADA`), nunca datos reales existentes.

### 2026-08-27 — commit `0e4c2d3`
- Corregido el `DocumentRoot` del vhost de Apache (servía código viejo).
- Planilla Fijos rediseñada para calzar con el Excel real: RAP e ISR ya no se calculan automático, se agregan `horas_extras`/`monto_horas_extras`/`i_vecinal`.
- Nuevo endpoint `GET /api/planillas/{id}/excel`.
- PDF de planilla agrupado por departamento con subtotales.

### 2026-08-26 — commit `2b5469d`
- OPcache activado en el entorno local.
- Corregido cálculo de saldo de vacaciones (acumulado por año).
- Eliminado N+1 en `PlanillaController::store()`.
- Corregido `EmpleadoController` para respetar `usa_salario_minimo`.
- Exportación a PDF de incidencias y log del sistema.
- `AuthController` con error controlado de conexión a BD.
- Rebrand de plantillas PDF a identidad Hotel Palma Real y Villas.

### Commits previos
| Commit | Descripción |
|---|---|
| `ea4d63f` | Cambios generales en empleados, banco, planillas, usuarios y demás módulos |
| `3a33b9c` | Carga de contexto .md |
| `a9c2c26` | Módulo 3 — Planillas, Aguinaldo e Incidencias |
| `cd8c94b` | Módulo 2 — Empleados (backend completo) |
| `f31323a` | Módulo 1 — Autenticación y base del sistema |
