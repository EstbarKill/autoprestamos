<?php
// functions.php - helpers para server.php

// Usar el token específico del dashboard (validarTokenDashboard)
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../prueba_equipos/jwt_dashboard.php'; // aquí está validarTokenDashboard


function logToFile($msg) {
    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    $f = $logsDir . '/autoprestamo.log';
    $line = '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL;
    file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}

function sendJson($conn, $obj) {
    try {
        $conn->send(json_encode($obj));
    } catch(\Exception $e) {
        logToFile("sendJson error: " . $e->getMessage());
    }
}

/**
 * registerClient($conn, $data, &$serverState)
 * - $serverState debe ser un array con 'clients','equipos','dashboards'
 */
function registerClient($conn, $data, &$serverState) {
    $token = $data['token'] ?? null;
    $payload = null;

    if ($token) {
        $payload = validarTokenDashboard($token); // retorna payload o null
    }

    if (!$token || !$payload) {
        sendJson($conn, ['tipo'=>'error','mensaje'=>'Token inválido']);
        return false;
    }

    // insertar datos del payload al objeto de conexión
    $conn->usuario_token = $payload['usuario'] ?? null;
    $conn->sede_token    = $payload['sede'] ?? null;

    if (!isset($data['origen']) || !in_array($data['origen'], ['dashboard','equipo'])) {
        sendJson($conn, ['tipo'=>'error','mensaje'=>'Origen inválido']);
        return false;
    }

    $conn->tipoCliente = $data['origen'];

    // establecer id_p_servicio si fue enviado por el cliente (importante para dashboard/equipo)
    $conn->id_p_servicio = isset($data['id_p_servicio']) ? (int)$data['id_p_servicio'] : null;

    // Si es dashboard -> validar que la sede solicitada (si viene) coincide con la del token
    if ($conn->tipoCliente === 'dashboard') {
        // si token tiene 'sede' y el dashboard envió id_p_servicio, validar coincidencia
        if (!empty($conn->sede_token) && !empty($conn->id_p_servicio) && ((int)$conn->sede_token !== (int)$conn->id_p_servicio)) {
            sendJson($conn, ['tipo'=>'error','mensaje'=>'Sede no autorizada para este token']);
            return false;
        }
        // registrar dashboard
        $conn->nombre_equipo = $data['nombre_equipo'] ?? ('dash_'.$conn->resourceId);
        $serverState['dashboards'][$conn->resourceId] =& $conn;
        logToFile("Registro dashboard: {$conn->nombre_equipo} (sede token: {$conn->sede_token}, id_p_servicio: {$conn->id_p_servicio})");
    } else {
        // equipo
        $conn->idCliente = $data['nombre_equipo'] ?? ('eq_'.$conn->resourceId);
        $conn->id_p_servicio = isset($data['id_p_servicio']) ? (int)$data['id_p_servicio'] : null;
        $id_key = $conn->idCliente . "_sede_" . ($conn->id_p_servicio ?? '0');
        $serverState['equipos'][$id_key] = $conn;
        $conn->idGlobal = $id_key;
        logToFile("Registro equipo: {$conn->idCliente} (sede: {$conn->id_p_servicio})");
    }

    // Attach to clients storage if not already
    if (!($serverState['clients']->contains($conn))) {
        $serverState['clients']->attach($conn);
    }

    sendJson($conn, ['tipo'=>'confirmacion_registro','origen'=>'server','nombre_eq'=>$conn->nombre_equipo ?? $conn->idCliente]);
    return true;
}

function broadcastToSede($serverState, $sedeId, $msg) {
    if (!$sedeId) return;
    foreach ($serverState['clients'] as $c) {
        try {
            if (isset($c->id_p_servicio) && (int)$c->id_p_servicio === (int)$sedeId) {
                $c->send(json_encode($msg));
            }
        } catch(\Exception $e) {
            logToFile("broadcastToSede error: " . $e->getMessage());
        }
    }
}

function cleanupClient($conn, &$serverState) {
    try {
        if (isset($conn->idGlobal) && isset($serverState['equipos'][$conn->idGlobal])) {
            unset($serverState['equipos'][$conn->idGlobal]);
        }
        if (isset($serverState['dashboards'][$conn->resourceId])) {
            unset($serverState['dashboards'][$conn->resourceId]);
        }
        if ($serverState['clients']->contains($conn)) {
            $serverState['clients']->detach($conn);
        }
    } catch(\Exception $e) {
        logToFile("cleanupClient error: " . $e->getMessage());
    }
    logToFile("Cliente desconectado: ({$conn->resourceId})");
}
?>
