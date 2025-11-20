<?php
// status.php

// Constantes de estado (pueden ser globales)
const ESTADO_FINALIZADO = 1;
const ESTADO_ABIERTO    = 2;
const ESTADO_SUSPENDIDO = 3;
const ESTADO_BLOQUEADO  = 4;
const ESTADO_HIBERNANDO  = 5;
const ESTADO_COMANDO_SHELL = 6;

function actualizarEstado($conn, $sesionId, $nuevoEstado, $fechaFinal = null) {
    if ($fechaFinal) {
        $stmt = $conn->prepare("UPDATE sesiones SET id_estado_fk=?, fecha_final_real=? WHERE id=?");
        $stmt->bind_param("isi", $nuevoEstado, $fechaFinal, $sesionId);
    } else {
        $stmt = $conn->prepare("UPDATE sesiones SET id_estado_fk=? WHERE id=?");
        $stmt->bind_param("ii", $nuevoEstado, $sesionId);
    }
    $stmt->execute();
}

function crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo) {
    $estadoInicial = ESTADO_ABIERTO;
    $stmt = $conn->prepare("
        INSERT INTO sesiones (user_id, username, id_equipo_fk, fecha_inicio, fecha_final_programada, fecha_final_real, id_estado_fk)
        VALUES (?, ?, ?, NOW(), NOW() + INTERVAL ? SECOND, NULL, ?)
    ");
    $stmt->bind_param("ssiii", $userId, $username_full, $id_equipo, $intervaloTiempo, $estadoInicial);
    $stmt->execute();
    return $conn->insert_id;
}