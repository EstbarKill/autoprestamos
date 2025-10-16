<?php   
// iniciar_server.php

// Verificar si el servidor WebSocket ya está corriendo
$pidFile = __DIR__ . '/server.pid'; // Ruta del archivo que almacena el PID del proceso

if (file_exists($pidFile)) {
    // Si el archivo de PID existe, el servidor probablemente ya está en ejecución
    $pid = file_get_contents($pidFile);
    if (posix_kill($pid, 0)) {
        // Si el proceso con ese PID está en ejecución, no hacemos nada
        echo json_encode(['status' => 'error', 'message' => 'El servidor WebSocket ya está corriendo.']);
        exit;
    }
}

// Iniciar el servidor WebSocket en segundo plano usando un proceso PHP
$command = "php " . __DIR__ . "/server.php > /dev/null 2>&1 & echo $!";
$pid = shell_exec($command); // Ejecutamos el comando y obtenemos el PID del proceso

// Guardamos el PID en un archivo para futuras verificaciones
file_put_contents($pidFile, $pid);

// Devolver una respuesta de éxito
echo json_encode(['status' => 'ok', 'message' => 'Servidor WebSocket iniciado correctamente.']);

?>