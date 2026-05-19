# Contexto de Cambios — RRHH Backend (Laravel)

> Proyecto: Sistema de Recursos Humanos — Hotel Palma Real  
> Backend: Laravel + Sanctum · Base de datos: MySQL  
> Fecha de última actualización: 2026-05-19

---

## Historial de commits

| # | Commit | Descripción |
|---|--------|-------------|
| 1 | `f95c1ba` | Inicio del proyecto (Vue 3 + Tailwind + Pinia + Router — lado frontend) |
| 2 | `75fc033` | Migraciones completas — todas las tablas del sistema |
| 3 | `f31323a` | Módulo 1 — Autenticación y base del sistema |
| 4 | `cd8c94b` | Módulo 2 — Empleados (backend completo) |
| 5 | `a9c2c26` | Módulo 3 — Planillas, Aguinaldo e Incidencias |

---

## Módulo 1 — Autenticación (`f31323a`)

### Archivos creados
| Archivo | Propósito |
|---------|-----------|
| `app/Http/Controllers/AuthController.php` | Login (Sanctum token), logout, endpoint `/me` |
| `app/Http/Controllers/DashboardController.php` | Stats del dashboard: empleados activos, departamentos, incidencias, planillas |
| `app/Models/Empleado.php` | Modelo base de empleado |
| `app/Models/Departamento.php` | Catálogo de departamentos |
| `app/Models/InformacionLaboral.php` | Datos laborales del empleado (salarios, banco, estado) |
| `app/Models/Incidencia.php` | Registro de incidencias disciplinarias |
| `app/Models/CabeceraPlanilla.php` | Cabecera/encabezado de cada planilla |

### Rutas registradas
```
POST   /api/login
POST   /api/logout          (auth)
GET    /api/me              (auth)
GET    /api/dashboard/stats (auth)
```

---

## Módulo 2 — Empleados (`cd8c94b`)

### Archivos creados
| Archivo | Propósito |
|---------|-----------|
| `app/Http/Controllers/EmpleadoController.php` | CRUD completo + `uploadFoto`; crea `informacion_laboral` en transacción |
| `app/Http/Controllers/DepartamentoController.php` | CRUD catálogo departamentos |
| `app/Http/Controllers/PuestoController.php` | CRUD catálogo puestos |
| `app/Http/Controllers/BancoController.php` | CRUD catálogo bancos |
| `app/Models/Puesto.php` | Modelo puestos |
| `app/Models/Banco.php` | Modelo bancos |
| `app/Models/DetallePlanilla.php` | Línea individual de planilla por empleado |
| `app/Models/OtroIngreso.php` | Ingresos adicionales en planilla |
| `app/Models/OtraDeduccion.php` | Deducciones adicionales en planilla |
| `app/Models/DeduccionCuota.php` | Deducciones por cuotas (préstamos, etc.) |

### Lógica destacada
- `EmpleadoController::store()` crea el empleado y su `informacion_laboral` en una misma transacción DB.
- `Empleado` tiene un accessor `foto_url` que se incluye automáticamente (`$appends`).
- Los salarios se calculan automáticamente al guardar: **quincenal, diario y por hora** a partir del salario base mensual.

### Rutas registradas
```
apiResource  /api/empleados
apiResource  /api/departamentos
apiResource  /api/puestos
apiResource  /api/bancos
POST         /api/empleados/{id}/foto
```

---

## Módulo 3 — Planillas, Aguinaldo e Incidencias (`a9c2c26`)

### 3.1 Planillas

**Archivo:** `app/Http/Controllers/PlanillaController.php`  
**Modelos usados:** `CabeceraPlanilla`, `DetallePlanilla`, `OtroIngreso`, `DeduccionCuota`

#### Endpoints
| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/api/planillas` | Listar planillas (paginado, filtros por `tipo` y `estado`) |
| POST | `/api/planillas` | Generar planilla nueva (auto-calcula todos los rubros) |
| GET | `/api/planillas/{id}` | Detalle con todos los renglones |
| PUT | `/api/planillas/{id}/detalles/{detalle}` | Editar un renglón individual |
| POST | `/api/planillas/{id}/cerrar` | Cerrar planilla y aplicar cuotas |
| DELETE | `/api/planillas/{id}` | Eliminar planilla activa |
| GET | `/api/planillas/{id}/pdf` | Exportar PDF (DomPDF, A4 landscape) |

#### Lógica de cálculo en `store()`
- **IHSS:** 3.5 % sobre quincenal (techo L 25,500).
- **RAP:** 1.5 % sobre salario base quincenal.
- **ISR:** escala anual progresiva (0 % / 15 % / 20 % / 25 %) dividida entre 24 quincenas.
- `otros_ingresos` y `desc_ingresos` se toman de la tabla `otros_ingresos` filtrando por `nombre_planilla`.
- `otras_deducciones` se suman de `deduccion_cuotas` activas del empleado.

#### Lógica de `cerrar()`
- Cambia estado a `Cerrado`.
- Incrementa `cuotas_aplicadas` en cada `DeduccionCuota` activa de los empleados de esa planilla.
- Si `cuotas_aplicadas >= total_cuotas`, la cuota pasa a estado `Completado`.

---

### 3.2 Aguinaldo

**Archivo:** `app/Http/Controllers/AguinaldoController.php`  
**Modelos nuevos:** `app/Models/AguinaldoFijo.php`, `app/Models/AguinaldoExtra.php`  
**Vista PDF:** `resources/views/aguinaldo/pdf.blade.php`

#### Endpoints
| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/api/aguinaldo` | Listar lotes (agrupados por `nombre_aguinaldo`, muestra tipo `Ambos` si aplica) |
| POST | `/api/aguinaldo` | Generar lote (`tipo_aguinaldo`: Fijos / Extras / Ambos) |
| GET | `/api/aguinaldo/{nombre}` | Detalle del lote con fijos y extras + totales |
| PUT | `/api/aguinaldo/fijos/{id}` | Editar registro fijo (días trabajados, anticipo) |
| PUT | `/api/aguinaldo/extras/{id}` | Editar registro extra (días promedio, antigüedad, anticipos) |
| POST | `/api/aguinaldo/{nombre}/cerrar` | Cerrar lote completo |
| DELETE | `/api/aguinaldo/{nombre}` | Eliminar lote activo |
| GET | `/api/aguinaldo/{nombre}/pdf` | PDF A4 landscape |

