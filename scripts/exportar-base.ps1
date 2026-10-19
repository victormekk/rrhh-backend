<#
  Exporta la base local (tablas + todos los datos) a un archivo .sql
  y, opcionalmente, lo carga en la base MySQL de Railway.

  Uso (desde la carpeta rrhh-backend):
    powershell -ExecutionPolicy Bypass -File scripts\exportar-base.ps1
        -> solo genera el .sql en el Escritorio

    powershell -ExecutionPolicy Bypass -File scripts\exportar-base.ps1 -RailwayUrl "mysql://root:CLAVE@HOST:PUERTO/railway"
        -> genera el .sql y lo carga en Railway (usar MYSQL_PUBLIC_URL del servicio MySQL)

  Lee usuario, clave y nombre de la base local desde el .env.
  El .sql tiene datos reales: NO subirlo a GitHub ni compartirlo.
  Cargarlo en Railway REEMPLAZA las tablas que ya existan alla.
#>
param(
    [string]$RailwayUrl = "",
    [string]$Salida = ""
)

$ErrorActionPreference = "Stop"
$raiz = Split-Path -Parent $PSScriptRoot

# --- Datos de la base local desde el .env ---
function Leer-Env($clave, $defecto) {
    $linea = Get-Content (Join-Path $raiz ".env") | Where-Object { $_ -match "^$clave=" } | Select-Object -First 1
    if (-not $linea) { return $defecto }
    $valor = ($linea -split "=", 2)[1].Trim().Trim('"').Trim("'")
    if ($valor -eq "") { return $defecto }
    return $valor
}
$dbHost = Leer-Env "DB_HOST" "127.0.0.1"
$dbPort = Leer-Env "DB_PORT" "3306"
$dbName = Leer-Env "DB_DATABASE" "rrhh_hpr"
$dbUser = Leer-Env "DB_USERNAME" "root"
$dbPass = Leer-Env "DB_PASSWORD" ""

# --- Buscar mysqldump y mysql (PATH o Laragon) ---
function Buscar-Exe($nombre) {
    $cmd = Get-Command $nombre -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $candidatos = Get-ChildItem "C:\laragon\bin\mysql\*\bin\$nombre.exe" -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending
    if ($candidatos) { return $candidatos[0].FullName }
    throw "No se encontro $nombre. Instala MySQL/Laragon o agregalo al PATH."
}
$mysqldump = Buscar-Exe "mysqldump"
$mysql = Buscar-Exe "mysql"

if ($Salida -eq "") {
    $fecha = Get-Date -Format "yyyy-MM-dd_HHmm"
    $Salida = Join-Path ([Environment]::GetFolderPath("Desktop")) "rrhh_hpr_$fecha.sql"
}

# --- 1. Exportar ---
Write-Host "Exportando la base '$dbName' de $dbHost..." -ForegroundColor Cyan
$env:MYSQL_PWD = $dbPass
& $mysqldump "-h$dbHost" "-P$dbPort" "-u$dbUser" `
    --single-transaction --routines --triggers --no-tablespaces `
    --set-gtid-purged=OFF --default-character-set=utf8mb4 --add-drop-table `
    "--result-file=$Salida" $dbName
if ($LASTEXITCODE -ne 0) { throw "mysqldump fallo (codigo $LASTEXITCODE)." }

$tablas = (Select-String -Path $Salida -Pattern "^CREATE TABLE").Count
$mb = [math]::Round((Get-Item $Salida).Length / 1MB, 1)
Write-Host "Listo: $Salida ($tablas tablas, $mb MB)" -ForegroundColor Green

# --- 2. Cargar en Railway (opcional) ---
if ($RailwayUrl -ne "") {
    $uri = [System.Uri]$RailwayUrl
    $partes = $uri.UserInfo -split ":", 2
    $rUser = [System.Uri]::UnescapeDataString($partes[0])
    $rPass = [System.Uri]::UnescapeDataString($partes[1])
    $rDb = $uri.AbsolutePath.Trim("/")
    if ($rDb -eq "") { $rDb = "railway" }

    Write-Host "Cargando en Railway ($($uri.Host):$($uri.Port)/$rDb)... puede tardar unos minutos." -ForegroundColor Cyan
    $env:MYSQL_PWD = $rPass
    $archivo = $Salida -replace "\\", "/"
    & $mysql "-h$($uri.Host)" "-P$($uri.Port)" "-u$rUser" --default-character-set=utf8mb4 $rDb -e "source $archivo"
    if ($LASTEXITCODE -ne 0) { throw "La carga en Railway fallo (codigo $LASTEXITCODE)." }

    $conteo = & $mysql "-h$($uri.Host)" "-P$($uri.Port)" "-u$rUser" $rDb -N -e "SELECT COUNT(*) FROM empleados"
    Write-Host "Cargado en Railway. Empleados en la base: $conteo" -ForegroundColor Green
}

Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
