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
$esperaBloqueo    = 5; // BLOQUEADO (s)
$claveCorrecta    = "S1m0n_20s25";
$restringido      = false;

// ================== FUNCIONES DE VALIDACIÓN FOLIO ==================
function validarCheckoutFolio($checkoutResp, &$errorMsg = null) {
    if (!$checkoutResp) {
        $errorMsg = "No hay respuesta de FOLIO";
        return false;
    }
    
    // Verificar si hay errores
    if (isset($checkoutResp['errors']) && !empty($checkoutResp['errors'])) {
        $firstError = $checkoutResp['errors'][0];
        $errorMsg = $firstError['message'] ?? 
                   $firstError['code'] ?? 
                   json_encode($checkoutResp['errors']);
        return false;
    }
    
    // Verificar si es exitoso
    if (isset($checkoutResp['successful']) && $checkoutResp['successful'] === true) {
        return true;
    }
    
    // Si tiene fields pero sin errores, asumir que fue exitoso
    if (isset($checkoutResp['item']) || isset($checkoutResp['loan'])) {
        return true;
    }
    
    $errorMsg = "Respuesta inesperada de FOLIO: " . json_encode($checkoutResp);
    return false;
}

function validarCheckinFolio($checkinResp, &$errorMsg = null) {
    if (!$checkinResp) {
        $errorMsg = "No hay respuesta de FOLIO";
        return false;
    }
    
    // Verificar si hay errores
    if (isset($checkinResp['errors']) && !empty($checkinResp['errors'])) {
        $firstError = $checkinResp['errors'][0];
        $errorMsg = $firstError['message'] ?? 
                   $firstError['code'] ?? 
                   json_encode($checkinResp['errors']);
        return false;
    }
    
    // Verificar si es exitoso
    if (isset($checkinResp['successful']) && $checkinResp['successful'] === true) {
        return true;
    }
    
    // Si tiene item/loan info, asumir que fue exitoso
    if (isset($checkinResp['item']) || isset($checkinResp['loan']) || isset($checkinResp['item_id'])) {
        return true;
    }
    
    $errorMsg = "Respuesta inesperada de FOLIO: " . json_encode($checkinResp);
    return false;
}

// ================== INPUT ==================
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Normalizar entradas: preferir explícitamente `mac_address`, aceptar `mac_eq` como fallback
$input['mac_address'] = $input['mac_address'] ?? $input['mac_eq'] ?? $_POST['mac_address'] ?? $_GET['mac_address'] ?? null;
$input['nombre_equipo'] = $input['nombre_equipo'] ?? $input['nombre_pc'] ?? $_POST['nombre_equipo'] ?? $_GET['nombre_equipo'] ?? null;

$username       = $input['username'] ?? $_POST['username'] ?? $_GET['username'] ?? null;
$mac_address = $input['mac_address'];
$nombre_eq = $input['nombre_equipo'] ?? null;
$clave_admin    = $input['clave_admin'] ?? $_POST['clave_admin']   ?? null;
$cancel_suspend = $input['cancel_suspend'] ?? $_POST['cancel_suspend']   ?? null;
$confirmar_inicio = $input['confirmar_inicio'] ?? $_POST['confirmar_inicio']   ?? null;
$empezar = $input['empezar'] ?? $_POST['empezar']   ?? null;
$tipo = $input['tipo'] ?? $_POST['tipo']   ?? null;
$user_comando  = $input['user_comando'] ?? $_POST['user_comando'] ?? null;
$accion = $input['accion'] ?? $_POST['accion'] ?? null;
$origen = $input['origen'] ?? $_POST['origen'] ?? null;
$destino = $input['destino'] ?? $_POST['destino'] ?? null;


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

// Convertir los minutos en fecha
$bloqueadoHastaDT = clone $now;
$bloqueadoHastaDT->modify("+{$esperaBloqueo} minutes");
$bloqueadoHasta = $bloqueadoHastaDT->format("Y-m-d H:i:s");

