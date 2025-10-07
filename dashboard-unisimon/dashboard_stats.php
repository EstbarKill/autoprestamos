<?php
include './db.php';
$data = [
    "Abiertas" => 0,
    "Suspendidas" => 0,
    "Bloqueadas" => 0,
    "Finalizadas" => 0
];
$q = $conn->query("SELECT e.nombre_estado, COUNT(*) AS total
                   FROM sesiones s
                   LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                   GROUP BY e.nombre_estado");
while ($r = $q->fetch_assoc()) {
    switch (strtolower($r['nombre_estado'])) {
        case 'abierto': $data['abiertas'] = $r['total']; break;
        case 'suspendido': $data['suspendidas'] = $r['total']; break;
        case 'bloqueado': $data['bloqueadas'] = $r['total']; break;
        case 'finalizado': $data['finalizadas'] = $r['total']; break;
    }
}
echo json_encode($data);
?>
