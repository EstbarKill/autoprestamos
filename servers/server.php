<?php
// ============================================================
// 🔌 SERVIDOR WEBSOCKET AUTOPRÉSTAMOS - UNIVERSIDAD SIMÓN BOLÍVAR
// Versión: 2.5 - Registro real de dashboards + Actualizar estable
// ============================================================

require_once __DIR__ . '/../config/db.php';
require __DIR__ . '/vendor/autoload.php';
define('JWT_SECRET_DASHBOARD', getenv('JWT_SECRET') ?: 'UNISIMON_SUPER_SECRET_2025');
require_once __DIR__ . '/functions.php';

// ============================================================
// 🌍 ESTADO GLOBAL PARA TODOS LOS CLIENTES
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
    protected $conn;
    protected $serverState;

    public function __construct($conn, &$serverState)
    {
        $this->conn = $conn;
        $this->serverState =& $serverState;

        // referencias vivas
        $this->clients    =& $this->serverState['clients'];
        $this->equipos    =& $this->serverState['equipos'];
        $this->dashboards =& $this->serverState['dashboards'];
    }

    // ============================================================
    // 🟢 NUEVA CONEXIÓN
    // ============================================================
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);

    // Inicializar todas las propiedades usadas en el flujo
    $conn->tipoCliente     = null;
    $conn->idCliente       = null;
    $conn->id_p_servicio   = null;
    $conn->sede_token      = null;
    $conn->usuario_token   = null;
    $conn->idGlobal        = null;
    $conn->nombre_equipo   = null;
    $conn->resourceId      = $conn->resourceId ?? $conn->resourceId; // por consistencia

        $this->log("🟢 Cliente conectado ({$conn->resourceId})");
    }

    // ============================================================
    // 📌 REGISTRAR DASHBOARD DE FORMA CORRECTA
    // ============================================================
    public function registrarDashboard($conn)
    {
        $this->dashboards[$conn->resourceId] =& $conn;
        $this->log("📘 Dashboard registrado en memoria ({$conn->resourceId})");
    }

    // ============================================================
    // 📩 MENSAJES RECIBIDOS
    // ============================================================
    public function onMessage(ConnectionInterface $from, $msg)
    {
$data = json_decode($msg, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $this->log("⚠️ Mensaje no JSON (".json_last_error_msg()."): $msg");
    sendJson($from, ['tipo'=>'error','mensaje'=>'JSON inválido']);
    return;
}


        switch ($data['tipo'] ?? '') {
            case 'registro':
                $serverStateRef =& $this->serverState;
                $ok = registerClient($from, $data, $serverStateRef);

                if ($ok && $from->tipoCliente === 'dashboard') {
                    $this->registrarDashboard($from);
                    $this->enviarEstado($from); // estado inicial
                }
                if (!$ok) {
                    try { $from->close(); } catch(Exception $e) {}
                }
                break;

            case 'comando':
                if ($from->tipoCliente !== 'dashboard') {
                    sendJson($from, ['tipo'=>'error','mensaje'=>'Solo dashboards pueden emitir comandos']);
                    break;
                }

                $sede = $data['id_p_servicio'] ?? null;
                if (!$sede) {
                    sendJson($from, ['tipo'=>'error','mensaje'=>'Comando requiere id_p_servicio']);
                    break;
                }

                broadcastToSede($this->serverState, $sede, $data);
                break;

            case 'log':
                $id = $data['id'] ?? 'Desconocido';
                echo "🧾 Log de {$id}: {$data['mensaje']}\n";

                foreach ($this->dashboards as $client) {
                    if ($client->id_p_servicio == $from->id_p_servicio) {
                        $client->send(json_encode([
                            'tipo'=>'log',
                            'id'=>$id,
                            'mensaje'=>$data['mensaje']
                        ]));
                    }
                }
                break;

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
                            'tipo'=>'confirmacion',
                            'nombre_eq'=>$nombre_eq,
                            'accion'=>$accion,
                            'resultado'=>$resultado,
                            'origen'=>'server'
                        ]));
                    }
                }

                if ($resultado === 'ejecutado' && $accion !== 'mensaje') {

                    $payload = [
                        'tipo'    =>'comando_api',
                        'accion'  =>$accion,
                        'username'=>$usuario,
                        'mac_eq'  =>$mac_eq,
                        'origen'  =>'server'
                    ];

                    $ch = curl_init("http://localhost/autoprestamos/prueba_equipos/api.php");
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER=>true,
                        CURLOPT_POST=>true,
                        CURLOPT_POSTFIELDS=>json_encode($payload),
                        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
                        CURLOPT_TIMEOUT=>15
                    ]);

                    curl_exec($ch);
                    curl_close($ch);
                }
                break;

            case 'hibernado':
                break;

            case 'actualizar':
                if ($from->tipoCliente !== 'dashboard') {
                    sendJson($from, ['tipo'=>'error','mensaje'=>'Solo dashboards pueden solicitar estado']);
                    break;
                }

                $this->enviarEstado($from);
                break;

            default:
                sendJson($from, ['tipo'=>'error','mensaje'=>'Formato inválido']);
                $this->log("❓ Tipo desconocido: " . json_encode($data));
                break;
        }
    }

    // ============================================================
    // 🔴 CLIENTE DESCONECTADO
    // ============================================================
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

            unset($this->equipos[$conn->idGlobal]);

            foreach ($this->dashboards as $dash) {
                if ($dash->id_p_servicio == $conn->id_p_servicio) {
                    $dash->send(json_encode([
                        'tipo'=>'equipo_desconectado',
                        'id'=>$conn->idCliente,
                        'timestamp'=>date('Y-m-d H:i:s')
                    ]));
                }
            }
        }

        $this->clients->detach($conn);

        $this->log("🔴 Cliente desconectado ({$id})");
    }

    // ============================================================
    // ❗ ERRORES
    // ============================================================
    public function onError(ConnectionInterface $conn, Exception $e)
    {
        $this->log("⚠️ Error: {$e->getMessage()}");
        $conn->close();
    }

    // ============================================================
    // 📊 ENVIAR ESTADO A UN DASHBOARD (FILTRADO)
    // ============================================================
    private function enviarEstado($conn)
    {
        $sede = (int)$conn->sede_token;

        $result = $this->conn->query("
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
            $this->log("⚠️ Error SQL enviarEstado: " . $this->conn->error);
        }

        $equiposActivos = [];
        foreach ($this->equipos as $id => $eq) {
            if ($eq->id_p_servicio == $sede) {
                $equiposActivos[] = $id;
            }
        }

        $conn->send(json_encode([
            'tipo'=>'estado',
            'sesiones'=>$sesiones,
            'stats'=>$this->getStats($sede),
            'equipos_conectados'=>$equiposActivos,
            'origen'=>'server'
        ]));
    }

    // ============================================================
    // 📈 STATS POR SEDE
    // ============================================================
    private function getStats($sede)
    {
        $stats = ['Abierto'=>0,'Suspendido'=>0,'Bloqueado'=>0,'Hibernado'=>0,'Finalizado'=>0];

        $result = $this->conn->query("
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
                elseif (strpos($n,'hibern') !== false) $stats['Hibernado'] = $t;
                elseif ($n === 'finalizado') $stats['Finalizado'] = $t;
            }
        }

        return $stats;
    }

    private function log($msg)
    {
        echo "[".date("Y-m-d H:i:s")."] $msg\n";
    }
}

// ============================================================
// 🚀 EJECUCIÓN DEL SERVIDOR
// ============================================================
$server = IoServer::factory(
    new HttpServer(new WsServer(new DashboardServer($conn, $serverState))),
    8081
);

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║    SERVIDOR WEBSOCKET AUTOPRÉSTAMOS - UNISIMÓN              ║\n";
echo "║    Escuchando en ws://localhost:8081                        ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$server->run();
