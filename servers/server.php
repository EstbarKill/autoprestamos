<?php
// ============================================================
// 🔌 SERVIDOR WEBSOCKET AUTOPRÉSTAMOS - UNIVERSIDAD SIMÓN BOLÍVAR
// Versión: 2.1 - Con confirmaciones de comandos en tiempo real
// ============================================================

require_once __DIR__ . '/../config/db.php';
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;

class DashboardServer implements MessageComponentInterface
{
    protected $claveCorrecta    = "S1m0n_2025";
    protected $clients;
    protected $equipos;       // Clientes PowerShell registrados
    protected $dashboards;    // Dashboards web conectados
    protected $conn;
    protected $ultimoEnvioEstado = 0;
    protected $ultimoCheckDesbloqueo;
    protected $intervaloDesbloqueo = 30; // 4 minutos = 240 segundos
    public function __construct($conn)
    {
        $this->clients = new \SplObjectStorage;
        $this->equipos = [];
        $this->dashboards = [];
        $this->conn = $conn;
        $this->ultimoCheckDesbloqueo = time();
    }
    public function verificarDesbloqueos()
    {
        // 🔍 PASO 1: Buscar todas las sesiones bloqueadas cuyo tiempo ya expiró
        $sqlSelect = "
        SELECT id, id_equipo_fk
        FROM sesiones
        WHERE id_estado_fk = 4
        AND bloqueado_hasta IS NOT NULL
        AND bloqueado_hasta <= NOW()
        ";

        $result = $this->conn->query($sqlSelect);

        if (!$result) {
            $this->log("❌ Error verificando desbloqueos: " . $this->conn->error);
            return;
        }

        // Recopilar IDs de sesiones a actualizar
        $sesionesExpiradas = [];
        while ($row = $result->fetch_assoc()) {
            $sesionesExpiradas[] = $row;
        }

        // Si no hay sesiones expiradas, salir
        if (empty($sesionesExpiradas)) {
            return;
        }

        // 📊 PASO 2: Actualizar TODAS las sesiones expiradas en UNA SOLA consulta
        $idsExpirados = implode(',', array_column($sesionesExpiradas, 'id'));
        
        $sqlUpdate = "
        UPDATE sesiones 
        SET id_estado_fk = 1
        WHERE id IN ($idsExpirados)
        ";

        if (!$this->conn->query($sqlUpdate)) {
            $this->log("❌ Error actualizando sesiones expiradas: " . $this->conn->error);
            return;
        }

        $cantidadActualizada = count($sesionesExpiradas);
        $this->log("🔓 $cantidadActualizada sesión/es pasaron a FINALIZADO automáticamente");

        // 🔔 PASO 3: Notificar dashboards de TODOS los cambios
        foreach ($sesionesExpiradas as $sesion) {
            $this->notificarDashboards([
                "tipo"      => "estado_cambiado",
                "id_sesion" => $sesion['id'],
                "estado"    => "finalizado",
                "timestamp" => date("Y-m-d H:i:s")
            ]);
        }
    }
    private function schedulerDesbloqueos()
    {
        $ahora = time();

        // Evitar ejecución muy seguida
        if (($ahora - $this->ultimoCheckDesbloqueo) < $this->intervaloDesbloqueo) {
            return;
        }

        // Actualizar timestamp
        $this->ultimoCheckDesbloqueo = $ahora;

        $this->log("⏱️ Ejecutando verificación periódica de desbloqueos...");

        // Llamar a tu función real
        $this->verificarDesbloqueos();
    }

