<?php
include 'db.php';
header('Content-Type: application/json');

$q = $conn->query("SELECT s.id, s.usuario AS username, s.fecha_inicio, s.fecha_final_programada, e.nombre_estado
                   FROM sesiones s
                   LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                   ORDER BY s.fecha_inicio DESC
                   LIMIT 200");
$out = [];
while ($r = $q->fetch_assoc()) {
    $out[] = $r;
}
echo json_encode($out);
