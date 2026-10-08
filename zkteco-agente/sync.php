<?php

/**
 * Agente local de reloj biométrico ZKTeco.
 *
 * Corre en una PC de la red local del hotel (NO en el servidor del sistema,
 * que está en la nube y no puede alcanzar el reloj por LAN). Se conecta al
 * K30 por UDP, lee las marcaciones y las sube por HTTPS al sistema RRHH.
 *
 * Uso:
 *   php sync.php              -> sincroniza usando la config de .env
 *   php sync.php --info       -> solo muestra info del dispositivo (prueba de conexión, no sube nada)
 *
 * ADVERTENCIA: la librería coding-libs/zkteco-php se marca a sí misma como
 * "no recomendada para producción". Antes de dejar esto corriendo sin
 * supervisión, probar varias veces contra el reloj real y revisar sync.log.
 */

require __DIR__ . '/vendor/autoload.php';

use CodingLibs\ZktecoPhp\Libs\ZKTeco;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

function log_linea(string $mensaje): void
{
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $mensaje;
    echo $linea . PHP_EOL;
    file_put_contents(__DIR__ . '/sync.log', $linea . PHP_EOL, FILE_APPEND);
}

$ip       = $_ENV['DEVICE_IP']       ?? null;
$puerto   = (int) ($_ENV['DEVICE_PORT'] ?? 4370);
$password = $_ENV['DEVICE_PASSWORD'] ?? 0;
$apiUrl   = $_ENV['API_URL']         ?? null;
$apiToken = $_ENV['API_TOKEN']       ?? null;

if (!$ip || !$apiUrl || !$apiToken) {
    log_linea('ERROR: falta configurar DEVICE_IP, API_URL o API_TOKEN en .env (copiar .env.example).');
    exit(1);
}

$soloInfo = in_array('--info', $argv, true);

try {
    $zk = new ZKTeco($ip, $puerto, false, 15, $password);

    log_linea("Conectando a {$ip}:{$puerto}...");
    if (!$zk->connect()) {
        log_linea('ERROR: no se pudo conectar al dispositivo. Revisar IP/puerto/red.');
        exit(1);
    }

    log_linea('Conectado. Dispositivo: ' . $zk->deviceName() . ' | Serie: ' . $zk->serialNumber() . ' | Firmware: ' . $zk->fmVersion());

    if ($soloInfo) {
        $usuarios = $zk->getUsers();
        log_linea('Usuarios enrolados en el reloj: ' . count($usuarios));
        foreach (array_slice($usuarios, 0, 10) as $u) {
            log_linea('  - ' . json_encode($u));
        }
        $zk->disconnect();
        exit(0);
    }

    $marcasCrudas = $zk->getAttendances();
    log_linea('Marcaciones leídas del reloj: ' . count($marcasCrudas));

    $zk->disconnect();

    // Solo se suben las marcaciones mas nuevas que la ultima sincronizacion
    // exitosa (el reloj guarda TODO su historial en cada lectura). El
    // backend igual deduplica por su cuenta, esto es solo para no reenviar
    // miles de registros viejos en cada corrida.
    $marcaFile   = __DIR__ . '/ultima_sincronizacion.txt';
    $desdeUnix   = is_file($marcaFile) ? (int) trim(file_get_contents($marcaFile)) : 0;
    $maxUnixVisto = $desdeUnix;

    $marcaciones = [];
    foreach ($marcasCrudas as $m) {
        $recordTime = $m['record_time'] ?? null;
        if ($recordTime === null) {
            continue;
        }
        $unix = is_numeric($recordTime) ? (int) $recordTime : strtotime((string) $recordTime);
        if ($unix === false || $unix <= $desdeUnix) {
            continue;
        }
        $maxUnixVisto = max($maxUnixVisto, $unix);

        $marcaciones[] = [
            'codigo_biometrico' => (string) $m['user_id'],
            'fecha'             => date('Y-m-d', $unix),
            'hora'              => date('H:i:s', $unix),
            'device_ip'         => $ip,
        ];
    }

    log_linea('Marcaciones nuevas desde la última corrida: ' . count($marcaciones));

    if (empty($marcaciones)) {
        log_linea('Nada nuevo que subir.');
        exit(0);
    }

    // Se suben en lotes para no mandar un solo POST gigante si hay muchas.
    $lotes = array_chunk($marcaciones, 200);
    $totalInsertadas = 0;

    foreach ($lotes as $i => $lote) {
        $respuesta = enviarLote($apiUrl, $apiToken, $lote);
        if ($respuesta === null) {
            log_linea("ERROR subiendo el lote " . ($i + 1) . " de " . count($lotes) . ". Se detiene sin actualizar la marca de ultima sincronizacion.");
            exit(1);
        }
        $totalInsertadas += $respuesta['insertadas'] ?? 0;
        if (!empty($respuesta['sin_mapear'])) {
            log_linea('AVISO: códigos biométricos sin empleado asignado: ' . implode(', ', array_unique($respuesta['sin_mapear'])));
        }
    }

    file_put_contents($marcaFile, (string) $maxUnixVisto);
    log_linea("Listo. {$totalInsertadas} marcación(es) nueva(s) insertada(s) en el sistema.");
} catch (\Throwable $e) {
    log_linea('ERROR: ' . $e->getMessage());
    exit(1);
}

function enviarLote(string $apiUrl, string $apiToken, array $marcaciones): ?array
{
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Asistencia-Token: ' . $apiToken,
        ],
        CURLOPT_POSTFIELDS => json_encode(['marcaciones' => $marcaciones]),
    ]);

    $body    = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $status >= 400) {
        log_linea("Fallo HTTP (status {$status}): " . ($curlErr ?: $body));
        return null;
    }

    return json_decode($body, true);
}
