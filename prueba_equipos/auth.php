<?php
// auth.php
require '../config/db.php'; // Asegúrate de que este archivo exista y contenga la conexión a la base de datos

function validarUsuarioYEquipo($conn, $username, $token, $mac_address) {
    // Usuario en FOLIO
    $username_full = $username . "@unisimon.edu.co";
    $user = folioGetUser($username_full, $token);
    if (!$user) {
        jsonError("Usuario no encontrado en FOLIO");
    }

    $userId = $user['id'] ?? null;
    $userBarcode = $user['barcode'] ?? null;
    if (!$userBarcode) {
        jsonError("Usuario sin barcode FOLIO");
    }

    // Equipo: prioridad MAC, fallback IP
    $equipo = getEquipo($conn, $mac_address);
    if (!$equipo) {
        jsonError("Equipo no encontrado");
    }

    $id_equipo = intval($equipo['id_equipo']);
    $folio_item_barcode = $equipo['barcode_equipo'] ?? null;
    if (!$folio_item_barcode) {
        jsonError("Equipo sin barcode FOLIO");
    }

    return [
        'user' => $user,
        'userId' => $userId,
        'userBarcode' => $userBarcode,
        'equipo' => $equipo,
        'id_equipo' => $id_equipo,
        'folio_item_barcode' => $folio_item_barcode
    ];
}


// Funciones de ayuda relacionadas con la base de datos (se podría mover a 'db_helpers.php' si es muy grande)
function getEquipo($conn, $mac_address) {
    static $cache = [];
    $cacheKey = $mac_address;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    if ($mac_address) {
        // Buscar por MAC primero
        $stmt = $conn->prepare("SELECT * FROM equipos WHERE mac_equipo=? LIMIT 1");
        $stmt->bind_param("s", $mac_address);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            $cache[$cacheKey] = $result;
            return $result;
        }
    }
    return null;
}


function getUltimaSesion($conn, $userId, $id_equipo) {
    $stmt = $conn->prepare("SELECT * FROM sesiones WHERE user_id=? AND id_equipo_fk=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("si", $userId, $id_equipo);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}