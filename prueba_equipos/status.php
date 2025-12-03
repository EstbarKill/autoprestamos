<?php
// status.php

// Constantes de estado (pueden ser globales)
const ESTADO_FINALIZADO = 1;
const ESTADO_ABIERTO    = 2;
const ESTADO_SUSPENDIDO = 3;
const ESTADO_BLOQUEADO  = 4;
const ESTADO_HIBERNANDO  = 5;

function actualizarEstado(
    $conn,
    $sesionId,
    $nuevoEstado,
    $fechaFinal = null,
    $bloqueadoDesde = null,
    $bloqueadoHasta = null
) {
    $sql = "UPDATE sesiones SET id_estado_fk=?";
    $params = [$nuevoEstado];
    $types  = "i";

    // Si viene fecha final → registrar finalización real
    if ($fechaFinal !== null) {
        $sql .= ", fecha_final_real=?";
        $params[] = $fechaFinal;
        $types    .= "s";
    }

    // Si viene info de bloqueo → ponerlos
    if ($bloqueadoDesde !== null && $bloqueadoHasta !== null) {
        $sql .= ", bloqueado_desde=?, bloqueado_hasta=?";
        $params[] = $bloqueadoDesde;
        $params[] = $bloqueadoHasta;
        $types    .= "ss";
    }

    // 🔥 Limpieza automática si NO se está bloqueando
    if ($nuevoEstado != ESTADO_BLOQUEADO) {
        $sql .= ", bloqueado_desde=NULL, bloqueado_hasta=NULL";
    }

    $sql .= " WHERE id=?";
    $params[] = $sesionId;
    $types    .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
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