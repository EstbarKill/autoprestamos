<?php
$host = "localhost";      // Servidor de MySQL
$user = "root";           // Usuario de MySQL (ajústalo si tienes otro)
$pass = "";               // Contraseña (ajústala)
$db   = "autoprestamo"; // Nombre de la base de datos

$conn = new mysqli($host, $user, $pass, $db);

// Validar conexión
if ($conn->connect_error) {
    die("Error en la conexión a la BD: " . $conn->connect_error);
}
?>
