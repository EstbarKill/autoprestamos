<?php
header('Content-Type: application/json');

// Archivos de control
$pidFile = __DIR__ . '/server.pid';
$logFile = __DIR__ . '/server.log';

function logToFile($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] $msg\n", FILE_APPEND);
}

// 1️⃣ Verificar si existe PID guardado
if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));

    // 2️⃣ Verificar si el proceso con ese PID sigue vivo
    $output = [];
    exec("tasklist /FI \"PID eq $pid\" /FO LIST", $output);
    $outputStr = implode("\n", $output);

    if (stripos($outputStr, "php.exe") !== false) {
        // Proceso existe, servidor activo
        logToFile("✔️ Servidor WebSocket activo (PID $pid)");
        echo json_encode([
            "status" => "corriendo",
            "pid" => $pid,
            "mensaje" => "Servidor WebSocket está activo en segundo plano"
        ]);
        exit;
    } else {
        // PID muerto, limpiar
        unlink($pidFile);
        logToFile("🧹 Proceso no encontrado, PID eliminado ($pid)");
    }
}

// 3️⃣ Si no hay PID, buscar manualmente por puerto 8081
$output = [];
exec('netstat -ano | findstr :8081', $output);
$pidEncontrado = null;

foreach ($output as $linea) {
    if (preg_match('/\s+(\d+)$/', $linea, $m)) {
        $pidEncontrado = $m[1];
        break;
    }
}

// 4️⃣ Si se encontró PID por puerto
if ($pidEncontrado) {
    file_put_contents($pidFile, $pidEncontrado);
    logToFile("📡 Detectado proceso escuchando en 8081 (PID $pidEncontrado)");
    echo json_encode([
        "status" => "corriendo",
        "pid" => $pidEncontrado,
        "mensaje" => "Servidor WebSocket está escuchando en el puerto 8081"
    ]);
} else {
    logToFile("🔴 No se detectó ningún proceso activo en 8081");
    echo json_encode([
        "status" => "detenido",
        "mensaje" => "Servidor WebSocket no se está ejecutando"
    ]);
}
?>
