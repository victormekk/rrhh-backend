# Deploy en Railway — Sistema RRHH Hotel Palma Real

El sistema son **3 servicios** dentro de un mismo proyecto de Railway:

| Servicio | Origen | Qué es |
|---|---|---|
| **MySQL** | Plantilla "MySQL" de Railway | Base de datos |
| **rrhh-backend** | GitHub `victormekk/rrhh-backend` | API Laravel 13 / PHP 8.4 |
| **rrhh-frontend** | GitHub `victormekk/rrhh_frontend` | Vue (Vite), servido como sitio estático |

El frontend se construye con **Railpack** (detección automática). El backend usa su propio
**`Dockerfile`** (PHP 8.4 + FrankenPHP con `pdo_mysql`, `gd`, `zip`, `intl`, `bcmath`), porque
la imagen de Railpack no traía el driver de MySQL. Cada repo trae un `railway.json` con el
health check y la política de reinicio. Al arrancar, el backend corre `docker/start.sh`:
`php artisan migrate --force`, `storage:link`, `optimize` y luego levanta el servidor en `$PORT`.

---

## 1. Base de datos

1. En el proyecto de Railway: **+ New → Database → MySQL**.
2. **Pasar los datos actuales** (antes de desplegar el backend):
   0. **Borrar los datos de prueba** en local: `php artisan pruebas:extras --borrar`
      (20 extras con cédula `PRUEBA-…` y planillas "PRUEBA Extras …").
   1. Exportar y cargar con el script (lee la base local desde el `.env`):
      ```
      powershell -ExecutionPolicy Bypass -File scripts\exportar-base.ps1 -RailwayUrl "<MYSQL_PUBLIC_URL>"
      ```
      `MYSQL_PUBLIC_URL` está en el servicio MySQL → **Variables**. Sin `-RailwayUrl` solo
      genera el `.sql` en el Escritorio (no subirlo a GitHub: tiene datos reales).
      Cargarlo **reemplaza** las tablas que ya existan en Railway.
   2. Alternativa manual: en HeidiSQL, **Nueva sesión** con los datos de `MYSQL_PUBLIC_URL` →
      base `railway` → **Archivo → Ejecutar archivo SQL** → elegir el `.sql`.

   > Si la base se carga antes del primer deploy, las migraciones ya figuran como
   > ejecutadas y el backend no intentará recrear tablas.

## 2. Backend (`rrhh-backend`)

1. **+ New → GitHub Repo → rrhh-backend**.
2. **Settings → Networking → Generate Domain** (queda `https://<algo>.up.railway.app`).
3. **Volumes → + New Volume**, ruta de montaje: **`/app/storage/app/public`**
   (ahí se guardan las fotos de empleados; sin volumen se borran en cada deploy).
4. **Variables** (pestaña *Raw Editor*):

```env
APP_NAME="RRHH Palma Real"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                      # generar en local: php artisan key:generate --show
APP_URL=https://<backend>.up.railway.app

DB_CONNECTION=mysql
DATABASE_URL=${{MySQL.MYSQL_URL}}

LOG_CHANNEL=stderr
LOG_LEVEL=warning

CORS_ALLOWED_ORIGINS=https://<frontend>.up.railway.app
SANCTUM_EXPIRATION=720
SANCTUM_INACTIVIDAD=120

ASISTENCIA_TOKEN=             # cadena larga y aleatoria; la misma va en el agente del reloj
```

5. Deploy. Verificar `https://<backend>.up.railway.app/api/salud` → `{"estado":"ok"}`.

## 3. Frontend (`rrhh_frontend`)

1. **+ New → GitHub Repo → rrhh_frontend**.
2. **Settings → Networking → Generate Domain**.
3. **Variables**:

```env
VITE_API_URL=https://<backend>.up.railway.app/api
```

   > `VITE_API_URL` se incrusta al **compilar**: si se cambia, hay que volver a desplegar.

4. Volver al backend y poner el dominio real del frontend en `CORS_ALLOWED_ORIGINS`.

## 4. Agente del reloj biométrico (PC del hotel)

En `zkteco-agente/.env` de la PC del hotel:

```env
API_URL=https://<backend>.up.railway.app/api/asistencias/importar
API_TOKEN=<mismo valor que ASISTENCIA_TOKEN>
```

## 5. Verificación final

- Iniciar sesión en el frontend con un usuario existente.
- Abrir una planilla y descargar su PDF y Excel.
- Subir una foto de empleado, hacer **Redeploy** del backend y confirmar que la foto sigue.
- Log del Sistema: aparecen el inicio y cierre de sesión.

## Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| El navegador muestra error de CORS | `CORS_ALLOWED_ORIGINS` no coincide exactamente con el dominio del frontend (con `https://`, sin `/` final). |
| El frontend llama a `localhost:8000` | Falta `VITE_API_URL` o se agregó después de compilar: redeploy del frontend. |
| Las fotos no cargan | Falta el volumen o `APP_URL` no es el dominio público del backend. |
| `could not find driver` | El servicio se está construyendo con Railpack: revisar que `railway.json` diga `"builder": "DOCKERFILE"`. |
| El health check falla | Revisar `DATABASE_URL` y los logs del deploy (con `LOG_CHANNEL=stderr` se ven en Railway). |
