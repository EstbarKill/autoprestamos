<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/jwt_dashboard.php';

$sede = $_POST['sede'] ?? $_GET['sede'] ??null;

if (!$sede) {
    echo json_encode([
        "status" => "error",
        "mensaje" => "No se envió la sede para generar token."
    ]);
    exit;
}

$username = "dashboard";

echo json_encode([
    "status" => "ok",
    "token"  => generarTokenDashboardRaw($username, $sede)
]);
?>