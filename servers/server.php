<?php
// ============================================================
// 🔌 SERVIDOR WEBSOCKET AUTOPRÉSTAMOS - UNIVERSIDAD SIMÓN BOLÍVAR
// Versión: 2.6 - Registro unificado (dashboard + equipos)
// ============================================================

require_once __DIR__ . '/../config/db.php';
require __DIR__ . '/vendor/autoload.php';

// Hacer la conexión mysqli accesible globalmente para functions.php
if (isset($conn) && $conn instanceof mysqli) {
    $GLOBALS['db'] = $conn;
}

// require funciones (que incluyen jwt.php internamente)
require_once __DIR__ . '/functions.php';

// ============================================================
// ESTADO GLOBAL
// ============================================================
$serverState = [
    'clients'    => new SplObjectStorage(),
    'equipos'    => [],
    'dashboards' => []
];

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;

class DashboardServer implements MessageComponentInterface
{
    protected $clients;
    protected $equipos;
    protected $dashboards;
    protected $db;
    protected $serverState;

    public function __construct($dbConn, &$serverState)
    {
        $this->db = $dbConn;
        $this->serverState = &$serverState;
        $this->clients    = &$this->serverState['clients'];
        $this->equipos    = &$this->serverState['equipos'];
        $this->dashboards = &$this->serverState['dashboards'];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        // Inicializar propiedades
        $conn->tipoCliente     = null;
        $conn->idCliente       = null;
        $conn->id_p_servicio   = null;
        $conn->sede_token      = null;
        $conn->usuario_token   = null;
        $conn->idGlobal        = null;
        $conn->nombre_equipo   = null;
        $this->log("🟢 Cliente conectado ({$conn->resourceId})");
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("⚠️ Mensaje no JSON (" . json_last_error_msg() . "): $msg");
            sendJson($from, ['tipo' => 'error', 'mensaje' => 'JSON inválido']);
            return;
        }