switch ($tipo) {
    case 'control':
        if ($origen == 'server' && $destino == 'api') {
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
                            jsonOk(["estado" => "Abierto", "mensaje" => "Sesión abierta en curso", "tiempo_restante" => $finSuspension->format("Y-m-d H:i:s")]);
                            break;
                        case ESTADO_SUSPENDIDO:
                            $finSuspension = ($fechaProg ? clone $fechaProg : $now)->modify("+" . $tiempoSuspendido . " seconds");
                            if ($clave_admin) {
                                if ($clave_admin == $claveCorrecta) {
                                    // 1. Hacer checkin de la sesión anterior
                                    $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                                    $checkinError = null;
                                    if (!validarCheckinFolio($checkInResp, $checkinError)) {
                                        jsonOk([
                                            "estado" => "Error",
                                            "mensaje" => "No se pudo hacer checkin en FOLIO: $checkinError",
                                            "folio_response" => $checkInResp
                                        ]);
                                        exit;
                                    }
                                    
                                    // 2. Actualizar sesión antigua a FINALIZADO
                                    actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO, $now->format('Y-m-d H:i:s'), $finSuspension->format("Y-m-d H:i:s"));
                                    
                                    // 3. Crear nueva sesión
                                    $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                                    
                                    // 4. Hacer checkout de la nueva sesión
                                    $checkoutResp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                                    $checkoutError = null;
                                    if (!validarCheckoutFolio($checkoutResp, $checkoutError)) {
                                        // Si el checkout falla, revertir todo
                                        actualizarEstado($conn, $sesion_id, ESTADO_BLOQUEADO, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $bloqueadoHasta);
                                        jsonOk([
                                            "estado" => "Error",
                                            "mensaje" => "No se pudo hacer checkout en FOLIO para la nueva sesión: $checkoutError",
                                            "folio_response" => $checkoutResp
                                        ]);
                                        exit;
                                    }
                                    
                                    jsonOk([
                                        "estado" => "Renovado",
                                        "mensaje" => "Sesión renovada exitosamente",
                                        "folio_checkin" => $checkInResp,
                                        "folio_checkout" => $checkoutResp
                                    ]);
                                    exit;
                                }
                            } elseif ($origen === 'server' && in_array($cancel_suspend, ["Cancelar", "Expirado", "Intentos"])) {
                                // ⚠️ IMPORTANTE: Solo bloqueamos si viene desde el SERVIDOR
                                // (El servidor valida que haya solicitud explícita del dashboard)

                                // Hacer checkin en FOLIO
                                $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                                $checkinError = null;
                                if (!validarCheckinFolio($checkInResp, $checkinError)) {
                                    jsonOk([
                                        "estado" => "Error",
                                        "mensaje" => "No se pudo hacer checkin en FOLIO al bloquear: $checkinError",
                                        "folio_response" => $checkInResp
                                    ]);
                                    exit;
                                }
                                
                                // Calcular bloqueado_hasta
                                $bloqueadoDesdeDT = clone $now;
                                $bloqueadoHastaDT = clone $now;
                                $bloqueadoHastaDT->modify("+{$esperaBloqueo} minutes");
                                
                                // Actualizar sesión a bloqueado CON fecha_final_real
                                actualizarEstado(
                                    $conn,
                                    $last['id'],
                                    ESTADO_BLOQUEADO,
                                    $now->format('Y-m-d H:i:s'),  // fecha_final_real <- AQUÍ SE REGISTRA LA HORA
                                    $bloqueadoDesdeDT->format("Y-m-d H:i:s"),
                                    $bloqueadoHastaDT->format("Y-m-d H:i:s")
                                );
                                
                                $motivo = match ($cancel_suspend) {
                                    "Cancelar" => "Sesión cancelada → Bloqueada",
                                    "Expirado" => "Sesión expirada → Bloqueada",
                                    "Intentos" => "Demasiados intentos fallidos → Bloqueada",
                                    default => "Sesión bloqueada"
                                };
                                jsonOk([
                                    "estado" => "Bloqueado",
                                    "mensaje" => $motivo,
                                    "bloqueado_hasta" => $bloqueadoHastaDT->format("Y-m-d H:i:s"),
                                    "folio_checkin" => $checkInResp
                                ]);
                                exit;
                            } else {
                                // Si no hay clave_admin ni cancel_suspend válido desde servidor, solo retornar estado actual
                                // SIN BLOQUEAR AUTOMÁTICAMENTE
                                jsonOk([
                                    "estado" => "Suspendido",
                                    "mensaje" => "Sesión suspendida. Esperando acción del usuario o administrador.",
                                    "tiempo_restante" => max(0, $finSuspension->getTimestamp() - $now->getTimestamp())
                                ]);
                                exit;
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
                            if ($loanExist) {
                                jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
                                exit;
                            }

                            $manualblock = folioManualBlock($userId, $token);
                            $autoblock = folioAutoBlock($userId, $token);
                            if (!empty($manualblock) || !empty($autoblock)) {
                                jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
                                exit;
                            }

                            $bloqueadoHasta = isset($last['bloqueado_hasta'])
                                ? new DateTime($last['bloqueado_hasta'], new DateTimeZone('America/Bogota'))
                                : null;

                            if ($bloqueadoHasta && $now < $bloqueadoHasta) {
                                jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
                                exit;
                            }

                            // ✅ TODAS las condiciones OK, auto-inicia
                            try {
                                $checkout_resp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                                $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);

                                jsonOk([
                                    "estado" => "Abierto",
                                    "auto_iniciada" => true,  // ✅ Indica que fue auto-inicio
                                    "sesion_id" => $sesion_id,
                                    "tiempo_restante" => $intervaloTiempo,
                                    "mensaje" => "Sesión iniciada automáticamente"
                                ]);
                            } catch (Exception $e) {
                                jsonOk([
                                    "estado" => "Finalizado",
                                    "puede_auto_iniciar" => true,
                                    "puede_auto_iniciar_error" => $e->getMessage()
                                ]);
                            }
                            exit;
                        default:
                            jsonOk(["estado" => "Error", "mensaje" => "Flujo no reconocido en estado Suspendido"]);
                            exit;
                    }
                    if ($accion == 'estado') {
                        jsonOk([
                            "estado" => $estado,
                            "mensaje" => "Respuesta de estado",
                            "tiempo_restante" => $tiempoRestante ?? null
                        ]);
                    }
                } else {
                    $checkout_resp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                    $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                    jsonOk(["estado" => "Abierto", "mensaje" => "Sesión iniciada correctamente"]);
                }
            }
        }
        break;
    case 'comando_api':
        $accion = strtolower($accion ?? '');
        $resultado = $resultado ?? 'resultado';
        $now = new DateTime('now', new DateTimeZone('America/Bogota'));
        if ($origen == 'server' && $destino == 'api') {
            // ============================================================
            // 📊 ACCIÓN ESPECIAL: SOLICITUD DE ESTADO (SIN REQUIERE ADMIN)
            // ============================================================
            if ($accion === 'estado') {
                // Este es el flujo normal de control (líneas 60-283)
                // Solo ejecutar el switch de control, no el de comandos
                if (!$last) {
                    // Nueva sesión
                    $checkout_resp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                    $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                    jsonOk(["estado" => "Abierto", "mensaje" => "Sesión iniciada correctamente"]);
                } else {
                    // Sesión existente
                    switch ($estado) {
                        case ESTADO_ABIERTO:
                            if ($fechaProg && $now >= $fechaProg) {
                                actualizarEstado($conn, $last['id'], ESTADO_SUSPENDIDO);
                                jsonOk(["estado" => "Suspendido", "mensaje" => "Sesión suspendida automáticamente"]);
                                exit;
                            }
                            $tiempoRestante = $fechaProg ? ($fechaProg->getTimestamp() - $now->getTimestamp()) : $intervaloTiempo;
                            jsonOk(["estado" => "Abierto", "mensaje" => "Sesión abierta en curso", "tiempo_restante" => $tiempoRestante]);
                            break;
                        case ESTADO_SUSPENDIDO:
                            if($accion === 'bloquear') {
                                // Calcular bloqueado_hasta
                                $bloqueadoDesdeDT = clone $now;
                                $bloqueadoHastaDT = clone $now;
                                $bloqueadoHastaDT->modify("+{$esperaBloqueo} minutes");
                                
                                // Actualizar sesión a bloqueado CON fecha_final_real
                                actualizarEstado(
                                    $conn,
                                    $last['id'],
                                    ESTADO_BLOQUEADO,
                                    $now->format('Y-m-d H:i:s'),  // fecha_final_real <- AQUÍ SE REGISTRA LA HORA
                                    $bloqueadoDesdeDT->format("Y-m-d H:i:s"),
                                    $bloqueadoHastaDT->format("Y-m-d H:i:s")
                                );
                                jsonOk([
                                    "estado" => "Bloqueado",
                                    "mensaje" => "Sesión bloqueada por comando",
                                    "bloqueado_hasta" => $bloqueadoHastaDT->format("Y-m-d H:i:s"),
                                    "folio_checkin" => $checkInResp
                                ]);
                            }
                            break;
                        case ESTADO_BLOQUEADO:
                            $bloqueadoDesde = isset($last['bloqueado_desde']) ? new DateTime($last['bloqueado_desde'], new DateTimeZone('America/Bogota')) : null;
                            $bloqueadoHasta = isset($last['bloqueado_hasta']) ? new DateTime($last['bloqueado_hasta'], new DateTimeZone('America/Bogota')) : null;
                            if ($bloqueadoDesde === null || $bloqueadoHasta === null) {
                                actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO, $finSuspension->format('Y-m-d H:i:s'));
                                jsonOk(["estado" => "Finalizado", "mensaje" => "La sesión estaba en bloqueo pero sin datos completos. Finalizada por seguridad."]);
                                exit;
                            }
                            if ($now >= $bloqueadoHasta) {
                                actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO, $now->format('Y-m-d H:i:s'));
                                $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                                jsonOk(["estado" => "Finalizado", "mensaje" => "Sesión finalizada automáticamente después de bloqueo."]);
                                exit;
                            }
                            $restante = $bloqueadoHasta->getTimestamp() - $now->getTimestamp();
                            jsonOk(["estado" => "Bloqueado", "bloqueado_hasta" => $bloqueadoHasta->getTimestamp(), "mensaje" => "Sesión bloqueada en cuenta regresiva."]);
                            break;
                        case ESTADO_FINALIZADO:
                            $loanExist = loanExists($token, $folio_item_barcode);
                            if ($loanExist) {
                                jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
                                exit;
                            }
                            $manualblock = folioManualBlock($userId, $token);
                            $autoblock = folioAutoBlock($userId, $token);
                            if (!empty($manualblock) || !empty($autoblock)) {
                                jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
                                exit;
                            }
                            $bloqueadoHasta = isset($last['bloqueado_hasta'])
                                ? new DateTime($last['bloqueado_hasta'], new DateTimeZone('America/Bogota'))
                                : null;
                            if ($bloqueadoHasta && $now < $bloqueadoHasta) {
                                jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
                                exit;
                            }
                            try {
                                $checkout_resp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                                $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                                jsonOk([
                                    "estado" => "Abierto",
                                    "auto_iniciada" => true,
                                    "sesion_id" => $sesion_id,
                                    "tiempo_restante" => $intervaloTiempo,
                                    "mensaje" => "Sesión iniciada automáticamente"
                                ]);
                            } catch (Exception $e) {
                                jsonOk([
                                    "estado" => "Finalizado",
                                    "puede_auto_iniciar" => true,
                                    "puede_auto_iniciar_error" => $e->getMessage()
                                ]);
                            }
                            break;
                        default:
                            jsonOk(["estado" => "Error", "mensaje" => "Flujo no reconocido"]);
                            exit;
                    }
                }
                exit;
            }

            // ============================================================
            // 🔐 COMANDOS ESPECIALES QUE REQUIEREN CLAVE ADMIN
            // ============================================================
            $claveAdmin =  $input['clave_admin'] ?? null;
            if (!$claveAdmin) {
                jsonOk([
                    "estado" => "Error",
                    "mensaje" => "No se proporcionó clave de administrador"
                ]);
                exit;
            }

            // Validar clave
            if ($claveAdmin !== $claveCorrecta) {
                jsonOk([
                    "estado" => "Error",
                    "mensaje" => "Clave de administrador incorrecta"
                ]);
                exit;
            }

            // Clave correcta -> Proceder con renovación
            $last = getUltimaSesion($conn, $userId, $id_equipo);

            if (!$last) {
                jsonOk([
                    "estado" => "Error",
                    "mensaje" => "No hay sesión activa para renovar"
                ]);
                exit;
            }

            // Finalizar sesión actual
            actualizarEstado(
                $conn,
                $last['id'],
                ESTADO_FINALIZADO,
                $now->format('Y-m-d H:i:s')
            );

            // Check-in en FOLIO
            $checkinResp = folioCheckin($token, $folio_item_barcode, $servicePointId);

            if (!$checkinResp || (isset($checkinResp['status']) && $checkinResp['status'] >= 400)) {
                jsonOk([
                    "estado" => "Error_checkin",
                    "mensaje" => "Error al hacer check-in en FOLIO"
                ]);
                exit;
            }

            // Crear nueva sesión
            $nuevaSesionId = crearSesion(
                $conn,
                $userId,
                $username_full,
                $id_equipo,
                $intervaloTiempo
            );

            // Check-out en FOLIO
            $checkoutResp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);

            if (isset($checkoutResp['errors'])) {
                foreach ($checkoutResp['errors'] as $err) {
                    if (($err['code'] ?? '') === "ITEM_HAS_OPEN_LOAN") {
                        jsonOk([
                            "estado" => "Error",
                            "mensaje" => "El equipo ya tiene un préstamo abierto en FOLIO"
                        ]);
                        exit;
                    }
                }
            }

            jsonOk([
                "tipo" => "confirmacion_comando",
                "accion" => "validar_admin",
                "estado" => "Renovado",
                "mensaje" => "Sesión renovada con clave de administrador",
                "nueva_sesion_id" => $nuevaSesionId,
                "tiempo_restante" => $intervaloTiempo
            ]);
            exit;
            if ($accion === 'iniciar_auto') {
                $ultimaSesion = getUltimaSesion($conn, $userId, $id_equipo);
                if ($ultimaSesion["estado"] == ESTADO_FINALIZADO) {
                    $checkout_resp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
                    $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
                    if ($checkout_resp) {
                        jsonOk([
                            "tipo" => "confirmacion_comando",
                            "accion" => $accion,
                            "estado" => "FINALIZADO",
                            "auto_iniciada" => true,
                            "resultado" => $resultado,
                            "nombre_usuario" => $username_full,
                            "nombre_equipo" => $nombre_eq ?? $mac_address,
                            "mensaje" => "Check-in completado correctamente en FOLIO y sesión finalizada."
                        ]);
                    }
                } else {
                    $errorMsg = $checkinResp['Error'] ?? 'Error desconocido en FOLIO';
                    jsonOk([
                        "tipo" => "confirmacion_comando",
                        "accion" => $accion,
                        "estado" => "ERROR",
                        "resultado" => $resultado,
                        "mensaje" => "Fallo al realizar check-in: {$errorMsg}"
                    ]);
                }

                exit;
            }
            // ============================================================
            // 🔚 FINALIZAR
            // ============================================================
            if ($accion === 'finalizar') {
                $checkinResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                $ok = isset($checkinResp['status']) && $checkinResp['status'] >= 200 && $checkinResp['status'] < 300;

                if ($ok) {
                    $ultimaSesion = getUltimaSesion($conn, $userId, $id_equipo);
                    if ($ultimaSesion) {
                        actualizarEstado(
                            $conn,
                            $ultimaSesion['id'],
                            ESTADO_FINALIZADO,
                            $now->format('Y-m-d H:i:s')
                        );
                    }

                    jsonOk([
                        "tipo" => "confirmacion_comando",
                        "accion" => $accion,
                        "estado" => "FINALIZADO",
                        "resultado" => $resultado,
                        "nombre_usuario" => $username_full,
                        "nombre_equipo" => $nombre_eq ?? $mac_address,
                        "mensaje" => "Check-in completado correctamente en FOLIO y sesión finalizada."
                    ]);
                } else {
                    $errorMsg = $checkinResp['Error'] ?? 'Error desconocido en FOLIO';
                    jsonOk([
                        "tipo" => "confirmacion_comando",
                        "accion" => $accion,
                        "estado" => "ERROR",
                        "resultado" => $resultado,
                        "mensaje" => "Fallo al realizar check-in: {$errorMsg}"
                    ]);
                }
                exit;
            }

            // ============================================================
            // 🔁 RENOVAR
            // ============================================================
            if ($accion === 'renovar') {
                $ultimaSesion = getUltimaSesion($conn, $userId, $id_equipo);

                if (!$ultimaSesion) {
                    jsonOk([
                        "estado" => "Error",
                        "mensaje" => "No hay sesión activa para renovar"
                    ]);
                    exit;
                }

                // Cerrar sesión actual
                actualizarEstado(
                    $conn,
                    $ultimaSesion['id'],
                    ESTADO_FINALIZADO,
                    $now->format('Y-m-d H:i:s')
                );

                $checkinResp = folioCheckin($token, $folio_item_barcode, $servicePointId);

                // Crear nueva sesión
                $nuevaSesion = crearSesion(
                    $conn,
                    $userId,
                    $username_full,
                    $id_equipo,
                    $intervaloTiempo
                );

                $checkoutResp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);

                if ($checkinResp && $checkoutResp) {
                    jsonOk([
                        "estado" => "Renovado_comando",
                        "mensaje" => "Sesión renovada: check-in y nuevo préstamo realizados en FOLIO.",
                        "nueva_sesion_id" => $nuevaSesion,
                        "tiempo_restante" => $intervaloTiempo
                    ]);
                } else {
                    jsonOk([
                        "estado" => "Error_renovacion",
                        "mensaje" => "No se pudo renovar correctamente la sesión. Revise conexión con FOLIO."
                    ]);
                }
                exit;
            }

            // ============================================================
            // 🚫 BLOQUEAR
            // ============================================================
            if ($accion === 'bloquear') {
                // Hacer checkin en FOLIO
                $checkinResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
                $checkinError = null;
                
                // Validar checkin
                if (!validarCheckinFolio($checkinResp, $checkinError)) {
                    jsonOk([
                        "estado" => "Error_bloqueo",
                        "mensaje" => "No se pudo hacer checkin en FOLIO para bloquear: $checkinError",
                        "folio_response" => $checkinResp
                    ]);
                    exit;
                }

                $ultimaSesion = getUltimaSesion($conn, $userId, $id_equipo);
                if ($ultimaSesion) {
                    // Calcular tiempo de bloqueo (10 minutos desde ahora)
                    // IMPORTANTE: Clonar $now para no modificar el original
                    $bloqueadoDesdeDT = clone $now;
                    $bloqueadoHastaDT = clone $now;
                    $bloqueadoHastaDT->modify('+10 minutes');
                    
                    $bloqueadoDesde = $bloqueadoDesdeDT->format('Y-m-d H:i:s');
                    $bloqueadoHasta = $bloqueadoHastaDT->format('Y-m-d H:i:s');

                    // ⚠️ REGISTRAR fecha_final_real cuando se bloquea por timeout (accion='bloquear' desde servidor)
                    actualizarEstado(
                        $conn,
                        $ultimaSesion['id'],
                        ESTADO_BLOQUEADO,
                        $now->format('Y-m-d H:i:s'), // fecha_final_real <- SE REGISTRA AQUÍ TAMBIÉN
                        $bloqueadoDesde,
                        $bloqueadoHasta
                        );
                    
                    jsonOk([
                        "estado" => "Bloqueado_comando",
                        "mensaje" => "Sesión bloqueada y check-in completado correctamente.",
                        "bloqueado_hasta" => $bloqueadoHasta ?? null,
                        "folio_checkin" => $checkinResp
                    ]);
                } else {
                    jsonOk([
                        "estado" => "Error_bloqueo",
                        "mensaje" => "No se pudo completar el bloqueo: $checkinError",
                        "folio_response" => $checkinResp
                    ]);
                }
                exit;
            }
            // Acción desconocida
            jsonOk([
                "estado" => "Error",
                "mensaje" => "Acción de comando_api no reconocida: {$accion}"
            ]);
            exit;
        }

        break;
    case 'finalizar':
        // Finalizar sesión: hacer checkin en FOLIO y marcar como Finalizado
        if ($last) {
            // Hacer checkin en FOLIO
            $checkInResp = folioCheckin($token, $folio_item_barcode, $servicePointId);
            $checkinError = null
;
            
            // Validar que el checkin fue exitoso
            if (!validarCheckinFolio($checkInResp, $checkinError)) {
            jsonOk([
                "estado" => "Error",
                "mensaje" => "Error al hacer checkin en FOLIO: $checkinError",
                "folio_checkin" => $checkInResp ?? [],
                "checkin_error" => $checkinError
            ]);
            }
            
            // Actualizar sesión a FINALIZADO con fecha_final_real = ahora
            actualizarEstado($conn, $last['id'], ESTADO_FINALIZADO, $now->format('Y-m-d H:i:s'));
            
            // Retornar éxito incluso si checkin falló (la sesión fue finalizada en DB)
            jsonOk([
                "estado" => "Finalizado",
                "mensaje" => "Sesión finalizada correctamente",
                "folio_checkin" => $checkInResp ?? [],
                "checkin_error" => $checkinError
            ]);
            exit;
        } else {
            jsonError("No hay sesión activa para finalizar");
        }
        break;
    default:
        # code...
        break;
}
$conn->close();
