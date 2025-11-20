<?php
// functions.php - helpers para server.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/jwt.php'; // jwt unificado

// Hacer global la conexión mysqli ($conn) disponible como $db
if (isset($conn) && $conn instanceof mysqli) {
    $GLOBALS['db'] = $conn;
} else {
    // Si no existe, intentar tomar $GLOBALS['db'] ya disponible
    if (!isset($GLOBALS['db'])) {
        // dejar que las funciones detecten y logueen la ausencia
        $GLOBALS['db'] = null;
    }
}

function logToFile($msg)
{
    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    $f = $logsDir . '/autoprestamo.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}

function sendJson($conn, $obj)
{
    try {
        $conn->send(json_encode($obj));
    } catch (\Exception $e) {
        logToFile("sendJson error: " . $e->getMessage());
    }
}

/**
 * Buscar equipo en la DB
 * - normaliza mac (formato DB: XX-XX-XX-XX-XX-XX)
 */
function buscarEquipoEnDB($nombrePc, $macRAW)
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        logToFile("❌ ERROR: Conexión DB no disponible en buscarEquipoEnDB");
        return null;
    }

    // Normalizar MAC: remover separadores y crear formato XX-XX...
    $mac = strtoupper(str_replace([':', '-', ' '], '', $macRAW));
    if (strlen($mac) === 12) {
        $macFormateada = implode('-', str_split($mac, 2)); // E4-A8-DF-9B-0E-76
    } else {
        $macFormateada = $macRAW; // fallback: usar lo que venga
    }

    $sql = "
        SELECT 
            e.id_equipo,
            e.nombre_pc,
            e.mac_equipo,
            e.ip_equipo,
            e.barcode_equipo,
            e.folio_item_barcode,
            e.id_p_servicio_fk,
            ps.nombre_p_servicio
        FROM equipos e
        INNER JOIN puntos_servicios ps 
            ON ps.id_p_servicio = e.id_p_servicio_fk
        WHERE e.nombre_pc = ? OR e.mac_equipo = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        logToFile("❌ Error prepare buscarEquipoEnDB: " . $db->error);
        return null;
    }

    $stmt->bind_param("ss", $nombrePc, $macFormateada);
    $stmt->execute();
    $res = $stmt->get_result();
    $equipo = $res->fetch_assoc();
    $stmt->close();

    if ($equipo) {
        logToFile("🔍 Equipo detectado en DB: {$equipo['nombre_pc']} | MAC: {$equipo['mac_equipo']} | Sede: {$equipo['nombre_p_servicio']}");
    } else {
        logToFile("🔍 Equipo NO encontrado: {$nombrePc} | MAC buscada: {$macFormateada}");
    }

    return $equipo ?: null;
}

/**
 * registerClient
 * - NO registra dashboards en serverState. Devuelve un array con result.
 * - El caller (server.php) decide dónde poner al cliente en memoria.
 *
 * Retorna:
 *  ['success'=>bool, 'tipo'=>'dashboard'|'equipo', 'payload'=>array, 'mensaje'=>string]
 */
function registerClient($conn, $data, &$serverState)
{
    $origen = $data['origen'] ?? null;
    if (!in_array($origen, ['dashboard', 'equipo'])) {
        return ['success' => false, 'mensaje' => 'Origen inválido'];
    }

    // ==== DASHBOARD ====
    if ($origen === 'dashboard') {

        $token = $data['token'] ?? null;
        if (!$token) {
            return ['success' => false, 'mensaje' => 'Token no enviado para dashboard'];
        }

        $payload = validarTokenDashboard($token);
        if (!$payload) {
            return ['success' => false, 'mensaje' => 'Token dashboard inválido'];
        }

        // construir response payload
        $resultPayload = [
            'usuario' => $payload['usuario'] ?? null,
            'sede'    => isset($payload['sede']) ? (int)$payload['sede'] : null,
            'id_p_servicio_enviado' => isset($data['id_p_servicio']) ? (int)$data['id_p_servicio'] : null,
            'nombre_equipo' => $data['nombre_equipo'] ?? null
        ];

        logToFile("Registro request DASHBOARD por usuario {$resultPayload['usuario']} - sede token: {$resultPayload['sede']}");

        return [
            'success' => true,
            'tipo' => 'dashboard',
            'payload' => $resultPayload,
            'mensaje' => 'Dashboard validado'
        ];
    }

    // ==== EQUIPO ====
    if ($origen === 'equipo') {

        $nombre = $data['nombre_equipo'] ?? null;
        $mac    = $data['mac_address'] ?? null;

        if (!$nombre || !$mac) {
            return ['success' => false, 'mensaje' => 'Faltan parámetros (nombre_equipo o mac_address)'];
        }

        // Buscar equipo real en la DB
        $equipo = buscarEquipoEnDB($nombre, $mac);
        if (!$equipo) {
            return [
                'success' => false,
                'mensaje' => 'Equipo no registrado en la base de datos',
                'debug' => ['nombre' => $nombre, 'mac' => $mac]
            ];
        }

        // Generar token equipo (servidor confía en DB)
        $tokenEquipo = generarTokenEquipo($equipo['nombre_pc'], $equipo['mac_equipo'], (int)$equipo['id_p_servicio_fk']);

        $resultPayload = [
            'id_equipo' => (int)$equipo['id_equipo'],
            'nombre_pc' => $equipo['nombre_pc'],
            'mac_equipo' => $equipo['mac_equipo'],
            'id_p_servicio' => (int)$equipo['id_p_servicio_fk'],
            'nombre_p_servicio' => $equipo['nombre_p_servicio'],
            'token_equipo' => $tokenEquipo
        ];

        logToFile("Registro request EQUIPO valido: {$equipo['nombre_pc']} | sede_id: {$equipo['id_p_servicio_fk']}");

        return [
            'success' => true,
            'tipo' => 'equipo',
            'payload' => $resultPayload,
            'mensaje' => 'Equipo validado'
        ];
    }

    return ['success' => false, 'mensaje' => 'Origen no manejado'];
}

/* broadcastToSede y cleanupClient (sin cambios funcionales) */
function broadcastToSede(&$serverState, $sede, $payload, $filtroNombrePc = null) {
    foreach ($serverState['equipos'] as $k => $eqConn) {
        if ((int)$eqConn->id_p_servicio !== (int)$sede) continue;
        if ($filtroNombrePc) {
            if (strcasecmp($eqConn->idCliente, $filtroNombrePc) !== 0) continue;
        }
        try { $eqConn->send(json_encode($payload)); } catch (\Exception $e) {}
    }
}


function cleanupClient($conn, &$serverState)
{
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
    } catch (\Exception $e) {
        logToFile("cleanupClient error: " . $e->getMessage());
    }
    logToFile("Cliente desconectado: ({$conn->resourceId})");
}
?>