        switch ($data['tipo'] ?? '') {
            case 'registro':
                // Si viene del DASHBOARD → generar token ANTES de validar
                if (($data['origen'] ?? '') === 'dashboard') {

                    // Verificar datos mínimos
                    $usuario = $data['usuario'] ?? 'admin';
                    $sede    = isset($data['id_p_servicio']) ? (int)$data['id_p_servicio'] : null;

                    if (!$sede) {
                        $this->log("❌ Dashboard sin sede enviada");
                        sendJson($from, ['tipo' => 'error', 'mensaje' => 'Sede no enviada para dashboard']);
                        try {
                            $from->close();
                        } catch (Exception $e) {
                        }
                        break;
                    }

                    // 🔥 Generar token DASHBOARD
                    $tokenGenerado = generarTokenDashboard($usuario, $sede);
                    $data['token'] = $tokenGenerado;

                    $this->log("🔐 Token dashboard generado automáticamente para usuario {$usuario}, sede {$sede}");
                }

                // ===== VALIDAR REGISTRO CON TOKEN ACTUALIZADO =====
                $res = registerClient($from, $data, $this->serverState);

                if (!isset($res['success']) || !$res['success']) {
                    $this->log("❌ Registro fallido: " . ($res['mensaje'] ?? 'sin mensaje'));
                    sendJson($from, ['tipo' => 'error', 'mensaje' => $res['mensaje'] ?? 'Registro fallido']);
                    try {
                        $from->close();
                    } catch (Exception $e) {
                    }
                    break;
                }

                // ==================
                //  DASHBOARD
                // ==================
                if ($res['tipo'] === 'dashboard') {

                    $from->tipoCliente = 'dashboard';
                    $from->usuario_token = $res['payload']['usuario'] ?? null;
                    $from->sede_token = $res['payload']['sede'] ?? null;
                    $from->id_p_servicio = (int)($res['payload']['id_p_servicio_enviado'] ?? $from->sede_token);
                    $from->nombre_equipo = $res['payload']['nombre_equipo'] ?? ("dash_{$from->resourceId}");

                    // Guardar conexión
                    $this->dashboards[$from->resourceId] = $from;

                    $this->log("📘 Dashboard registrado ({$from->resourceId}) - usuario: {$from->usuario_token} - sede: {$from->id_p_servicio}");

                    // 🔥 Enviar token recién generado al dashboard
                    sendJson($from, [
                        'tipo' => 'confirmacion_registro',
                        'registro' => 'dashboard',
                        'origen' => 'server',
                        'nombre_eq' => $from->nombre_equipo,
                        'sede' => $from->id_p_servicio,
                        'token' => $data['token']
                    ]);

                    // Enviar estado inicial
                    $this->enviarEstado($from);
                } elseif ($res['tipo'] === 'equipo') {
                    // Registrar equipo en memoria
                    $from->tipoCliente = 'equipo';
                    $from->id_equipo = $res['payload']['id_equipo'];
                    $from->idCliente = $res['payload']['nombre_pc'];
                    $from->mac_address = $res['payload']['mac_equipo'];
                    $from->id_p_servicio = $res['payload']['id_p_servicio'];
                    $from->nombre_sede = $res['payload']['nombre_p_servicio'];

                    $key = "{$from->id_equipo}_{$from->id_p_servicio}";
                    $from->idGlobal = $key;
                    $this->equipos[$key] = $from;

                    $this->log("🖥️ Equipo conectado y registrado en memoria: {$from->idCliente} (sede {$from->id_p_servicio})");

                    // Confirmar al equipo incluyendo token generado
                    sendJson($from, [
                        'tipo' => 'confirmacion_registro',
                        'registro' => 'equipo',
                        'origen' => 'server',
                        'nombre_eq' => $from->idCliente,
                        'sede' => $from->id_p_servicio,
                        'token' => $res['payload']['token_equipo'] ?? null
                    ]);

                    // Notificar dashboards de esta sede que un equipo se conectó
                    foreach ($this->dashboards as $dash) {
                        if ($dash->id_p_servicio == $from->id_p_servicio) {
                            $dash->send(json_encode([
                                'tipo' => 'equipo_conectado',
                                'id' => $from->idCliente,
                                'nombre' => $from->idCliente,
                                'sede' => $from->id_p_servicio,
                                'timestamp' => date('Y-m-d H:i:s')
                            ]));
                        }
                    }
                }
                break;
            case 'comando':
                $destinoEquipo = $data['nombre_eq'] ?? null;
                $origen = $data['origen'] ?? '';
                if ($origen === 'dashboard') {
                switch ($data['accion'] ?? '') {
                    case 'mensaje':
                        $manejo = 'mensaje';
                        break;
                    case 'finalizar':
                    case 'suspender':
                    case 'bloquear':
                        $manejo = 'comandos';
                        break;
                    case 'info':
                        $manejo = 'info';
                }

                if ($from->tipoCliente !== 'dashboard') {
                    sendJson($from, ['tipo' => 'error', 'mensaje' => 'Solo dashboards pueden emitir comandos']);
                    break;
                }

                $sede = $data['id_p_servicio'] ?? null;

                if (!$sede) {
                    sendJson($from, ['tipo' => 'error', 'mensaje' => 'Comando requiere id_p_servicio']);
                    break;
                }

                // Normalizar/enriquecer el payload que se enviará a los equipos
                $payloadToEquipos = [
                    'tipo' => 'control_server',
                    'destino'=>'shell',
                    'destino_equipo'=> $destinoEquipo,     // PowerShell espera tipo control_server en su cola
                    'manejo' => $manejo,           // coincide con el switch en Start-CommandQueueMonitor
                    'accion' => $data['accion'] ?? ($data['comando'] ?? null),
                    'mensaje' => $data['mensaje'] ?? null,
                    'corr'  => $data['corr'] ?? null,
                    'origen' => 'server',
                    'emisor' => $from->nombre_equipo ?? ('dash_' . $from->resourceId),
                    'timestamp' => date('Y-m-d H:i:s')
                ];

                // Añadir campos extra si vienen (preservar detalles)
                foreach (['meta', 'params'] as $k) {
                    if (isset($data[$k])) $payloadToEquipos[$k] = $data[$k];
                }
                // comprobar si hay equipos conectados en esa sede
                $found = false;
                foreach ($this->equipos as $k => $eq) {
                    if (isset($eq->id_p_servicio) && (int)$eq->id_p_servicio === (int)$sede) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->log("⚠️ No hay equipos conectados en la sede {$sede} para recibir el comando.");
                    sendJson($from, ['tipo' => 'error', 'mensaje' => "No hay equipos conectados en la sede {$sede}"]);
                    break;
                }

                // Enviar solamente a equipos de la sede (no a dashboards)
                // --- cambio: enviar a equipo específico si se indicó destino ---


// Si destino explícito y no es 'todos', intentar enviar solo a ese equipo
if ($destinoEquipo && strtolower($destinoEquipo) !== 'todos') {
    // buscar en $this->equipos la conexión cuyo idCliente coincide
    $sent = false;
    foreach ($this->equipos as $k => $eqConn) {
        // idCliente almacena el nombre_pc según el registro
        if (isset($eqConn->idCliente) && strcasecmp($eqConn->idCliente, $destinoEquipo) === 0) {
            $this->log("📤 Enviando comando solo a equipo {$destinoEquipo} en sede {$sede}");
            try {
                $eqConn->send(json_encode($payloadToEquipos));
                $sent = true;
            } catch (\Exception $e) {
                $this->log("⚠️ Error enviando a equipo {$destinoEquipo}: " . $e->getMessage());
            }
            break;
        }
    }
    if (!$sent) {
        // Si no lo encontramos por idCliente, intentar buscar por id_equipo o nombre alterno
        foreach ($this->equipos as $k => $eqConn) {
            if (isset($eqConn->id_equipo) && strcasecmp($eqConn->id_equipo, $destinoEquipo) === 0) {
                try { $eqConn->send(json_encode($payloadToEquipos)); $sent = true; break; } catch (\Exception $e){}
            }
        }
    }

    if (!$sent) {
        $this->log("⚠️ No se encontró equipo destino '{$destinoEquipo}' en la sede {$sede}. Haciendo broadcast como fallback.");
        broadcastToSede($this->serverState, $sede, $payloadToEquipos);
    }
} else {
    // behavior previo: broadcast a toda la sede
    broadcastToSede($this->serverState, $sede, $payloadToEquipos);
}

                $this->log("📤 Comando enviado a sede {$sede}: " . json_encode($payloadToEquipos));
                
                    }
        break;
            case 'log':
                $id = $data['id'] ?? 'Desconocido';
                echo "🧾 Log de {$id}: {$data['mensaje']}\n";

                foreach ($this->dashboards as $client) {
                    if ($client->id_p_servicio == $from->id_p_servicio) {
                        $client->send(json_encode([
                            'tipo' => 'log',
                            'id' => $id,
                            'mensaje' => $data['mensaje']
                        ]));
                    }
                }
                break;
            case 'confirmacion_comando':
                $id = $data['id'] ??'';
                echo '';
                foreach ($this->dashboards as $client) {
                    if ($client->id_p_servicio == $from->id_p_servicio) {
                        $client->send(json_encode([
                            'tipo' => 'confirmacion_comando',
                            'id' => $id,
                            'accion' => $data['accion'] ?? '',
                            'resultado' => $data['resultado'] ?? '',
                            'origen' => 'server'
                        ]));
                    }
                }
            case 'confirmacion':
                if ($data['origen'] !== 'equipo') break;

                $nombre_eq = $data['nombre_eq'] ?? 'Desconocido';
                $accion    = strtolower($data['accion'] ?? '');
                $usuario   = $data['usuario'] ?? null;
                $mac_eq    = $data['mac_eq'] ?? null;
                $resultado = $data['resultado'] ?? null;

                foreach ($this->dashboards as $client) {
                    if ($client->id_p_servicio == $from->id_p_servicio) {
                        $client->send(json_encode([
                            'tipo' => 'confirmacion',
                            'nombre_eq' => $nombre_eq,
                            'accion' => $accion,
                            'resultado' => $resultado,
                            'origen' => 'server'
                        ]));
                    }
                }

                if ($resultado === 'ejecutado' && $accion !== 'mensaje') {

                    $payload = [
                        'tipo'    => 'comando_api',
                        'accion'  => $accion,
                        'username' => $usuario,
                        'mac_address'  => $mac_eq,
                        'origen'  => 'server'
                    ];

                    $ch = curl_init("http://localhost/autoprestamos/prueba_equipos/api.php");
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($payload),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_TIMEOUT => 15
                    ]);

