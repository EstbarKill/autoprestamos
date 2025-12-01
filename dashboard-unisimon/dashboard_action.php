<?php
// dashboard_action.php - VERSIÓN MEJORADA
include '../config/db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !isset($input['accion'])) {
    echo json_encode(["status" => "error", "mensaje" => "Solicitud inválida"]);
    exit;
}

$tipo = $input['accion'];
$id = isset($input['id']) ? (int)$input['id'] : null;
$nombre_pc = isset($input['nombre_pc']) ? $input['nombre_pc'] : null;

// Registrar log de la acción
function registrarLog($idEquipo, $accion, $mensaje) {
    global $conn;
    try {
        $sql = "INSERT INTO logs_acciones (id_equipo, accion, mensaje, fecha) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $idEquipo, $accion, $mensaje);
        $stmt->execute();
    } catch (Exception $e) {
        // Ignorar errores de logging
    }
}

switch ($tipo) {
    case 'comando':
        if (!$id || !isset($input['comando'])) { 
            echo json_encode(["status"=>"error","mensaje"=>"ID y comando requeridos"]); 
            exit; 
        }
        $comando = $input['comando'];
        // Si llega nombre_pc, lo registramos en logs
        $mensaje = "Comando '$comando' solicitado para la sesión $id";
        if ($nombre_pc) $mensaje .= " (destino: $nombre_pc)";
        registrarLog($nombre_pc ?? $id, 'comando', $mensaje);
        // Aquí no ejecutamos WS; dashboard envía WS directamente
        echo json_encode(["status"=>"ok","mensaje"=>$mensaje]);
        break;
    case "renovar":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "UPDATE sesiones SET id_estado_fk = 2, fecha_final_programada = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i',$id);
        if ($stmt->execute()) {
            $mensaje = "Sesión $id renovada";
            registrarLog($nombre_pc ?? $id, 'renovar', $mensaje);
            echo json_encode(["status"=>"ok","mensaje"=>$mensaje]);
        } else {
            echo json_encode(["status"=>"error","mensaje"=>"Error SQL: ".$stmt->error]);
        }
        break;

    case "finalizar":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "UPDATE sesiones SET id_estado_fk = 1, fecha_final_real = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i',$id);
        if ($stmt->execute()) {
            $mensaje = "Sesión $id finalizada (registro DB)";
            registrarLog($nombre_pc ?? $id, 'finalizar', $mensaje);
            echo json_encode(["status"=>"ok","mensaje"=>$mensaje]);
        } else {
            echo json_encode(["status"=>"error","mensaje"=>"Error SQL: ".$stmt->error]);
        }
        break;
    case "bloquear":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "UPDATE sesiones SET id_estado_fk = 4 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i',$id);
        if ($stmt->execute()) {
            $mensaje = "Sesión $id bloqueada";
            registrarLog($nombre_pc ?? $id, 'bloquear', $mensaje);
            echo json_encode(["status"=>"ok","mensaje"=>$mensaje]);
        } else {
            echo json_encode(["status"=>"error","mensaje"=>"Error SQL: ".$stmt->error]);
        }
        break;

    case "suspender":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "UPDATE sesiones SET id_estado_fk = 3 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i',$id);
        if ($stmt->execute()) {
            $mensaje = "Sesión $id suspendida";
            registrarLog($nombre_pc ?? $id, 'suspender', $mensaje);
            echo json_encode(["status"=>"ok","mensaje"=>$mensaje]);
        } else {
            echo json_encode(["status"=>"error","mensaje"=>"Error SQL: ".$stmt->error]);
        }
        break;

    case "info":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        // Incluir nombre_pc desde la tabla equipos si existe la relación
        $sql = "SELECT s.*, e.nombre_estado, eq.nombre_pc FROM sesiones s 
                LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
                WHERE s.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        echo json_encode(["status" => "ok", "mensaje" => "Información obtenida", "data" => $data]);
        break;

    default:
        echo json_encode(["status" => "error", "mensaje" => "Acción desconocida"]);
        exit;
}