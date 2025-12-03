
<?php
header('Content-Type: application/json');

$pidFile = __DIR__ . '/server.pid';
$sessionFile = __DIR__ . '/server_session.json';
$logFile = __DIR__ . '/server.log';

// 🧠 Guardar logs con timestamp
function logToFile($message) {
    global $logFile;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// 🧩 Verificar si un proceso sigue activo (solo Windows)
function isProcessRunning($pid) {
    $output = null;
    $resultCode = null;
    exec("tasklist /fi \"PID eq $pid\"", $output, $resultCode);
    return ($resultCode === 0 && strpos(implode("\n", $output), (string)$pid) !== false);
}

// 📂 Leer sesión existente
function leerSesion() {
    global $sessionFile;
    if (!file_exists($sessionFile)) return null;
    $data = json_decode(file_get_contents($sessionFile), true);
    return is_array($data) ? $data : null;
}

// 💾 Guardar sesión modificada
function guardarSesion($data) {
    global $sessionFile;
    file_put_contents($sessionFile, json_encode($data, JSON_PRETTY_PRINT));
}

// 🚨 Verificar existencia de PID
if (!file_exists($pidFile)) {
    logToFile("❌ Intento de detener sin PID activo.");
    echo json_encode([
        "status" => "error",
        "mensaje" => "⚠️ No se encontró el archivo PID. El servidor ya podría estar apagado."
    ]);
    exit;
}

$pid = (int) file_get_contents($pidFile);
$sesion = leerSesion();

// 🧩 Validar proceso
if ($pid > 0 && isProcessRunning($pid)) {
    exec("taskkill /PID $pid /F", $output, $resultCode);

    if ($resultCode === 0) {
        unlink($pidFile);

        if ($sesion) {
            $sesion['estado'] = 'cerrado';
            $sesion['fin'] = date("Y-m-d H:i:s");
            $sesion['duracion_segundos'] = time() - strtotime($sesion['inicio']);
            guardarSesion($sesion);
        }

        logToFile("🟥 Servidor detenido correctamente (PID: $pid).");
        echo json_encode([
            "status" => "ok",
            "mensaje" => "🔴 Servidor detenido correctamente.",
            "pid" => $pid
        ]);
        exit;
    } else {
        logToFile("⚠️ Error al intentar detener el proceso con PID $pid.");
        echo json_encode([
            "status" => "error",
            "mensaje" => "❌ No se pudo detener el servidor."
        ]);
        exit;
    }
} else {
    // 🔧 Limpieza si el proceso no existe
    if (file_exists($pidFile)) unlink($pidFile);

    if ($sesion) {
        $sesion['estado'] = 'cerrado (forzado)';
        $sesion['fin'] = date("Y-m-d H:i:s");
        $sesion['nota'] = 'PID no encontrado, cierre forzado.';
        guardarSesion($sesion);
    }

    logToFile("⚠️ No se encontró proceso activo con PID $pid. Cierre forzado.");
    echo json_encode([
        "status" => "error",
        "mensaje" => "⚠️ No se encontró proceso activo. Se eliminó el PID antiguo."
    ]);
}
?>
