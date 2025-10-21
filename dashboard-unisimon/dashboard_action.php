<?php
// dashboard_action.php
include 'db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !isset($input['accion']) || !isset($input['id'])) {
    echo json_encode(["status" => "error", "mensaje" => "Solicitud inválida"]);
    exit;
}

$id = (int)$input['id'];
$accion = $input['accion'];
$respuesta = ["status" => "ok", "accion" => $accion];
$mensaje = "";

switch ($accion) {
    case "renovar":
        $sql = "UPDATE sesiones SET id_estado_fk = 2, fecha_final_programada = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE id = ?";
        $mensaje = "Sesión renovada";
        break;
    case "finalizar":
        $sql = "UPDATE sesiones SET id_estado_fk = 1, fecha_final_real = NOW() WHERE id = ?";
        $mensaje = "Sesión finalizada";
        break;
    case "bloquear":
        $sql = "UPDATE sesiones SET id_estado_fk = 4 WHERE id = ?";
        $mensaje = "Sesión bloqueada";
        break;
    case "suspender":
        $sql = "UPDATE sesiones SET id_estado_fk = 3 WHERE id = ?";
        $mensaje = "Sesión suspendida";
        break;
    default:
        echo json_encode(["status" => "error", "mensaje" => "Acción desconocida"]);
        exit;
}
// Preparar la consulta SQL
$stmt = $conn->prepare($sql);
if ($accion === 'info') {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    echo json_encode(["status" => "ok", "mensaje" => $mensaje, "data" => $data]);
    exit;
} else {
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        // Notificar al WS server (llamada HTTP a un endpoint local)
        // Notificar cambios por WebSocket (puedes hacerlo aquí o mediante un sistema de notificación en tiempo real)
        $respuesta["mensaje"] = "Acción '$accion' aplicada correctamente";
        echo json_encode($respuesta);
    } else {
        $respuesta["status"] = "error";
        $respuesta["mensaje"] = "Error SQL: " . $conn->error;
        echo json_encode($respuesta);
    }
}
echo json_encode($respuesta);
