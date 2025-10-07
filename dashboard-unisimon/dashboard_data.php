<?php
include 'db.php';
$filtro = isset($_GET['estado']) ? $_GET['estado'] : '';

$sql = "SELECT s.*, e.nombre_estado 
        FROM sesiones s 
        LEFT JOIN estados e ON e.id_estado = s.id_estado_fk";
if ($filtro) $sql .= " WHERE e.nombre_estado = '" . $conn->real_escape_string($filtro) . "'";
$sql .= " ORDER BY s.id DESC";

$result = $conn->query($sql);

echo '<table class="table table-hover align-middle">';
echo '<thead><tr><th>ID</th><th>Usuario</th><th>Inicio</th><th>Fin Prog.</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
if ($result->num_rows > 0) {
    while ($r = $result->fetch_assoc()) {
        echo "<tr>
            <td>{$r['id']}</td>
            <td>{$r['username']}</td>
            <td>{$r['fecha_inicio']}</td>
            <td>{$r['fecha_final_programada']}</td>
            <td><span class='badge badge-{$r['nombre_estado']}'>" . htmlspecialchars($r['nombre_estado']) . "</span></td>
            <td>
                <button class='btn btn-sm btn-outline-info' onclick='verInfo({$r['id']})'>🔍</button>
                <button class='btn btn-sm btn-outline-success' onclick='ejecutarAccion({$r['id']}, \"renovar\")'>♻️</button>
                <button class='btn btn-sm btn-outline-warning' onclick='ejecutarAccion({$r['id']}, \"finalizar\")'>⛔</button>
                <button class='btn btn-sm btn-outline-danger' onclick='ejecutarAccion({$r['id']}, \"bloquear\")'>🚫</button>
            </td>
        </tr>";
    }
} else {
    echo "<tr><td colspan='6' class='text-center text-muted'>Sin registros</td></tr>";
}
echo '</tbody></table>';
?>
