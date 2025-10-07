<?php
// api.php
header('Content-Type: application/json');

// Incluir los archivos modulares
require 'db.php';
require 'tokenByron.php';
require 'utils.php';
require 'folio.php';
require 'auth.php';
require 'status.php';

date_default_timezone_set('America/Bogota');

// ================== CONFIGURACIÓN ==================
$intervaloTiempo  = 30; // ABIERTO (s)
$tiempoSuspendido = 30; // SUSPENDIDO (s)
$esperaBloqueo    = 10; // BLOQUEADO (s)
$claveCorrecta    = "S1m0n_2025";
$restringido      = false;

// ================== INPUT ==================
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$username       = $input['username'] ?? $_POST['username'] ?? $_GET['username'] ?? null;
$ip_address     = $input['ip_address'] ?? $_POST['ip_address'] ?? $_GET['ip_address'] ?? null;
$mac_address = $input['mac_address'] ?? $_POST['mac_address'] ?? $_GET['mac_address'] ?? null;
$clave_admin    = $input['clave_admin'] ?? $_POST['clave_admin']   ?? null;
$cancel_suspend = $input['cancel_suspend'] ?? $_POST['cancel_suspend']   ?? null;
$confirmar_inicio = $input['confirmar_inicio'] ?? $_POST['confirmar_inicio']   ?? null;
$empezar = $input['empezar'] ?? $_POST['empezar']   ?? null;


if (!$username || !$ip_address) jsonError("Faltan parámetros: username e ip_address");

// ================== FLUJO PRINCIPAL ==================
$authData = validarUsuarioYEquipo($conn, $username, $ip_address, $token, $mac_address);
$userBarcode = $authData['userBarcode'];
$id_equipo = $authData['id_equipo'];
$folio_item_barcode = $authData['folio_item_barcode'];
$userId = $authData['userId'];
$username_full = $authData['user']['username'];
$manualblock = folioManualBlock($userId, $token);
$autoblock   = folioAutoBlock($userId, $token);

if (!empty($manualblock) || !empty($autoblock)) {
    $detalles = [];

    if (!empty($manualblock)) {
        foreach ($manualblock as $b) {
            $msg = $b['desc']
                ?? $b['patronMessage']
                ?? $b['staffInformation']
                ?? ($b['expirationDate'] ?? null)
                ?? json_encode($b);
            $detalles[] = "Manual: " . $msg;
        }
    }

    if (!empty($autoblock)) {
        foreach ($autoblock as $b) {
            $msg = $b['message'] ?? json_encode($b);
            $detalles[] = "Automático: " . $msg;
        }
    }

    $restringido = true;

    jsonOk([
        "estado"  => "Restringido",
        "mensaje" => "El usuario tiene restricciones en FOLIO: " . implode("; ", $detalles),
        "bloqueos" => [
            "manuales"    => $manualblock,
            "automaticos" => $autoblock
        ]
    ]);
    exit;
}




$servicePointId = folioGetServicePoint($token);
if (!$servicePointId) jsonError("Service Point no disponible");

$loanAbierto = loanExists($token, $folio_item_barcode);
if ($loanAbierto) {
    jsonOk(["estado" => "Error", "mensaje" => "El equipo ya tiene un préstamo abierto en FOLIO. No se puede abrir una nueva sesión."]);
    exit;
}

