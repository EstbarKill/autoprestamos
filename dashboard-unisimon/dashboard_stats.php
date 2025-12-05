<?php
include '../config/db.php';
header('Content-Type: application/json');

// Obtener parámetro de filtro por punto de servicio
$id_p_servicio = isset($_GET['id_p_servicio_fk']) ? (int)$_GET['id_p_servicio_fk'] : null;

$data = [
    "Abierto" => 0,
    "Suspendido" => 0,
    "Bloqueado" => 0,
    "Finalizado" => 0
];

// Construir query base
$sql = "SELECT COALESCE(e.nombre_estado, 'Desconocido') AS nombre_estado, COUNT(*) AS total
        FROM sesiones s
        LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
        LEFT JOIN estados e ON e.id_estado = s.id_estado_fk";

// Si se especifica id_p_servicio, filtrar por ese punto de servicio
if ($id_p_servicio) {
    $sql .= " WHERE eq.id_p_servicio_fk = ?";
    $sql .= " GROUP BY e.nombre_estado";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id_p_servicio);
    $stmt->execute();
    $q = $stmt->get_result();
} else {
    $sql .= " GROUP BY e.nombre_estado";
    $q = $conn->query($sql);
}

while ($r = $q->fetch_assoc()) {
    $nombre = strtolower($r['nombre_estado']);
    $total = (int)$r['total'];
    if ($nombre === 'abierto') $data['Abierto'] = $total;
    if ($nombre === 'suspendido') $data['Suspendido'] = $total;
    if ($nombre === 'bloqueado') $data['Bloqueado'] = $total;
    if ($nombre === 'finalizado') $data['Finalizado'] = $total;
}

echo json_encode($data);
?>