<?php
include '../config/db.php';
header('Content-Type: application/json');

// Obtener parámetro de filtro por punto de servicio
$id_p_servicio = isset($_GET['id_p_servicio']) ? (int)$_GET['id_p_servicio'] : null;

// Construir query con LEFT JOIN a equipos para obtener nombre_pc
// Incluir id_p_servicio del equipo para permitir filtrado en el cliente
$sql = "SELECT s.id, s.username, s.id_equipo_fk, eq.nombre_pc, eq.id_p_servicio_fk AS id_p_servicio, s.fecha_inicio, s.fecha_final_programada, s.fecha_final_real, e.nombre_estado
        FROM sesiones s
        LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
        LEFT JOIN estados e ON e.id_estado = s.id_estado_fk";

// Si se especifica id_p_servicio, filtrar por ese punto de servicio
if ($id_p_servicio) {
    $sql .= " WHERE eq.id_p_servicio_fk = ?";
}

$sql .= " ORDER BY s.fecha_inicio DESC LIMIT 200";

$out = [];
if ($id_p_servicio) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id_p_servicio);
    $stmt->execute();
    $q = $stmt->get_result();
} else {
    $q = $conn->query($sql);
}

while ($r = $q->fetch_assoc()) {
    $out[] = $r;
}
echo json_encode($out);
?>