$last = getUltimaSesion($conn, $userId, $id_equipo);
$now  = new DateTime('now', new DateTimeZone('America/Bogota'));
$estado = intval($last['id_estado_fk'] ?? null);
$fechaProg = isset($last['fecha_final_programada']) ? new DateTime($last['fecha_final_programada'], new DateTimeZone('America/Bogota')) : null;
if ($restringido == false) {
    if ($last) {
        switch ($estado) {
            case ESTADO_ABIERTO:
                if ($fechaProg && $now >= $fechaProg) {
                    actualizarEstado($conn, $last['id'], ESTADO_SUSPENDIDO);
                    jsonOk(["estado" => "Suspendido", "mensaje" => "Sesión suspendida automáticamente"]);
                    exit;
                }
                $tiempoRestante = $fechaProg ? ($fechaProg->getTimestamp() - $now->getTimestamp()) : $intervaloTiempo;
                jsonOk(["estado" => "Abierto", "mensaje" => "Sesión abierta en curso","tiempo_restante" => $tiempoRestante]);
                exit;
            case ESTADO_SUSPENDIDO:
                if ($clave_admin) {
                    if ($clave_admin == $claveCorrecta) {
                        actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO, $now->format('Y-m-d H:i:s'));
                        $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                        $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                        $checkoutResp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                        if (isset($checkoutResp['errors'])) {
                            foreach ($checkoutResp['errors'] as $err) {
                                if (($err['code'] ?? '') === "ITEM_HAS_OPEN_LOAN") {
                                    jsonOk(["estado" => "Error", "mensaje" => "El equipo ya tiene un préstamo abierto en FOLIO. No se puede abrir una nueva sesión."]);
                                    exit;
                                }
                            }
                        }
                        jsonOk(["estado" => "Renovado", "mensaje" => "Sesión renovada, nueva sesión abierta"]);
                        exit;
                    }
                } elseif ($cancel_suspend == "Cancelar" || $cancel_suspend || "Expirado" || $cancel_suspend == "Intentos") {
                    actualizarEstado($conn, $last['id'], ESTADO_BLOQUEADO, $now->format('Y-m-d H:i:s'));
                    $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                                $motivo = match ($cancel_suspend) {
                "Cancelar" => "Sesión cancelada → Bloqueada",
                "Expirado" => "Sesión expirada → Bloqueada",
                "Intentos" => "Demasiados intentos fallidos → Bloqueada",
                default => "Sesión bloqueada"
            };
            jsonOk(["estado" => "Bloqueado", "mensaje" => $motivo]);
            exit;
                } else {
                    $finSuspension = ($fechaProg ? clone $fechaProg : $now)->modify("+" . $tiempoSuspendido . " seconds");
                    if ($now >= $finSuspension) {
                        actualizarEstado($conn, $last['id'], ESTADO_BLOQUEADO, $now->format('Y-m-d H:i:s'));
                        $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                        jsonOk(["estado" => "Bloqueado", "mensaje" => "Usuario bloqueado por no reactivar a tiempo"]);
                        exit;
                    }
                }
                break;
            case ESTADO_BLOQUEADO:
                $finBloqueo = ($fechaProg ? clone $fechaProg : $now)->modify("+" . $esperaBloqueo . " seconds");
                if ($now >= $finBloqueo) {
                    actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO);
                    $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                    jsonOk(["estado" => "Finalizado", "mensaje" => "Sesión finalizada"]);
                    exit;
                }
                break;
            case ESTADO_FINALIZADO:
                $loanExist = loanExists($token, $folio_item_barcode);
                $seccion = getUltimaSesion($conn, $userId, $id_equipo);
                if (!$loanExist) {
                    if ($seccion) {
                        $seccion_stade = $seccion['id_estado_fk'];
                        if ($seccion_stade == ESTADO_FINALIZADO) {
                            if ($confirmar_inicio == "true") {
                                $checkoutResp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                                if (isset($checkoutResp['errors'])) {
                                    foreach ($checkoutResp['errors'] as $err) {
                                        if (($err['code'] ?? '') === "ITEM_HAS_OPEN_LOAN") {
                                            jsonOk(["estado" => "Error", "mensaje" => "El equipo ya tiene un préstamo abierto en FOLIO. No se puede abrir una nueva sesión."]);
                                            exit;
                                        }
                                    }
                                }
                                $seccion = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                                jsonOk(["estado" => "Abierto", "mensaje" => "Sesión iniciada correctamente"]);
                                exit;
                            } else {
                                jsonOk(["estado" => "Finalizado", "mensaje" => "Confirme que desea iniciar una nueva sesión"]);
                                exit;
                            }
                        }
                    }
                }
                if ($empezar == "true" && $estado == ESTADO_FINALIZADO) {
                    $checkOut_resp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                    $seccion = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                    jsonOk(["estado" => "Abierto", "mensaje" => "Su sesion comenzará en breve"]);
                }
                break;
            default:
                jsonOk(["estado" => "Error", "mensaje" => "Flujo no reconocido en estado Suspendido"]);
                exit;
        }
    } else {
        $checkout_resp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
        $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
        jsonOk(["estado" => "Abierto", "mensaje" => "Sesión iniciada correctamente"]);
    }
}
$conn->close();
