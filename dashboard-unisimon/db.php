<?php
// db.php - conexión única para todo el sistema
$host = "localhost";
$user = "root";
$pass = "";
$db   = "autoprestamo";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("❌ Error en la conexión a la BD: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4"); // para evitar errores de acentos
?>