> Las rutas con `{nombre}` usan `.where('nombre', '.*')` para soportar nombres con espacios/slashes.

#### Lógica de cálculo en `store()`
- **Fijos:** `total = (salario_base / 365) × dias_trabajados − anticipo`  
  `dias_trabajados` = días desde `fecha_inicio` hasta `fecha_generada` (máx 365).
- **Extras:** `diario = salario_diario`, `dias_promedio` = promedio de `dias_trabajados` en planillas Extras del año en curso (default 15 si no hay historial).  
  `subtotal = diario × dias_promedio + antiguedad`, `total = subtotal − anticipos`.

#### Tablas en BD
- `aguinaldo_fijos` — columnas: `nombre_aguinaldo`, `departamento`, `nombres`, `apellidos`, `cuenta`, `fecha_inicio`, `salario_base`, `dias_trabajados`, `anticipo`, `total_aguinaldo`, `fecha_generada`, `estado`, `tipo_aguinaldo`, `id_empleado`, `id_info_laboral`, `id_departamento`.
- `aguinaldo_extras` — mismas + `diario`, `antiguedad`, `subtotal`, `dias_promedio`, `anticipos`.

---

### 3.3 Incidencias

**Archivo:** `app/Http/Controllers/IncidenciaController.php`

#### Endpoints (`apiResource`)
```
GET    /api/incidencias          filtros: search, grado, fecha_inicio, fecha_fin, id_empleado
POST   /api/incidencias
GET    /api/incidencias/{id}
PUT    /api/incidencias/{id}
DELETE /api/incidencias/{id}
```

#### Campos validados en `store()`
| Campo | Regla |
|-------|-------|
| `id_empleado` | `required | exists:empleados,id` |
| `fecha_incidencia` | `required | date` |
| `titulo` | `required | max:50` |
| `descripcion` | `required | max:300` |
| `grado` | `required | in:Leve,Moderada,Grave` |

---

## Vistas PDF (Blade + DomPDF)

| Vista | Ruta de archivo | Descripción |
|-------|----------------|-------------|
| Aguinaldo | `resources/views/aguinaldo/pdf.blade.php` | Tabla fijos y/o extras, totales, 3 firmas |
| Planilla | `resources/views/planillas/pdf.blade.php` | Todos los renglones de la planilla + totales |

Ambas usan papel **A4 landscape**, fuente DejaVu Sans (compatible DomPDF), y se descargan directamente como archivo.

---

## Estado actual de `routes/api.php`

```php
// Públicas
POST /api/login

// Protegidas (Sanctum)
POST   /api/logout
GET    /api/me
GET    /api/dashboard/stats

apiResource /api/empleados
apiResource /api/departamentos
apiResource /api/puestos
apiResource /api/bancos

// Aguinaldo (rutas específicas antes de wildcard {nombre})
GET    /api/aguinaldo
POST   /api/aguinaldo
PUT    /api/aguinaldo/fijos/{id}
PUT    /api/aguinaldo/extras/{id}
GET    /api/aguinaldo/{nombre}/pdf
POST   /api/aguinaldo/{nombre}/cerrar
GET    /api/aguinaldo/{nombre}
DELETE /api/aguinaldo/{nombre}

apiResource /api/incidencias

// Planillas
GET    /api/planillas/{id}/pdf
POST   /api/planillas/{id}/cerrar
PUT    /api/planillas/{id}/detalles/{detalle}
apiResource /api/planillas  (except update)
```

---

## Archivos pendientes de commit (untracked/modified al inicio de sesión)

Estos archivos ya existen en disco pero no estaban en el último commit registrado al inicio de la sesión. Fueron creados/modificados en el commit `a9c2c26`:

- `app/Http/Controllers/AguinaldoController.php`
- `app/Http/Controllers/IncidenciaController.php`
- `app/Http/Controllers/PlanillaController.php`
- `app/Models/AguinaldoExtra.php`
- `app/Models/AguinaldoFijo.php`
- `resources/views/aguinaldo/pdf.blade.php`
- `resources/views/planillas/pdf.blade.php`
- `routes/api.php` (modificado)
