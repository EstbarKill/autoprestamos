<?php
// api.php
header('Content-Type: application/json');

// Incluir los archivos modulares
require '../config/db.php';
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
$mac_address = $input['mac_address'] ?? $input['mac_eq'] ?? $_POST['mac_address'] ?? $_GET['mac_address'] ?? null;
$clave_admin    = $input['clave_admin'] ?? $_POST['clave_admin']   ?? null;
$cancel_suspend = $input['cancel_suspend'] ?? $_POST['cancel_suspend']   ?? null;
$confirmar_inicio = $input['confirmar_inicio'] ?? $_POST['confirmar_inicio']   ?? null;
$empezar = $input['empezar'] ?? $_POST['empezar']   ?? null;
$tipo = $input['tipo'] ?? $_POST['tipo']   ?? null;
$user_comando  = $input['user_comando'] ?? $_POST['user_comando'] ?? null;
$accion = $input['accion'] ?? $_POST['accion'] ?? null;
$origen = $input['origen'] ?? $_POST['origen'] ?? null;


if (!$username || !$mac_address) jsonError("Faltan parámetros: username e mac_address");

// ================== FLUJO PRINCIPAL ==================
$authData = validarUsuarioYEquipo($conn, $username, $token, $mac_address);
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

switch ($tipo) {
    case 'control':
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
                        jsonOk(["estado" => "Abierto", "mensaje" => "Sesión abierta en curso", "tiempo_restante" => $intervaloTiempo]);
                        exit;
                        break;
                    case ESTADO_SUSPENDIDO:
                        $finSuspension = ($fechaProg ? clone $fechaProg : $now)->modify("+" . $tiempoSuspendido . " seconds");
                        if ($clave_admin) {
                            if ($clave_admin == $claveCorrecta) {
                                actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO, $now->format('Y-m-d H:i:s'),$finSuspension->format("Y-m-d H:i:s"));
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
                        } elseif ($cancel_suspend == "Cancelar" or $cancel_suspend or "Expirado" or $cancel_suspend == "Intentos") {
                            actualizarEstado(
                                $conn,
                                $sesionId,
                                ESTADO_BLOQUEADO,
                                null,
                                $now->format("Y-m-d H:i:s"),             // bloqueado_desde
                                $finSuspension->format("Y-m-d H:i:s")         // bloqueado_hasta
                            );
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
                            if ($now >= $finSuspension) {
                                actualizarEstado(
                                    $conn,
                                    $sesionId,
                                    ESTADO_BLOQUEADO,
                                    null,
                                    $now->format("Y-m-d H:i:s"),             // bloqueado_desde
                                    $finSuspension->format("Y-m-d H:i:s")         // bloqueado_hasta
                                );
                                $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                                jsonOk(["estado" => "Bloqueado", "mensaje" => "Usuario bloqueado por no reactivar a tiempo"]);
                                exit;
                            }
                        }
                        break;
                    case ESTADO_BLOQUEADO:

                        // 1. Tomamos la fecha en que inició el bloqueo
                        $bloqueadoDesde = isset($last['bloqueado_desde']) ? new DateTime($last['bloqueado_desde'], new DateTimeZone('America/Bogota')) : null;
                        $bloqueadoHasta = isset($last['bloqueado_hasta']) ? new DateTime($last['bloqueado_hasta'], new DateTimeZone('America/Bogota')) : null;
                        $finSuspension = ($fechaProg ? clone $fechaProg : $now)->modify("+" . $tiempoSuspendido . " seconds");
                        // Seguridad: si faltan datos del bloqueo, finalizamos de una vez
                        if ($bloqueadoDesde === null || $bloqueadoHasta === null) {
                            actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO, $finSuspension->format('Y-m-d H:i:s'));

                            jsonOk([
                                "estado" => "Finalizado",
                                "mensaje" => "La sesión estaba en bloqueo pero sin datos completos. Finalizada por seguridad."
                            ]);
                            exit;
                        }

                        // 2. Verificar si YA PASÓ el tiempo de bloqueo
                        if ($now >= $bloqueadoHasta) {

                            // Finalizar automáticamente
                            actualizarEstado(
                                $conn,
                                $last['id'],
                                ESTADO_FINALIZADO,
                                $now->format('Y-m-d H:i:s')
                            );

                            // Registrar checkin en FOLIO
                            $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);

                            jsonOk([
                                "estado" => "Finalizado",
                                "mensaje" => "Sesión finalizada automáticamente después de bloqueo."
                            ]);
                            exit;
                        }

                        // 3. Si aún está dentro del tiempo de bloqueo
                        $restante = $bloqueadoHasta->getTimestamp() - $now->getTimestamp();

                        jsonOk([
                            "estado" => "Bloqueado",
                            "tiempo_restante" => $restante,
                            "mensaje" => "Sesión bloqueada en cuenta regresiva."
                        ]);
                        exit;

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
        break;
    case 'comando_api':
        if ($origen == 'server') {
            // ============================================================
            // 🧾 Datos esperados desde server.php
            // ============================================================
            $accion       = strtolower($accion ?? '');
            $resultado       = strtolower($resultado ?? 'resultado');
            $username     = $username ?? null;
            $mac_address  = $mac_address ?? null;
            $nombre_eq   = $input['nombre_equipo'] ?? null;
            $now          = new DateTime('now', new DateTimeZone('America/Bogota'));

            // ============================================================
            // 🧠 Validaciones iniciales
            // ============================================================
            if (!$accion) {
                jsonOk(["estado" => "Error", "mensaje" => "Acción no especificada"]);
                exit;
            }

            if (!$folio_item_barcode || !$servicePointId) {
                jsonOk(["estado" => "Error", "mensaje" => "Faltan datos de FOLIO"]);
                exit;
            }

            // ============================================================
            // ⚙️ Lógica según acción solicitada
            // ============================================================
            switch ($accion) {
                // ------------------------------------------------------------
                // 🔚 FINALIZAR → Check-in en FOLIO + marcar sesión finalizada
                // ------------------------------------------------------------
                case 'finalizar':
                    $checkinResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                    $ok = isset($checkinResp['status']) && $checkinResp['status'] >= 200 && $checkinResp['status'] < 300;

                    if ($ok) {
                        // Actualizar en la base de datos (última sesión del usuario)
                        $ultimaSesion = getUltimaSesion($conn, $userId, $id_equipo);
                        if ($ultimaSesion) {
                            actualizarEstado($conn, $ultimaSesion['id'], ESTADO_FINALIZADO, $now->format('Y-m-d H:i:s'));
                        }
                        jsonOk([
                            "tipo" => "confirmacion_comando",
                            "accion" => $accion,
                            "estado"  => "FINALIZADO",
                            "resultado" => $resultado,
                            "nombre_usuario" => $username_full,
                            "nombre_equipo" => $nombre_eq,
                            "mensaje" => "Check-in completado correctamente en FOLIO y sesión finalizada."
                        ]);
                    } else {
                        $errorMsg = $checkinResp['Error'] ?? 'Error desconocido en FOLIO';
                        jsonOk([
                            "tipo" => "confirmacion_comando",
                            "accion" => $accion,
                            "estado"  => "ERROR",
                            "resultado" => $resultado,
                            "nombre_usuario" => $username_full,
                            "nombre_equipo" => $nombre_eq,
                            "mensaje" => "Fallo al realizar check-in: {$errorMsg}"
                        ]);
                    }
                    break;
                // ------------------------------------------------------------
                // 🔁 RENOVAR → Check-in + nueva sesión (checkout)
                // ------------------------------------------------------------
                case 'renovar':
                    $ultimaSesion = getUltimaSesion($conn, $userId, $id_equipo);
                    // Primero cerrar sesión actual con un check-in
                    $checkinResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                    actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO, $now->format('Y-m-d H:i:s'));
                    // Luego iniciar nueva sesión (checkout)
                    $checkoutResp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);

                    if ($checkinResp && $checkoutResp) {
                        $nuevaSesion = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                        jsonOk([
                            "estado"  => "Renovado_comando",
                            "mensaje" => "Sesión renovada: check-in y nuevo préstamo realizados en FOLIO."
                        ]);
                    } else {
                        jsonOk([
                            "estado"  => "Error_renovacion",
                            "mensaje" => "No se pudo renovar correctamente la sesión. Revise conexión con FOLIO. $ok1"
                        ]);
                    }
                    break;

                // ------------------------------------------------------------
                // 🚫 BLOQUEAR → Check-in + actualizar estado en BD
                // ------------------------------------------------------------
                case 'bloquear':
                    $checkinResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                    $ok = isset($checkinResp['status']) && $checkinResp['status'] >= 200 && $checkinResp['status'] < 300;

                    if ($ok) {
                        $ultimaSesion = getUltimaSesion($conn, $userId, $id_equipo);
                        if ($ultimaSesion) {
                            actualizarEstado($conn, $ultimaSesion['id'], ESTADO_BLOQUEADO, $now->format('Y-m-d H:i:s'));
                        }
                        jsonOk([
                            "estado"  => "Bloqueado_comando",
                            "mensaje" => "Sesión bloqueada y check-in completado correctamente."
                        ]);
                    } else {
                        $errorMsg = $checkinResp['Error'] ?? 'Error desconocido en FOLIO';
                        jsonOk([
                            "estado"  => "Error_bloqueo",
                            "mensaje" => "Fallo al bloquear sesión en FOLIO: {$errorMsg}"
                        ]);
                    }
                    break;
                // ------------------------------------------------------------
                // ❓ Acción desconocida
                // ------------------------------------------------------------
                default:
                    jsonOk([
                        "estado"  => "Error",
                        "mensaje" => "Acción de comando_api no reconocida: {$accion}"
                    ]);
                    break;
            }

            break; // Fin del case comando_api
        } else if ($origen == "equipo") {

            // ============================================================
            // 🧠 Validaciones iniciales
            // ============================================================
            if ($tipo != 'validar_admin') {
                jsonOk(["estado" => "Error", "mensaje" => "No se pudo validar el administrador"]);
                exit;
            }

            if (!$clave_admin) {
                jsonOk(["estado" => "Error", "mensaje" => "No se pudo validar la clave de administrador"]);
                exit;
            }

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
            }
        }
        break;
    default:
        # code...
        break;
}
$conn->close();
