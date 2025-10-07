<?php
include '../prueba_equipos/db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !isset($input['accion']) || !isset($input['id'])) {
    echo json_encode(["status" => "error", "mensaje" => "Solicitud inválida"]);
    exit;
}

$id = (int)$input['id'];
$accion = $input['accion'];
$respuesta = ["status" => "ok", "accion" => $accion];

switch ($accion) {
    case "renovar":
        $sql = "UPDATE sesiones SET id_estado_fk = 2, 
                fecha_final_programada = DATE_ADD(NOW(), INTERVAL 1 MINUTE)
                WHERE id = $id";
        break;

    case "suspender":
        $sql = "UPDATE sesiones SET id_estado_fk = 3 WHERE id = $id";
        break;

    case "finalizar":
        $sql = "UPDATE sesiones SET id_estado_fk = 1, fecha_final_real = NOW() WHERE id = $id";
        break;

    case "bloquear":
        $sql = "UPDATE sesiones SET id_estado_fk = 4 WHERE id = $id";
        break;

    case "enviarMensaje":
        echo json_encode(["status" => "ok", "mensaje" => "💬 Mensaje simulado enviado a sesión $id"]);
        exit;

    default:
        echo json_encode(["status" => "error", "mensaje" => "Acción desconocida"]);
        exit;
}

if ($conn->query($sql)) {
    $respuesta["mensaje"] = "Acción '$accion' aplicada correctamente.";
} else {
    $respuesta["status"] = "error";
    $respuesta["mensaje"] = "Error SQL: " . $conn->error;
}

echo json_encode($respuesta);
?>
