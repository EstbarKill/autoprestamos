<?php
// =============================
// 📋 CONFIGURACIÓN BÁSICA
// =============================
date_default_timezone_set('America/Bogota');
header('Content-Type: application/json; charset=utf-8');

// Ruta donde se guardarán las respuestas
$archivo = __DIR__ . '/respuestas.csv';

// =============================
// 🧾 CAPTURA DE DATOS
// =============================
$fecha = date("Y-m-d H:i:s");
$vinculacion = $_POST['vinculacion'] ?? '';
$programa = $_POST['programa'] ?? '';
$dependencia = $_POST['dependencia'] ?? '';
$biblioteca = $_POST['biblioteca'] ?? '';
$espacio = $_POST['espacio'] ?? '';
$horario = $_POST['horario'] ?? '';
$atencion = $_POST['atencion'] ?? '';
$tiempo = $_POST['tiempo'] ?? '';
$iluminacion = $_POST['iluminacion'] ?? '';
$ventilacion = $_POST['ventilacion'] ?? '';
$discapacidad = $_POST['discapacidad'] ?? '';
$satisfaccion = $_POST['satisfaccion'] ?? '';
$observacion = trim(str_replace(array("\r","\n"), ' ', $_POST['observacion'] ?? ''));

// =============================
// 🔐 LIMPIEZA BÁSICA DE DATOS
// =============================
function limpiar($texto) {
    $texto = str_replace('"', '""', $texto); // Escapar comillas dobles
    $texto = preg_replace('/\s+/', ' ', $texto); // Quitar espacios múltiples
    return trim($texto);
}

// Limpiar todos los campos
$fecha = limpiar($fecha);
$vinculacion = limpiar($vinculacion);
$programa = limpiar($programa);
$dependencia = limpiar($dependencia);
$biblioteca = limpiar($biblioteca);
$espacio = limpiar($espacio);
$horario = limpiar($horario);
$atencion = limpiar($atencion);
$tiempo = limpiar($tiempo);
$iluminacion = limpiar($iluminacion);
$ventilacion = limpiar($ventilacion);
$discapacidad = limpiar($discapacidad);
$satisfaccion = limpiar($satisfaccion);
$observacion = limpiar($observacion);

// =============================
// 📊 CREACIÓN DEL ARCHIVO CSV
// =============================
if (!file_exists($archivo)) {
    $encabezado = [
        'Fecha',
        'Vinculación',
        'Programa',
        'Dependencia',
        'Biblioteca',
        'Espacio',
        'Horario',
        'Atención del Personal',
        'Tiempo de Espera',
        'Iluminación',
        'Ventilación',
        'Accesibilidad para Discapacidad',
        'Satisfacción General',
        'Observaciones'
    ];
    file_put_contents($archivo, '"' . implode('","', $encabezado) . '"' . "\n", FILE_APPEND | LOCK_EX);
}

// =============================
// ✏️ GUARDAR REGISTRO
// =============================
$linea = [
    $fecha,
    $vinculacion,
    $programa,
    $dependencia,
    $biblioteca,
    $espacio,
    $horario,
    $atencion,
    $tiempo,
    $iluminacion,
    $ventilacion,
    $discapacidad,
    $satisfaccion,
    $observacion
];

file_put_contents($archivo, '"' . implode('","', $linea) . '"' . "\n", FILE_APPEND | LOCK_EX);

// =============================
// ✅ RESPUESTA
// =============================
echo json_encode(['status' => 'ok', 'mensaje' => 'Registro guardado correctamente']);
?>
