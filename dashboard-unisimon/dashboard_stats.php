<?php
include './db.php';

$data = [
    "Abierto" => 0,
    "Suspendido" => 0,
    "Bloqueado" => 0,
    "Finalizado" => 0
];

$q = $conn->query("SELECT e.nombre_estado, COUNT(*) AS total
                   FROM sesiones s
                   LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                   GROUP BY e.nombre_estado");
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
