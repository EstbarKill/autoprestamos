<?php
header('Content-Type: application/json');
set_time_limit(8); // evita bloqueos largos

$pidFile = __DIR__ . '/server.pid';
$sessionFile = __DIR__ . '/server_session.json';
$logFile = __DIR__ . '/server.log';
$serverPort = 8081;

// 🧠 Logs
function logToFile($msg) {
    global $logFile;
    $ts = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

// 🔍 Verificar si un proceso está vivo
function isProcessRunning($pid) {
    exec("tasklist /FI \"PID eq $pid\"", $out, $code);
    return ($code === 0 && strpos(implode("\n", $out), (string)$pid) !== false);
}

// 📂 Sesión
function guardarSesion($data) {
    global $sessionFile;
    file_put_contents($sessionFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ⚙️ 1️⃣ Si ya hay PID activo, no reiniciar
if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));
    if (isProcessRunning($pid)) {
        logToFile("⚡ El servidor ya está activo (PID: $pid)");
        echo json_encode(["status" => "ya_corriendo", "pid" => $pid]);
        exit;
    }
    unlink($pidFile);
    logToFile("🧹 Eliminado PID obsoleto ($pid)");
}

// ⚙️ 2️⃣ Comprobar si el puerto ya está ocupado
$socket = @fsockopen("127.0.0.1", $serverPort, $errno, $errstr, 1);
if ($socket) {
    fclose($socket);
    logToFile("⚠️ Puerto $serverPort en uso, abortando inicio.");
    echo json_encode(["status" => "puerto_ocupado", "mensaje" => "⚠️ Puerto $serverPort ya en uso."]);
    exit;
}

// 🚀 3️⃣ Intentar iniciar el servidor en segundo plano
$command = 'powershell -command "Start-Process php -ArgumentList ' . "'" . __DIR__ . '\\server.php' . "'" . ' -WindowStyle Hidden"';
logToFile("🚀 Ejecutando: $command");
logToFile("ANTES exec...");
exec($command);
logToFile("DESPUES exec...");
usleep(900000); // 0.9s


// 🔍 4️⃣ Buscar PID con respaldo doble (wmic y tasklist)
exec('powershell -command "Get-CimInstance Win32_Process |
    Where-Object { $_.CommandLine -like \'*server.php*\' } |
    Select-Object -ExpandProperty ProcessId"', $out);

$pid = trim($out[0] ?? '');

foreach ($out as $line) {
    if (preg_match('/\d+/', $line, $m)) {
        $pid = $m[0];
        break;
    }
}

// Si no encontró PID, probar con tasklist
if (empty($pid)) {
    exec('tasklist /FI "IMAGENAME eq php.exe"', $list);
    foreach ($list as $line) {
        if (stripos($line, 'php.exe') !== false) {
            $pid = preg_replace('/\D/', '', $line);
            break;
        }
    }
}

// 🩺 5️⃣ Verificar si el servidor responde por puerto
$serverActivo = false;
for ($i = 0; $i < 4; $i++) {
    $sock = @fsockopen("127.0.0.1", $serverPort, $errno, $errstr, 1);
    if ($sock) {
        fclose($sock);
        $serverActivo = true;
        break;
    }
    usleep(500000);
}

if ($serverActivo) {
    if (!empty($pid)) file_put_contents($pidFile, $pid);

    $token = bin2hex(random_bytes(8));
    $inicio = time();

    $sesion = [
        "pid" => $pid ?: "N/A",
        "token" => $token,
        "inicio" => date("Y-m-d H:i:s", $inicio),
        "ip" => getHostByName(getHostName()),
        "estado" => "activo",
        "puerto" => $serverPort,
        "ruta" => __DIR__ . "\\server.php"
    ];
    guardarSesion($sesion);

    logToFile("✅ Servidor iniciado correctamente (PID: {$sesion['pid']})");

    echo json_encode([
        "status" => "iniciado",
        "mensaje" => "✅ Servidor iniciado correctamente.",
        "pid" => $sesion['pid'],
        "token" => $token
    ]);
    exit;
}

// ❌ 6️⃣ Si llega aquí, algo falló
logToFile("❌ Falló el arranque. Sin PID ni respuesta del puerto $serverPort.");
echo json_encode([
    "status" => "error",
    "mensaje" => "❌ No se detectó el servidor. Verifica permisos o revisa server.log."
]);
exit;
?>
