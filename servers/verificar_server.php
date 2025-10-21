<?php
// servers/verificar_server.php
header('Content-Type: application/json');

// Puerto del WebSocket
$puerto = 8081;

// Intentar abrir conexión al puerto local
$conexion = @fsockopen("localhost", $puerto, $errno, $errstr, 1);

if ($conexion) {
    fclose($conexion);
    echo json_encode([
        'status' => 'activo',
        'mensaje' => "El servidor está escuchando en el puerto $puerto"
    ]);
} else {
    echo json_encode([
        'status' => 'inactivo',
        'mensaje' => "No hay ningún proceso escuchando en el puerto $puerto"
    ]);
}
?>