    // ============================================================
    // 🔄 PROCESADOR DE SOLICITUDES DESDE EQUIPOS
    // ============================================================
    private function procesarSolicitudEquipo($from, $data)
    {
        $accion = $data['accion'] ?? null;
        $nombreEquipo = $data['nombre_equipo'] ?? null;
        $username = $data['username'] ?? null;
        $macAddress = $data['mac_eq'] ?? null;

        if (!$nombreEquipo) {
            $this->log("❌ Solicitud sin nombre_equipo");
            return;
        }

        $this->log("📥 Solicitud de $nombreEquipo: $accion");

        switch ($accion) {

            // -------------------------------------
            // 🔔 SOLICITAR RENOVACIÓN AL DASHBOARD
            // -------------------------------------
            case 'solicitar_renovacion':
                $sessionId = $this->obtenerSessionIdPorEquipo($nombreEquipo);

                if (!$sessionId) {
                    $this->log("❌ No hay sesión activa para $nombreEquipo");
                    $this->enviarAEquipo($nombreEquipo, [
                        'tipo' => 'error',
                        'mensaje' => 'No hay sesión activa',
                        'origen' => 'server'
                    ]);
                    return;
                }

                // Notificar a dashboards
                $this->notificarDashboards([
                    'tipo' => 'solicitud',
                    'accion' => 'solicitar_renovacion',
                    'nombre_equipo' => $nombreEquipo,
                    'sessionId' => $sessionId,
                    'usuario' => $username,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);

                $this->log("✅ Solicitud de renovación enviada a dashboards");
                break;

            // -------------------------------------
            // 🔐 RENOVAR CON CLAVE ADMIN
            // -------------------------------------
            case 'renovar_clave':
    $claveAdmin = $data['clave_admin'] ?? null;
    
    if (!$claveAdmin) {
        $this->enviarAEquipo($nombreEquipo, [
            'tipo' => 'error',
            'mensaje' => 'Clave no proporcionada'
        ]);
        return;
    }
    
    // ✅ NO valida, deja que API lo haga
    // Llama directamente a API
    $resultadoAPI = $this->llamarAPI([
        'tipo' => 'comando_api',
        'accion' => 'validar_admin',
        'username' => $username,
        'mac_eq' => $macAddress,
        'clave_admin' => $claveAdmin,
        'origen' => 'server',
        'destino' => 'api',
        'timestamp' => date('c')
    ]);
    
    // Procesa respuesta (que viene de API)
    if ($resultadoAPI['estado'] === 'Renovado') {
        $this->enviarAEquipo($nombreEquipo, $resultadoAPI);
    } else {
        $this->enviarAEquipo($nombreEquipo, [
            'tipo' => 'error',
            'mensaje' => $resultadoAPI['mensaje']
        ]);
    }
    break;

            // -------------------------------------
            // ⛔ CERRAR SESIÓN VOLUNTARIAMENTE
            // -------------------------------------
            case 'cerrar':
                $this->log("🚪 $nombreEquipo solicita cerrar sesión (pasando a BLOQUEADO)");

                // Pedir al API que bloquee la sesión (registro en DB + checkin en FOLIO)
                $resultadoAPI = $this->llamarAPI([
                    'tipo' => 'comando_api',
                    'accion' => 'bloquear',
                    'username' => $username,
                    'mac_eq' => $macAddress,
                    'nombre_equipo' => $nombreEquipo,
                    'origen' => 'server',
                    'destino' => 'api',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'respuesta' => 'manual'
                ]);

                // Determinar si la API reportó bloqueo exitoso
                $estadoApi = isset($resultadoAPI['estado']) ? strtolower($resultadoAPI['estado']) : '';
                $esBloqueado = (strpos($estadoApi, 'bloque') !== false);

                // Informar al equipo que se procesó el bloqueo
                $this->enviarAEquipo($nombreEquipo, [
                    'tipo' => 'confirmacion_comando',
                    'accion' => 'bloquear',
                    'estado' => $esBloqueado ? 'bloqueado_temporal' : 'error',
                    'mensaje' => $resultadoAPI['mensaje'] ?? ($esBloqueado ? 'Sesión bloqueada temporalmente' : 'Error al bloquear sesión'),
                    'origen' => 'server',
                    'bloqueado_hasta' => $resultadoAPI['bloqueado_hasta'] ?? null
                ]);

                // Notificar dashboards
                $this->notificarDashboards([
                    'tipo' => 'log',
                    'mensaje' => "🚪 $nombreEquipo - solicitud de cierre procesada como bloqueo",
                    'timestamp' => date('Y-m-d H:i:s'),
                    'origen' => 'server'
                ]);

                // Si el bloqueo fue exitoso, notificar al equipo que puede mostrar la pantalla de finalización
                if ($esBloqueado) {
                    $this->enviarAEquipo($nombreEquipo, [
                        'tipo' => 'respuesta_estado',
                        'estado' => 'Finalizado',
                        'mensaje' => $resultadoAPI['mensaje'] ?? 'Sesión finalizada tras bloqueo',
                        'origen' => 'server'
                    ]);
                } else {
                    // Notificar error de bloqueo
                    $this->enviarAEquipo($nombreEquipo, [
                        'tipo' => 'respuesta_estado',
                        'estado' => 'Error',
                        'mensaje' => $resultadoAPI['mensaje'] ?? 'Error bloqueando sesión',
                        'origen' => 'server'
                    ]);
                }

                break;

            // -------------------------------------
            // ⏰ SESIÓN EXPIRADA POR TIEMPO
            // -------------------------------------
            case 'expirado':
                $this->log("⏰ Sesión expirada: $nombreEquipo");

                $resultadoAPI = $this->llamarAPI([
                    'tipo' => 'comando_api',
                    'accion' => 'bloquear',
                    'username' => $username,
                    'mac_eq' => $macAddress,
                    'nombre_equipo' => $nombreEquipo,
                    'origen' => 'server',
                    'destino' => 'api',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'respuesta' => 'expirado'
                ]);

                $this->enviarAEquipo($nombreEquipo, [
                    'tipo' => 'confirmacion_comando',
                    'accion' => 'bloquear',
                    'estado' => 'bloqueado_temporal',
                    'mensaje' => 'Sesión expirada - Bloqueo temporal de 10 minutos',
                    'origen' => 'server',
                    'destino' => 'shell'
                ]);

                $this->notificarDashboards([
                    'tipo' => 'log',
                    'mensaje' => "⏰ $nombreEquipo - Sesión expirada, bloqueado temporalmente",
                    'timestamp' => date('Y-m-d H:i:s'),
                    'origen' => 'server',
                    'destino' => 'dashboard'
                ]);
                break;

            default:
                $this->log("❓ Acción de solicitud desconocida: $accion");
                break;
        }
    }
    // ============================================================
    // 🔄 PROCESADOR DE RESPUESTAS DESDE DASHBOARD
    // ============================================================
    private function procesarRespuestaDashboard($from, $data)
    {
        $accionDashboard = $data['accion'] ?? null;
        $sessionId = $data['session'] ?? null;

        if (!$sessionId) {
            $this->log("❌ Respuesta sin sessionId");
            return;
        }

        // Obtener datos de la sesión
        $sesion = $this->obtenerDatosSesion($sessionId);

        if (!$sesion) {
            $this->log("❌ Sesión $sessionId no encontrada");
            return;
        }

        $nombreEquipo = $sesion['nombre_pc'];
        $username = $sesion['username'];
        $macAddress = $sesion['mac_eq'];

        $this->log("📥 Dashboard respondió: $accionDashboard para sesión $sessionId");

        switch ($accionDashboard) {

            // -------------------------------------
            // ✅ DASHBOARD APRUEBA RENOVACIÓN
            // -------------------------------------
            case 'aceptar_renovacion':
                $resultadoAPI = $this->llamarAPI([
                    'tipo' => 'comando_api',
                    'accion' => 'renovar',
                    'username' => $username,
                    'mac_eq' => $macAddress,
                    'nombre_equipo' => $nombreEquipo,
                    'origen' => 'server',
                    'destino' => 'api',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);

                if ($resultadoAPI['estado'] === 'Renovado_comando') {
                    $this->enviarAEquipo($nombreEquipo, [
                        'tipo' => 'confirmacion_comando',
                        'accion' => 'renovar',
                        'estado' => 'renovado',
                        'mensaje' => 'El administrador aprobó tu solicitud de renovación',
                        'origen' => 'server'
                    ]);

                    $this->notificarDashboards([
                        'tipo' => 'log',
                        'mensaje' => "✅ Renovación aprobada para $nombreEquipo",
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    $this->enviarAEquipo($nombreEquipo, [
                        'tipo' => 'error',
                        'mensaje' => 'Error al renovar sesión',
                        'origen' => 'server'
                    ]);
                }
                break;

            // -------------------------------------
            // ❌ DASHBOARD RECHAZA RENOVACIÓN
            // -------------------------------------
            case 'rechazar_renovacion':
                $resultadoAPI = $this->llamarAPI([
                    'tipo' => 'comando_api',
                    'accion' => 'finalizar',
                    'username' => $username,
                    'mac_eq' => $macAddress,
                    'nombre_equipo' => $nombreEquipo,
                    'origen' => 'server',
                    'destino' => 'api',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);

                $this->enviarAEquipo($nombreEquipo, [
                    'tipo' => 'confirmacion_comando',
                    'accion' => 'finalizar',
                    'estado' => 'rechazado',
                    'mensaje' => 'El administrador rechazó tu solicitud. La sesión finalizará.',
                    'origen' => 'server'
                ]);

                $this->notificarDashboards([
                    'tipo' => 'log',
                    'mensaje' => "❌ Renovación rechazada para $nombreEquipo",
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                break;

            default:
                $this->log("❓ Acción de dashboard desconocida: $accionDashboard");
                break;
        }
    }
    // ============================================================
    // 🛠️ UTILIDADES
    // ============================================================

    // Enviar mensaje a un equipo específico
    private function enviarAEquipo($nombreEquipo, $payload)
    {
        if (isset($this->equipos[$nombreEquipo])) {
            try {
                $this->equipos[$nombreEquipo]->send(json_encode($payload));
                $this->log("✅ Mensaje enviado a $nombreEquipo");
                return true;
            } catch (\Exception $e) {
                $this->log("❌ Error enviando a $nombreEquipo: {$e->getMessage()}");
                return false;
            }
        } else {
            $this->log("⚠️ Equipo $nombreEquipo no conectado");
            return false;
        }
    }

    // Obtener sessionId por nombre de equipo
    private function obtenerSessionIdPorEquipo($nombreEquipo)
    {
        $sessionId = null;
        $stmt = $this->conn->prepare("
        SELECT s.id
        FROM sesiones s
        INNER JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
        WHERE eq.nombre_pc = ?
        ORDER BY s.id DESC
        LIMIT 1
    ");

        $stmt->bind_param("s", $nombreEquipo);
        $stmt->execute();
        $stmt->bind_result($sessionId);
        $stmt->fetch();
        $stmt->close();

        return $sessionId ?? null;
    }

    // Obtener datos completos de una sesión
    private function obtenerDatosSesion($sessionId)
    {
        $stmt = $this->conn->prepare("
        SELECT 
            s.id,
            s.username,
            s.id_equipo_fk,
            eq.nombre_pc,
            eq.mac_eq
        FROM sesiones s
        LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
        WHERE s.id = ?
        LIMIT 1
    ");

        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $sesion = $result->fetch_assoc();
        $stmt->close();

        return $sesion;
    }

    // Llamar a la API REST
    private function llamarAPI($payload)
    {
        $apiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php";

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($payload),
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT         => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log("❌ Error cURL API: $error");
            return ['estado' => 'ERROR_CURL', 'mensaje' => $error];
        }

        $decoded = json_decode($response, true);

        if (!$decoded) {
            $this->log("⚠️ API devolvió respuesta inválida: $response");
            return ['estado' => 'ERROR_JSON', 'mensaje' => 'Respuesta API inválida'];
        }

        return $decoded;
    }
    // ============================================================
    // 🟢 NUEVA CONEXIÓN
    // ============================================================
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $conn->tipoCliente = null;
        $conn->idCliente = null;
        $this->log("🟢 Cliente conectado: ({$conn->resourceId})");
        $this->schedulerDesbloqueos();
    }

    // ============================================================
    // 📩 MENSAJE RECIBIDO
    // ============================================================
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->schedulerDesbloqueos();
        $data = @json_decode($msg, true);
        if (!$data) {
            $this->log("⚠️ Mensaje no JSON: $msg");
            return;
        }

        // Normalizar claves comunes para compatibilidad con el shell PowerShell
        // Aceptar tanto 'mac_eq' como 'mac_address', y 'nombre_equipo'/'nombre_pc', 'username'/'usuario'
        $data['mac_eq'] = $data['mac_eq'] ?? $data['mac_address'] ?? null;
        $data['mac_address'] = $data['mac_address'] ?? $data['mac_eq'] ?? null;
        $data['nombre_equipo'] = $data['nombre_equipo'] ?? $data['nombre_pc'] ?? null;
        $data['username'] = $data['username'] ?? $data['usuario'] ?? null;

        // Primary routing by tipo (preferred) or accion
        switch ($data['tipo'] ?? '') {
            // ========================================
            // 📊 SOLICITUD DE ESTADO DESDE EQUIPO
            // ========================================
            case 'solicitar_estado':
                if ($data['origen'] == 'equipo' && $data['destino'] == 'server') {
                    $this->procesarSolicitudEstado($from, $data);
                }
                break;
            // ========================================
            // 🖥️ Registro de cliente PowerShell
            // ========================================
            case 'registro':
                // ========================================
                // 📊 REGISTRO DE DASHBOARD
                // ========================================
                if ($data['origen'] == 'dashboard') {
                    $this->procesarRegistroDashboard($from, $data);
                    break;
                }

                // ========================================
                // 🖥️ REGISTRO DE EQUIPO (CON ESTADO INICIAL)
                // ========================================
                if ($data['origen'] == 'equipo') {
                    $this->procesarRegistroEquipo($from, $data);
                    break;
                }
                break;
            // ========================================
            // 📨 SOLICITUDES DESDE EQUIPOS
            // ========================================
            case 'solicitud':
                $this->procesarSolicitudEquipo($from, $data);
                break;

            // ========================================
            // 📨 RESPUESTAS DESDE DASHBOARD
            // ========================================
            case 'respuesta_solicitud':
                $this->procesarRespuestaDashboard($from, $data);
                break;
            case 'comando':
                $accion = $data['accion'] ?? 'undefined';
                $nombre_equipo = $data['nombre_equipo'] ?? null;
                $origen = $data['origen'] ?? null;
                $manejo = null;
                $texto = $data['mensaje'] ?? null;
                $destino = $data['destino'] ?? $data['nombre_equipo'] ?? null;
                $id_p_servicio = $data['id_p_servicio'] ?? null;
                if ($origen === 'dashboard') {
                    switch ($accion) {
                        case 'mensaje':
                            $manejo = 'mensaje';
                            break;
                        case 'info':
                            $manejo = 'ver_info';
                            break;
                        default:
                            $manejo = 'comandos';
                            break;
                    }

                    if (!$destino) {
                        echo "⚠️ No se especifico destino.\n";
                        return;
                    }
                    // 🧠 Envío a todos
                    if (strtolower($destino) === 'todos') {
                        echo "🌍 Enviando mensaje a todos los equipos conectados...\n";
                        foreach ($this->equipos as $equip) {
                            $equipPuntoId = $equip->id_p_servicio ?? ($equip->puntoServicio['id'] ?? null);
                            if ($equipPuntoId && $equipPuntoId == ($from->puntoServicio['id'] ?? null)) {
                                $equip->send(json_encode([
                                    'tipo'    => 'control_server',
                                    'accion'  => $accion,
                                    'manejo'  => $manejo,
                                    'id_p_servicio' => $id_p_servicio,
                                    'texto'   => $texto,
                                    'origen'  => 'server',
                                    'destino' => 'shell',
                                    'timestamp' => date('Y-m-d H:i:s')
                                ]));
                            }
                        }
                        break;
                    }
                    echo " Enviando comando '{$accion}' a equipo '{$destino}'...Manejo '{$manejo}'\n";
                    $found = false;

                    foreach ($this->equipos as $equip) {
                        if (isset($equip->idCliente) && $equip->idCliente === $destino) {
                            $found = true;
                            $payload = [
                                'tipo' => 'control_server',
                                "manejo" => $manejo,
                                'accion' => $accion,
                                'id_p_servicio' => $id_p_servicio,
                                'texto'     => $texto,
                                'origen' => 'server',
                                'destino' => 'shell',
                                'timestamp' => date('Y-m-d H:i:s')
                            ];
                            $equip->send(json_encode($payload));
                            echo "✅ Comando '{$accion}' enviado a {$destino}\n";
                            break;
                        }
                    }

                    if (!$found) {
                        echo "❌ Equipo '{$nombre_equipo}' no conectado.\n";
                        $from->send(json_encode([
                            'tipo'    => 'error',
                            'mensaje' => "El equipo '{$destino}' no está conectado.",
                            'origen'  => 'server'
                        ]));
                    }
                }
                if ($origen === 'equipo') {
                    $nombre_equipo = $data['nombre_equipo'] ?? null;
                    $idEquipo = null;
                    $sessionId = null;
                    switch ($accion) {

                        case 'solicitar_renovacion':
                            // 1. Buscar ID del equipo
                            $stmt = $this->conn->prepare("
                            SELECT id_equipo
                            FROM equipos
                            WHERE nombre_pc = ?
                            LIMIT 1
                        ");

                            $stmt->bind_param("s", $nombre_equipo);
                            $stmt->execute();
                            $stmt->bind_result($idEquipo);
                            $stmt->fetch();
                            $stmt->close();

                            if (!$idEquipo) {
                                $this->log("❌ No existe equipo con nombre '$nombre_equipo'");
                                break;
                            }

                            // 2. Buscar la última sesión asociada al equipo
                            $stmt = $this->conn->prepare("
                                SELECT id
                                FROM sesiones
                                WHERE id_equipo_fk = ?
                                ORDER BY id DESC
                                LIMIT 1
                            ");

                            $stmt->bind_param("i", $idEquipo);
                            $stmt->execute();
                            $stmt->bind_result($sessionId);
                            $stmt->fetch();
                            $stmt->close();

                            if (!$sessionId) {
                                $this->log("❌ No se encontró sesión activa para el equipo ID $idEquipo ($nombre_equipo)");
                                break;
                            }

                            // 3. Construir payload
                            $payload = [
                                'tipo'          => 'solicitud',
                                'nombre_equipo' => $nombre_equipo,
                                'estado'        => 'renovacion',
                                'sessionId'     => $sessionId,
                                'timestamp'     => date('Y-m-d H:i:s')
                            ];

                            // 4. Notificar dashboards
                            $this->log("📢 Notificando dashboards (renovación): " . json_encode($payload));
                            $this->notificarDashboards($payload);
                            break;
                    }
                }
                break;

            case 'solicitud':
                $accion = $data['accion'] ?? null;
                $nombre_equipo = $data['nombre_equipo'] ?? null;
                $usuario = $data['username'] ?? null;
                $mac = $data['mac_eq'] ?? null;

                if (!$nombre_equipo) {
                    $this->log("❌ solicitud sin nombre_equipo");
                    break;
                }

                switch ($accion) {

                    case 'solicitar_renovacion':
                        // Reutilizar flujo del case 'comando' → equipo
                        $this->procesarSolicitudRenovacion($nombre_equipo);
                        break;

                    case 'cerrar':
                    case 'expirado':
                    case 'renovar_clave':

                        // Enviar confirmación inicial al dashboard
                        $this->notificarDashboards([
                            'tipo' => 'solicitud_equipo',
                            'accion' => $accion,
                            'equipo' => $nombre_equipo,
                            'usuario' => $usuario
                        ]);

                        // Llamar la API
                        $this->procesarComandoAPI($accion, $usuario, $mac, $nombre_equipo);
                        break;

                    default:
                        $this->log("❓ solicitud no reconocida: " . $accion);
                        break;
                }
                break;

            case 'respuesta_solicitud':

                $accionDashboard = $data['accion'] ?? null;
                $sesionId        = $data['session'] ?? null;

                if (!$sesionId) {
                    $this->log("❌ respuesta_solicitud sin sessionId");
                    break;
                }

                // ============================================================
                // 🔍 1. Buscar datos de la sesión en base de datos
                // ============================================================
                $sql = "SELECT 
                s.id,
                s.username,
                s.id_equipo_fk,
                eq.nombre_pc,
                eq.mac_eq
            FROM sesiones s
            LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
            WHERE s.id = ? LIMIT 1";

                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("i", $sesionId);
                $stmt->execute();
                $result = $stmt->get_result();
                $sesion = $result->fetch_assoc();
                $stmt->close();

                if (!$sesion) {
                    $this->log("❌ Sesión no encontrada para ID: $sesionId");
                    break;
                }

                // Datos clave
                $username   = $sesion['username'];
                $nombre_equipo  = $sesion['nombre_pc'];
                $mac_eq     = $sesion['mac_eq'];

                $this->log("📌 respuesta_solicitud → Sesión encontrada: PC=$nombre_equipo, usuario=$username");

                // ============================================================
                // 🔁 2. Determinar acción API según respuesta del dashboard
                // ============================================================
                if ($accionDashboard === "aceptar_renovacion") {

                    $accionAPI = "renovar"; // Acción para API
                    $this->log("🔁 Renovación aprobada por dashboard para $nombre_equipo");
                } elseif ($accionDashboard === "rechazar_renovacion") {

                    $accionAPI = "finalizar"; // Cerrar sesión
                    $this->log("⛔ Renovación rechazada por dashboard para $nombre_equipo");
                } else {
                    $this->log("❓ Acción de solicitud desconocida: " . $accionDashboard);
                    break;
                }

                // ============================================================
                // 🌐 3. Llamar a la API → comando_api
                // ============================================================
                $apiPayload = [
                    'tipo'      => 'comando_api',
                    'accion'    => $accionAPI,
                    'username'  => $username,
                    'mac_eq'    => $mac_eq,
                    'nombre_equipo' => $nombre_equipo,
                    'origen'    => 'server',
                    'destino' => 'api',
                    'timestamp' => date('Y-m-d H:i:s')
                ];

                $apiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php";

                $ch = curl_init($apiUrl);

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST            => true,
                    CURLOPT_POSTFIELDS      => json_encode($apiPayload),
                    CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT         => 10
                ]);

                $respuestaApi = curl_exec($ch);
                $errorCurl    = curl_error($ch);
                curl_close($ch);

                if ($errorCurl) {
                    $this->log("❌ Error cURL API: $errorCurl");
                    break;
                }

                $res = json_decode($respuestaApi, true);

                if (!$res) {
                    $this->log("⚠️ API devolvió respuesta inválida: $respuestaApi");
                    break;
                }

                $estadoAPI  = $res['estado'] ?? 'SIN_RESPUESTA';
                $msgAPI     = $res['mensaje'] ?? 'Sin mensaje';

                $this->log("📡 API → [$estadoAPI] $msgAPI");

                // ============================================================
                // 📤 4. Enviar al PowerShell (si está conectado)
                // ============================================================
                if (isset($this->equipos[$nombre_equipo])) {
                    $this->equipos[$nombre_equipo]->send(json_encode([
                        'tipo'      => 'confirmacion_solicitud',
                        'accion'    => $accionAPI,
                        'estado'    => $estadoAPI,
                        'mensaje'   => $msgAPI,
                        'origen'    => 'server',
                        'username'  => $username
                    ]));
                }

                // ============================================================
                // 🔔 5. Notificar dashboards del resultado
                // ============================================================
                $this->notificarDashboards([
                    'tipo'      => 'resultado_solicitud',
                    'accion'    => $accionAPI,
                    'estado'    => $estadoAPI,
                    'sesionId'  => $sesionId,
                    'nombre_pc' => $nombre_equipo,
                    'usuario'   => $username,
                    'mensaje'   => $msgAPI,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);

                break;
            case 'log':
                $id = $data['id'] ?? 'Desconocido';
                echo "🧾 Log de {$id}: {$data['mensaje']}\n";

                // Opcional: retransmitir al dashboard
                foreach ($this->clients as $client) {
                    if (!isset($client->id_equipo)) {
                        $client->send(json_encode([
                            'tipo' => 'log',
                            'id' => $id,
                            'mensaje' => $data['mensaje'],
                            'timestamp'      => date("Y-m-d H:i:s") 
                        ]));
                    }
                }
                break;
            case 'confirmacion_comando':
                $accion = $data['accion'] ?? '';
                $estado = $data['estado'] ?? '';
                $mensaje = $data['mensaje'] ?? '';
                $nombre_eq = $data['nombre_equipo'] ?? 'Desconocido';
                foreach ($this->clients as $client) {
                    if (!isset($client->id_equipo)) {
                        $client->send(json_encode([
                            'tipo'      => 'proceso_comando',
                            'nombre_eq' => $nombre_eq,
                            'accion'    => $accion,
                            'resultado' => $estado,
                            'origen'    => 'server' // indicamos que lo reenvía el server
                        ]));
                    }
                }
                break;
            case 'confirmacion':
                if ($data['origen'] == 'equipo') {
                    $nombre_eq   = $data['nombre_equipo'] ?? 'Desconocido';
                    $accion      = strtolower($data['accion'] ?? '');
                    $usuario     = $data['usuario'] ?? null;
                    $mac_eq = $data['mac_eq'] ?? null;
                    $resultado  = $data['resultado'] ?? null;
                    // ======================================================
                    // 📡 Reenviar confirmación al dashboard
                    // ======================================================
                    foreach ($this->clients as $client) {
                        if (!isset($client->id_equipo)) {
                            $client->send(json_encode([
                                'tipo'      => 'proceso_comando',
                                'nombre_eq' => $nombre_eq,
                                'accion'    => $accion,
                                'resultado' => $data['resultado'] ?? 'pendiente',
                                'origen'    => 'server' // indicamos que lo reenvía el server
                            ]));
                        }
                    }
                    if ($resultado == 'ejecutando' && $accion != 'mensaje') {
                        // ======================================================
                        // 🧠 Llamada directa a la API (comando_api)
                        // ======================================================
                        $apiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php";
                        $payload = [
                            'tipo'        => 'comando_api',
                            'accion'      => $accion,
                            'username'    => $usuario,
                            'mac_eq' => $mac_eq,
                            'nombre_equipo' => $nombre_eq,
                            'origen'      => 'server', // 👈 NUEVO: indica que viene del servidor
                            'destino' => 'api'
                        ];

                        $ch = curl_init($apiUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST            => true,
                            CURLOPT_POSTFIELDS      => json_encode($payload),
                            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
                            CURLOPT_TIMEOUT         => 15
                        ]);

                        $apiResponse = curl_exec($ch);
                        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlError   = curl_error($ch);
                        curl_close($ch);

                        // ======================================================
                        // 🧾 Validar respuesta de la API
                        // ======================================================
                        if ($curlError) {
                            $this->log("⚠️ Error al contactar API: {$curlError}");
                            echo "⚠️ Fallo cURL al invocar API ({$accion})\n";
                            break;
                        }

                        $decoded = json_decode($apiResponse, true);
                        if (!is_array($decoded)) {
                            $this->log("⚠️ API devolvió respuesta no válida: {$apiResponse}");
                            echo "⚠️ API devolvió respuesta no JSON o vacía\n";
                            break;
                        }

                        // ======================================================
                        // 🧮 Validación de resultado proveniente de la API
                        // ======================================================
                        $estado  = strtoupper($decoded['estado'] ?? 'SIN_RESPUESTA');
                        $mensaje = $decoded['mensaje'] ?? 'Sin mensaje';

                        echo "📡 API respondió: [{$estado}] $nombre_eq {$mensaje}\n";
                        $this->log("📡 API → {$estado} → {$mensaje}");

                        // ======================================================
                        // 📘 Registro según tipo de acción y resultado
                        // ======================================================
                        switch ($accion) {
                            case 'finalizar':
                                if ($estado === 'FINALIZADO') {
                                    if (isset($this->equipos[$nombre_eq])) {
                                        $this->equipos[$nombre_eq]->send(json_encode([
                                            'tipo'      => 'confirmacion_comando',
                                            'accion'    => $accion,
                                            'estado'    => $estado,
                                            'mensaje'   => $mensaje,
                                            'origen'    => 'server',
                                            'mac_eq'    => $mac_eq,
                                            'username'  => $usuario
                                        ]));
                                    }
                                }
                                break;
                            case 'bloquear':
                                if (str_contains($estado, 'BLOQUEADO')) {
                                    $this->log("🚫 Sesión bloqueada correctamente para {$nombre_eq}");
                                } else {
                                    $this->log("⚠️ Error al bloquear {$nombre_eq}: {$mensaje}");
                                }
                                break;
                            case 'renovar':
                                if ($estado === 'RENOVADO_COMANDO') {
                                    if (isset($this->equipos[$nombre_eq])) {
                                        $this->equipos[$nombre_eq]->send(json_encode([
                                            'tipo'      => 'confirmacion_comando',
                                            'accion'    => $accion,
                                            'estado'    => "renovar",
                                            'mensaje'   => $mensaje,
                                            'origen'    => 'server',
                                            'mac_eq'    => $mac_eq,
                                            'username'  => $usuario
                                        ]));
                                    }
                                }
                                break;
                                break;
                            default:
                                $this->log("ℹ️ Acción no reconocida o sin manejo específico: {$accion}");
                                break;
                        }
                    } elseif ($resultado == "error") {
                        $this->log("❌ Error reportado por equipo {$nombre_eq} en acción {$accion}: {$data['mensaje']}");
                    }
                }
                break; // ← fin de case confirmacion

            case 'actualizar':
                if ($data['origen'] == 'dashboard') {
                    $from->tipoCliente = 'dashboard';
                    $this->dashboards[] = $from;
                    $this->enviarEstado($from);
                } else if ($data['origen'] == 'equipo') {
                    $from->tipoCliente = 'equipo';
                    $this->equipos[] = $from;
                    $this->enviarEstado($from);
                }
                break;
            // ========================================
            default:
                echo "❓ Tipo de mensaje desconocido: " . json_encode($data) . "\n";
                break;
        }
    }

    // ============================================================
    // 📌 CLIENTE DESCONECTADO
    // ============================================================
    public function onClose(ConnectionInterface $conn)
    {
        $this->schedulerDesbloqueos();
        $this->clients->detach($conn);

        // Eliminar de equipos si corresponde
        if ($conn->tipoCliente === 'equipo' && !empty($conn->idCliente)) {
            if (isset($this->equipos[$conn->idCliente])) {
                unset($this->equipos[$conn->idCliente]);
                $this->log("🔌 Equipo desconectado: {$conn->idCliente}");

                // Notificar a dashboards
                $payload = [
                    'tipo' => 'equipo_desconectado',
                    'id' => $conn->idCliente,
                    'timestamp' => date('Y-m-d H:i:s')
                ];

                $this->log("📢 Notificando dashboards: " . json_encode($payload));
                $this->notificarDashboards($payload);
            } else {
                $this->log("⚠️ Intento de desconexión de equipo no registrado: {$conn->idCliente}");
            }
        }


        // Eliminar de dashboards si corresponde
        if ($conn->tipoCliente === 'dashboard') {
            $key = array_search($conn, $this->dashboards, true);
            if ($key !== false) {
                unset($this->dashboards[$key]);
            }
            $this->log("📊 Dashboard desconectado ({$conn->resourceId})");
        }

        $this->log("🔴 Cliente desconectado: ({$conn->resourceId})");
    }
    // ============================================================
    // ⚠️ MANEJO DE ERRORES
    // ============================================================
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $this->log("⚠️ Error: {$e->getMessage()}");
        $conn->close();
    }

    // ============================================================
    // 📢 NOTIFICAR A TODOS LOS DASHBOARDS
    // ============================================================
    private function notificarDashboards($payload)
    {
        $mensaje = json_encode($payload);
        foreach ($this->dashboards as $dashboard) {
            if ($dashboard && method_exists($dashboard, 'send')) { // Validar objeto
                try {
                    $dashboard->send($mensaje);
                } catch (\Exception $e) {
                    $this->log("❌ Error al notificar dashboard: {$e->getMessage()}");
                }
            }
        }
    }
    // ============================================================
    // 💾 GUARDAR LOG EN BASE DE DATOS
    // ============================================================
    private function guardarLogAccion($idEquipo, $accion, $mensaje)
    {
        try {
            $sql = "INSERT INTO logs_acciones (id_equipo, accion, mensaje, fecha) 
                    VALUES (?, ?, ?, NOW())";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("sss", $idEquipo, $accion, $mensaje);
            $stmt->execute();
        } catch (\Exception $e) {
            $this->log("❌ Error al guardar log: {$e->getMessage()}");
        }
    }
    // ============================================================
    // 📊 ENVIAR ESTADO A UN CLIENTE
    // ============================================================
    private function enviarEstado($conn)
    {
        $sql = "SELECT s.id, s.username, eq.nombre_pc, s.fecha_inicio, s.fecha_final_programada, s.fecha_final_real, e.nombre_estado
        FROM sesiones s
        LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
        LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
        ORDER BY s.id DESC";
        $result = $this->conn->query($sql);
        $sesiones = [];
        while ($row = $result->fetch_assoc()) $sesiones[] = $row;
        $data = [
            'tipo' => 'estado',
            'sesiones' => $sesiones,
            'stats' => $this->getStats(),
            'equipos_conectados' => array_keys($this->equipos),
            'origen' => 'server',
        ];
        $conn->send(json_encode($data));
    }

    // ============================================================
    // 📊 ENVIAR ESTADO A TODOS
    // ============================================================
    private function enviarEstadoATodos()
    {
        $sql = "SELECT s.id, s.username, eq.nombre_pc, s.fecha_inicio, s.fecha_final_programada, s.fecha_final_real, e.nombre_estado
        FROM sesiones s
        LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
        LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
        ORDER BY s.id DESC";
        $result = $this->conn->query($sql);
        $sesiones = [];
        while ($row = $result->fetch_assoc()) $sesiones[] = $row;

        $data = [
            'tipo' => 'estado',
            'sesiones' => $sesiones,
            'stats' => $this->getStats(),
            'equipos_conectados' => array_keys($this->equipos),
            'origen' => 'server'   // <-- agregado
        ];

        foreach ($this->clients as $client) {
            $client->send(json_encode($data));
        }
    }

    // ============================================================
    // 💬 ENVIAR MENSAJE DE TEXTO
    // ============================================================
    private function enviarMensaje($texto, $destino = 'todos')
    {
        $data = ['tipo' => 'mensaje', 'texto' => $texto, 'destino' => $destino];
        if (trim($texto) === '') {
            $this->log("⚠️ Intento de enviar mensaje vacío");
            return;
        }
        if ($destino === 'todos') {
            foreach ($this->equipos as $client) {
                $client->send(json_encode($data));
            }
            $this->log("🌐 Mensaje global enviado: $texto");
            return;
        }

        if (isset($this->equipos[$destino])) {
            $this->equipos[$destino]->send(json_encode($data));
            $this->log("🎯 Mensaje enviado a $destino: $texto");
        } else {
            $this->log("⚠️ Destino '$destino' no conectado");
        }
    }
    private function getPuntoServicioPorEquipo(string $nombreEquipo)
    {
        $puntoServicio = null;
        $sql = "SELECT ps.id_p_servicio, ps.nombre_p_servicio 
            FROM equipos e
            LEFT JOIN puntos_servicios ps ON e.id_p_servicio_fk = ps.id_p_servicio
            WHERE e.nombre_pc = ?";
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $nombreEquipo);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $puntoServicio = [
                    'id' => $row['id_p_servicio'],
                    'nombre' => $row['nombre_p_servicio']
                ];
            }
            $stmt->close();
        }
        return $puntoServicio;
    }

    private function procesarComandoAPI($accion, $usuario, $mac, $nombreEquipo)
    {
        $payload = [
            'tipo' => 'comando_api',
            'accion' => $accion,
            'username' => $usuario,
            'mac_eq' => $mac,
            'origen' => 'server',
            'destino' => 'api'
        ];

        $apiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php";
        $ch = curl_init($apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($payload),
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT         => 10
        ]);

        $respuesta = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log("❌ Error API ($accion): $error");
            return;
        }

        $res = json_decode($respuesta, true);

        $estado = $res['estado'] ?? 'SIN_RESPUESTA';
        $mensaje = $res['mensaje'] ?? 'Sin mensaje';

        $this->log("📡 API ($accion) → [$estado] $mensaje");

        // Enviar al equipo si está conectado
        if (isset($this->equipos[$nombreEquipo])) {
            $this->equipos[$nombreEquipo]->send(json_encode([
                'tipo' => 'confirmacion_comando',
                'accion' => $accion,
                'estado' => $estado,
                'mensaje' => $mensaje
            ]));
        }

        // Notificar dashboards
        $this->notificarDashboards([
            'tipo' => 'proceso_comando',
            'accion' => $accion,
            'equipo' => $nombreEquipo,
            'estado' => $estado,
            'mensaje' => $mensaje
        ]);
    }