                    curl_exec($ch);
                    curl_close($ch);
                }
                break;

            case 'hibernado':
                break;

            case 'actualizar':
                if ($from->tipoCliente !== 'dashboard') {
                    sendJson($from, ['tipo' => 'error', 'mensaje' => 'Solo dashboards pueden solicitar estado']);
                    break;
                }

                $this->enviarEstado($from);
                break;

            default:
                sendJson($from, ['tipo' => 'error', 'mensaje' => 'Formato inválido']);
                $this->log("❓ Tipo desconocido: " . json_encode($data));
                break;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $id = $conn->resourceId;

        // eliminar dashboard
        if (isset($this->dashboards[$id])) {
            unset($this->dashboards[$id]);
            $this->log("📕 Dashboard eliminado ($id)");
        }

        // eliminar equipos
        if ($conn->tipoCliente === 'equipo' && isset($conn->idGlobal)) {
            if (isset($this->equipos[$conn->idGlobal])) {
                unset($this->equipos[$conn->idGlobal]);
            }

            foreach ($this->dashboards as $dash) {
                if ($dash->id_p_servicio == $conn->id_p_servicio) {
                    $dash->send(json_encode([
                        'tipo' => 'equipo_desconectado',
                        'id' => $conn->idCliente,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]));
                }
            }
        }

        $this->clients->detach($conn);

        $this->log("🔴 Cliente desconectado ({$id})");
    }

    public function onError(ConnectionInterface $conn, Exception $e)
    {
        $this->log("⚠️ Error: {$e->getMessage()}");
        try {
            $conn->close();
        } catch (\Exception $_) {
        }
    }

    private function enviarEstado($conn)
    {
        $sede = (int)$conn->sede_token;

        $result = $this->db->query("
            SELECT s.id, s.username, eq.nombre_pc, s.fecha_inicio,
                   s.fecha_final_programada, s.fecha_final_real, e.nombre_estado,
                   eq.id_p_servicio_fk AS id_p_servicio
            FROM sesiones s
            LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
            LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
            WHERE eq.id_p_servicio_fk = {$sede}
            ORDER BY s.id DESC
        ");

        $sesiones = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) $sesiones[] = $row;
        } else {
            $this->log("⚠️ Error SQL enviarEstado: " . $this->db->error);
        }

        $equiposActivos = [];
        foreach ($this->equipos as $id => $eq) {
            if ($eq->id_p_servicio == $sede) {
                $equiposActivos[] = $id;
            }
        }

        $conn->send(json_encode([
            'tipo' => 'estado',
            'sesiones' => $sesiones,
            'stats' => $this->getStats($sede),
            'equipos_conectados' => $equiposActivos,
            'origen' => 'server'
        ]));
    }

    private function getStats($sede)
    {
        $stats = ['Abierto' => 0, 'Suspendido' => 0, 'Bloqueado' => 0, 'Hibernado' => 0, 'Finalizado' => 0];

        $result = $this->db->query("
            SELECT COALESCE(e.nombre_estado, 'Desconocido') AS nombre_estado,
                   COUNT(*) AS total
            FROM sesiones s
            LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
            LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
            WHERE eq.id_p_servicio_fk = {$sede}
            GROUP BY e.nombre_estado
        ");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $n = strtolower($row['nombre_estado']);
                $t = (int)$row['total'];

                if ($n === 'abierto') $stats['Abierto'] = $t;
                elseif ($n === 'suspendido') $stats['Suspendido'] = $t;
                elseif ($n === 'bloqueado') $stats['Bloqueado'] = $t;
                elseif (strpos($n, 'hibern') !== false) $stats['Hibernado'] = $t;
                elseif ($n === 'finalizado') $stats['Finalizado'] = $t;
            }
        }

        return $stats;
    }

    private function log($msg)
    {
        echo "[" . date("Y-m-d H:i:s") . "] $msg\n";
    }
}

// EJECUCIÓN DEL SERVIDOR
$server = IoServer::factory(
    new HttpServer(new WsServer(new DashboardServer($conn, $serverState))),
    8081
);

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║    SERVIDOR WEBSOCKET AUTOPRÉSTAMOS - UNISÓN               ║\n";
echo "║    Escuchando en ws://localhost:8081                       ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$server->run();
