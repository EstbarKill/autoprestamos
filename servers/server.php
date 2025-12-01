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
    protected $clients;
    protected $equipos;       // Clientes PowerShell registrados
    protected $dashboards;    // Dashboards web conectados
    protected $conn;
    protected $ultimoEnvioEstado = 0;

    public function __construct($conn)
    {
        $this->clients = new \SplObjectStorage;
        $this->equipos = [];
        $this->dashboards = [];
        $this->conn = $conn;
    }

    // ============================================================
    // 🟢 NUEVA CONEXIÓN
    // ============================================================
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $conn->tipoCliente = null; // 'equipo' o 'dashboard'
        $conn->idCliente = null;
        $this->log("🟢 Cliente conectado: ({$conn->resourceId})");
    }

    // ============================================================
    // 📩 MENSAJE RECIBIDO
    // ============================================================
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = @json_decode($msg, true);
        if (!$data) {
            $this->log("⚠️ Mensaje no JSON: $msg");
            return;
        }
        // Primary routing by tipo (preferred) or accion
        switch ($data['tipo'] ?? '') {
            // ========================================
            // 🖥️ Registro de cliente PowerShell
            // ========================================
            case 'registro':
                if ($data['origen'] == 'dashboard') {
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
                if ($data['origen'] == 'equipo') {
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
                    echo "🖥️ Equipo registrado: {$from->idCliente} con punto de servicio: {$from->id_p_servicio}\n";
                    $this->enviarEstado($from);
                    $from->send(json_encode([
                        'tipo' => 'confirmacion_registro',
                        'origen' => 'server',
                        'usuario' => 'equipo',
                        'equipos' => array_keys($this->equipos)
                    ]));
                }

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
                }


                break;
            case 'respuesta_solicitud':

                $accionDashboard = $data['action'] ?? null;
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
                eq.mac_equipo
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
                $nombre_pc  = $sesion['nombre_pc'];
                $mac_eq     = $sesion['mac_eq'];

                $this->log("📌 respuesta_solicitud → Sesión encontrada: PC=$nombre_pc, usuario=$username");

                // ============================================================
                // 🔁 2. Determinar acción API según respuesta del dashboard
                // ============================================================
                if ($accionDashboard === "aceptar_renovacion") {

                    $accionAPI = "renovar"; // Acción para API
                    $this->log("🔁 Renovación aprobada por dashboard para $nombre_pc");
                } elseif ($accionDashboard === "rechazar_renovacion") {

                    $accionAPI = "finalizar"; // Cerrar sesión
                    $this->log("⛔ Renovación rechazada por dashboard para $nombre_pc");
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
                    'origen'    => 'server'
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
                if (isset($this->equipos[$nombre_pc])) {
                    $this->equipos[$nombre_pc]->send(json_encode([
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
                    'nombre_pc' => $nombre_pc,
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
                            'mensaje' => $data['mensaje']
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
                            'origen'      => 'server' // 👈 NUEVO: indica que viene del servidor
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
                        $tipo   = $decoded['tipo'] ?? 'undefined';
                        $mensaje = $decoded['mensaje'] ?? 'Sin mensaje';

                        echo "📡 API respondió: [{$estado}] $nombre_eq {$mensaje}\n";
                        $this->log("📡 API → {$estado} → {$mensaje}");

                        // ======================================================
                        // 📘 Registro según tipo de acción y resultado
                        // ======================================================
                        if($tipo === 'confirmacion_comando'){
                        switch ($accion) {
                           
                            case 'finalizar':
                                if ($estado === 'Finalizado_comando') {
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

                                break;
                            case 'renovar':
                                if ($estado === 'Renovado_comando') {
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
                            default:
                                $this->log("ℹ️ Acción no reconocida o sin manejo específico: {$accion}");
                                break;
                        }
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
        $this->clients->detach($conn);

        // Eliminar de equipos si corresponde
        if ($conn->tipoCliente === 'equipo' && $conn->idCliente) {
            unset($this->equipos[$conn->idCliente]);
            $this->log("🔌 Equipo desconectado: {$conn->idCliente}");

            // Notificar a dashboards
            $this->notificarDashboards([
                'tipo' => 'equipo_desconectado',
                'id' => $conn->idCliente,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
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
            try {
                $dashboard->send($mensaje);
            } catch (\Exception $e) {
                $this->log("❌ Error al notificar dashboard: {$e->getMessage()}");
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
        $sql = "SELECT s.id, s.username, s.nombre_pc, s.fecha_inicio, s.fecha_final_programada, s.fecha_final_real, e.nombre_estado
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
    // ============================================================
    // 📈 OBTENER ESTADÍSTICAS
    // ============================================================
    private function getStats()
    {
        $stats = ['Abierto' => 0, 'Suspendido' => 0, 'Bloqueado' => 0, 'Finalizado' => 0];
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