    // ============================================================
    // 📈 OBTENER ESTADÍSTICAS
    // ============================================================
    private function getStats()
    {
        $stats = ['Abierto' => 0, 'Suspendido' => 0, 'Bloqueado' => 0, 'Hibernado' => 0, 'Finalizado' => 0];
        $sql = "SELECT COALESCE(e.nombre_estado, 'Desconocido') AS nombre_estado, COUNT(*) AS total 
                FROM sesiones s
                LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                GROUP BY e.nombre_estado";
        $result = $this->conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $nombre = strtolower($row['nombre_estado']);
            $total = (int)$row['total'];

            // Mapear estados con búsqueda flexible
            if ($nombre === 'abierto') $stats['Abierto'] = $total;
            elseif ($nombre === 'suspendido') $stats['Suspendido'] = $total;
            elseif ($nombre === 'bloqueado') $stats['Bloqueado'] = $total;
            // Aceptar variantes: 'hibernado', 'hibernando', 'hibernación', etc.
            elseif (strpos($nombre, 'hibern') !== false) $stats['Hibernado'] = $total;
            elseif ($nombre === 'finalizado') $stats['Finalizado'] = $total;
        }
        return $stats;
    }
    private function procesarSolicitudRenovacion($nombreEquipo)
    {
        $idEquipo = null;
        $sessionId = null;
        // 1. Buscar ID del equipo
        $stmt = $this->conn->prepare("
        SELECT id_equipo
        FROM equipos
        WHERE nombre_pc = ?
        LIMIT 1
    ");
        $stmt->bind_param("s", $nombreEquipo);
        $stmt->execute();
        $stmt->bind_result($idEquipo);
        $stmt->fetch();
        $stmt->close();

        if (!$idEquipo) {
            $this->log("❌ No existe equipo '$nombreEquipo'");
            return;
        }

        // 2. Última sesión abierta
        $stmt = $this->conn->prepare("
        SELECT id
        FROM sesiones
        WHERE id_equipo_fk = ?
        ORDER BY id DESC
        LIMIT 1
    ");
        $stmt->bind_param("i", $idEquipo);
        $stmt->execute();
        $stmt->bind_result($sessionId);
        $stmt->fetch();
        $stmt->close();

        if (!$sessionId) {
            $this->log("❌ No se encontró sesión activa para $nombreEquipo");
            return;
        }

        // 3. Notificar dashboards
        $payload = [
            'tipo' => 'solicitud_renovacion',
            'equipo' => $nombreEquipo,
            'sessionId' => $sessionId,
            'timestamp' => date("Y-m-d H:i:s")
        ];

        $this->notificarDashboards($payload);
    }
    // ============================================================
    // 🔄 PROCESADOR DE SOLICITUD DE ESTADO
    // ============================================================
    private function procesarSolicitudEstado($from, $data)
    {
        $nombreEquipo = $data['nombre_equipo'] ?? null;
        $username = $data['username'] ?? null;
        $macAddress = $data['mac_address'] ?? null;

        if (!$username || !$macAddress) {
            $this->log("❌ Solicitud de estado sin credenciales completas");
            $from->send(json_encode([
                'tipo' => 'respuesta_estado',
                'estado' => 'Error',
                'mensaje' => 'Faltan credenciales (username o mac_address)',
                'origen' => 'server',
                'destino' => 'shell'
            ]));
            return;
        }

        $this->log("📬 Solicitud de estado recibida de: $username ($nombreEquipo)");

        // ============================================================
        // 🌐 LLAMAR A LA API REST
        // ============================================================
        $apiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php";
        $payload = [
            'tipo' => 'comando_api',
            'accion' => 'estado',
            'username' => $username,
            'mac_address' => $macAddress,
            'nombre_equipo' => $nombreEquipo,
            'origen' => 'server',
            'destino' => 'api',
            'timestamp' => date('c')
        ];
        $this->log("🌐 Llamando API con accion='estado'");

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => json_encode($payload),
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT         => 30
        ]);

        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // ============================================================
        // 📥 PROCESAR RESPUESTA DE LA API
        // ============================================================
        if ($curlError) {
            $this->log("❌ Error cURL al consultar API: $curlError");
            $from->send(json_encode([
                'tipo' => 'respuesta_estado',
                'estado' => 'Error',
                'mensaje' => "Error de conexión con el servidor: $curlError",
                'origen' => 'server',
                'destino' => 'shell'
            ]));
            return;
        }

        $decoded = json_decode($apiResponse, true);

        if (!$decoded) {
            $this->log("⚠️ API devolvió respuesta no JSON: $apiResponse");
            $from->send(json_encode([
                'tipo' => 'respuesta_estado',
                'estado' => 'Error',
                'mensaje' => 'Respuesta inválida del servidor',
                'origen' => 'server',
                'destino' => 'shell'
            ]));
            return;
        }

        $estado = $decoded['estado'] ?? 'Desconocido';
        $bloqueado_hasta = $decoded['tiempo_restante'] ?? 'Desconocido';
        $mensaje = $decoded['mensaje'] ?? 'Sin mensaje';
        $autoIniciada = $decoded['auto_iniciada'] ?? false;

        $this->log("✅ Respuesta API: estado=$estado, mensaje=$mensaje, Prueba = $bloqueado_hasta");

        // ============================================================
        // 📤 ENVIAR RESPUESTA_ESTADO AL EQUIPO
        // ============================================================
        // Respuesta base con campos obligatorios para que cliente la acepte
        $respuesta = [
            'tipo' => 'respuesta_estado',
            'origen' => 'server',
            'destino' => 'shell',
            'estado' => $estado,
            'mensaje' => $mensaje,
            'nombre_equipo' => $nombreEquipo,
            'username' => $username,
            'timestamp' => date('c'),
            'bloqueado_hasta' => $bloqueado_hasta
        ];

        // Fusionar con toda la respuesta API (sesion_id, auto_iniciada, etc)
        $respuesta = array_merge($respuesta, $decoded);
        // Garantizar que destino='shell' y tipo='respuesta_estado' no sean sobreescritos por API
        $respuesta['destino'] = 'shell';
        $respuesta['tipo'] = 'respuesta_estado';

        // Enviar por WebSocket
        $jsonRespuesta = json_encode($respuesta);
        $from->send($jsonRespuesta);
        $this->log("📤 Enviado respuesta_estado: tipo={$respuesta['tipo']}, estado={$estado}, destino=shell");

        // ============================================================
        // 🔔 NOTIFICAR DASHBOARDS SOBRE AUTO-INICIO
        // ============================================================
        if ($autoIniciada) {
            $this->log("🚀 Sesión auto-iniciada para $nombreEquipo");

            $this->notificarDashboards([
                'tipo' => 'log',
                'mensaje' => "🚀 $nombreEquipo: Sesión iniciada automáticamente para $username",
                'nivel' => 'success',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // También actualizar estadísticas
            usleep(100000); // 100ms - dar tiempo a que la BD se actualice
            $this->enviarEstadoATodos();
        }

        // ============================================================
        // 📊 NOTIFICAR DASHBOARDS DE ESTADOS IMPORTANTES
        // ============================================================
        if (in_array($estado, ['Bloqueado', 'Suspendido', 'Restringido'])) {
            $iconos = [
                'Bloqueado' => '🚫',
                'Suspendido' => '⏸️',
                'Restringido' => '⛔'
            ];

            $icono = $iconos[$estado] ?? '📊';

            $this->notificarDashboards([
                'tipo' => 'log',
                'mensaje' => "$icono $nombreEquipo: $estado - $mensaje",
                'nivel' => 'warning',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
    // Modificar el case 'registro' para equipos
    private function procesarRegistroEquipo($from, $data)
    {
        $from->tipoCliente = 'equipo';
        $from->idCliente = $data['nombre_equipo'] ?? 'Desconocido';

        // Buscar punto de servicio en BD
        $puntoServicio = $this->getPuntoServicioPorEquipo($from->idCliente);

        if ($puntoServicio) {
            $from->id_p_servicio = $puntoServicio['id'];
            $from->puntoServicio = [
                'id' => $puntoServicio['id'],
                'nombre' => $puntoServicio['nombre']
            ];
        } else {
            $from->id_p_servicio = null;
            $from->puntoServicio = [
                'id' => null,
                'nombre' => 'Desconocido'
            ];
        }

        $this->equipos[$from->idCliente] = $from;

        $this->log("🖥️ Equipo registrado: {$from->idCliente} (Punto de servicio: {$from->id_p_servicio})");

        // ============================================================
        // 🎯 OBTENER ESTADO INICIAL AUTOMÁTICAMENTE
        // ============================================================
        $username = $data['username'] ?? null;
        $macAddress = $data['mac_address'] ?? null;

        if ($username && $macAddress) {
            // Simular solicitud de estado
            $this->procesarSolicitudEstado($from, [
                'nombre_equipo' => $from->idCliente,
                'username' => $username,
                'mac_address' => $macAddress
            ]);
        } else {
            // Solo enviar confirmación de registro
            $from->send(json_encode([
                'tipo' => 'confirmacion_registro',
                'origen' => 'server',
                'usuario' => 'equipo',
                'nombre_equipo' => $from->idCliente,
                'punto_servicio' => $from->puntoServicio,
                'equipos' => array_keys($this->equipos)
            ]));
        }
    }
    private function procesarRegistroDashboard($from, $data)
    {
                        $from->nombre_equipo = $data['nombre_equipo'] ?? 'Desconocido';
                    $from->tipoCliente = 'dashboard';
                    $from->id_p_servicio = $data['id_p_servicio'] ?? null;
                    $from->puntoServicio = [
                        'id' => $from->id_p_servicio ?? null,
                        'nombre' => $data['nombre_p_servicio'] ?? 'Desconocido'
                    ];
                    $this->dashboards[$from->resourceId] = $from;
                    $this->enviarEstado($from);

                    $from->send(json_encode([
                        'tipo' => 'confirmacion_registro',
                        'origen' => 'server',
                        'usuario' => 'dashboard',
                        'nombre_equipo' => $from->nombre_equipo,
                        'punto_servicio' => $from->puntoServicio,
                        'equipos' => array_keys($this->equipos)
                    ]));

                    echo "💻 Dashboard registrado: {$from->nombre_equipo}\n";
    }
    // ============================================================
    // 🧾 LOG EN CONSOLA
    // ============================================================
    private function log($msg)
    {
        echo "[" . date("Y-m-d H:i:s") . "] $msg\n";
    }
}

// ============================================================
// 🚀 EJECUCIÓN DEL SERVIDOR
// ============================================================
$server = IoServer::factory(
    new HttpServer(new WsServer(new DashboardServer($conn))),
    8081
);

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                                                            ║\n";
echo "║     SERVIDOR WEBSOCKET AUTOPRÉSTAMOS - UNISIMÓN            ║\n";
echo "║     Escuchando en ws://localhost:8081                      ║\n";
echo "║                                                            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

$server->run();
