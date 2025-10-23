<?php
// dashboard_action.php
include 'db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !isset($input['accion'])) {
    echo json_encode(["status" => "error", "mensaje" => "Solicitud inválida"]);
    exit;
}

$accion = $input['accion'];
$id = isset($input['id']) ? (int)$input['id'] : null;

switch ($accion) {
    case "renovar":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "UPDATE sesiones SET id_estado_fk = 2, fecha_final_programada = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE id = ?";
        $mensaje = "Sesión renovada";
        break;
    case "finalizar":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "UPDATE sesiones SET id_estado_fk = 1, fecha_final_real = NOW() WHERE id = ?";
        $mensaje = "Sesión finalizada";
        break;
    case "bloquear":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "UPDATE sesiones SET id_estado_fk = 4 WHERE id = ?";
        $mensaje = "Sesión bloqueada";
        break;
    case "suspender":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "UPDATE sesiones SET id_estado_fk = 3 WHERE id = ?";
        $mensaje = "Sesión suspendida";
        break;
    case "info":
        if (!$id) { echo json_encode(["status"=>"error","mensaje"=>"ID requerido"]); exit; }
        $sql = "SELECT s.*, e.nombre_estado FROM sesiones s LEFT JOIN estados e ON e.id_estado = s.id_estado_fk WHERE s.id = ?";
        break;
    default:
        echo json_encode(["status" => "error", "mensaje" => "Acción desconocida"]);
        exit;
}

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(["status" => "error", "mensaje" => "Error preparación SQL: " . $conn->error]);
    exit;
}
$stmt->bind_param('i', $id);

if (!$stmt->execute()) {
    echo json_encode(["status" => "error", "mensaje" => "Error SQL: " . $stmt->error]);
    exit;
}

if ($accion === 'info') {
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    echo json_encode(["status" => "ok", "mensaje" => $mensaje ?? "info", "data" => $data]);
    exit;
} else {
    // Opcional: notificar al WS server (si tu WS expone un endpoint HTTP /notify)
    // Descomenta y ajusta URL si tu servidor acepta POST HTTP para forzar broadcast.
    
    $notify = [
      "tipo" => "actualizacion",
      "accion" => $accion,
      "id" => $id
    ];
    $ch = curl_init("http://localhost:8081/notify");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notify));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    curl_close($ch);
    

    echo json_encode(["status" => "ok", "mensaje" => "Acción '$accion' aplicada correctamente"]);
    exit;
}

