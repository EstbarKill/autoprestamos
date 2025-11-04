<?php
// ============================================================
// 🔌 SERVIDOR WEBSOCKET AUTOPRÉSTAMOS - UNIVERSIDAD SIMÓN BOLÍVAR
// Versión: 2.1 - Con confirmaciones de comandos en tiempo real
// ============================================================

require_once __DIR__ . '/../dashboard-unisimon/db.php';
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
                echo "💻 Dashboard registrado: {$from->nombre_equipo}\n";
                $this->enviarEstado($from);
                $from->send(json_encode([
                    'tipo' => 'equipos_conectados',
                    'equipos' => array_keys($this->equipos)
                ]));
                }
                if ($data['origen'] == 'equipo') {
                $from->tipoCliente = 'equipo';
                $from->idCliente = $data['nombre_equipo'] ?? 'Desconocido';
                $this->equipos[$from->idCliente] = $from;
                echo "🖥️ Equipo registrado: {$from->idCliente}\n";
                }
                break;
            case 'actualizar':
                if ($data['origen'] == 'dashboard') {
                $from->tipoCliente = 'dashboard';
                $this->dashboards[] = $from;
                echo "📊 Dashboard conectado: ID:({$from->resourceId})\n";
                $this->enviarEstado($from);
                }
                if ($data['origen'] == 'equipo') {
                $from->tipoCliente = 'equipo';
                $from->idCliente = $data['nombre_equipo'] ?? 'Desconocido';
                $this->equipos[$from->idCliente] = $from;
                echo "🖥️ Equipo conectado: {$from->idCliente}\n";
                }
                break;
            case 'comando':
                $accion = $data['accion'] ?? 'undefined';
                $nombre_pc = $data['nombre_eq'] ?? null;

                if (!$nombre_pc) {
                    echo "⚠️ No se especificó ID de destino.\n";
                    return;
                }

                echo "⚡ Enviando comando '{$accion}' a equipo '{$nombre_pc}'...\n";
                $found = false;

                foreach ($this->equipos as $equip) {

                    if (isset($equip->idCliente) && $equip->idCliente === $nombre_pc) {
                        echo "Revisando equipo conectado: {$equip->idCliente}\n";
                        $found = true;
                        $payload = [
                            'tipo' => 'control_server',
                            'accion' => $accion,
                            'origen' => 'server',
                            'timestamp' => date('Y-m-d H:i:s')
                        ];
                        $equip->send(json_encode($payload));
                        echo "✅ Comando '{$accion}' enviado a {$nombre_pc}\n";
                        break;
                    }
                }

                if (!$found) {
                    echo "❌ Equipo '{$nombre_pc}' no conectado.\n";
                    $from->send(json_encode([
                        'tipo' => 'error',
                        'mensaje' => "Equipo '$nombre_pc' no está conectado al servidor."
                    ]));
                }
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
            case 'finalizar':
                $idDestino = $data['nombre_eq'] ?? null;
                if (!$idDestino) {
                    $from->send(json_encode([
                        'tipo' => 'error',
                        'mensaje' => 'ID de equipo no especificado para finalizar.'
                    ]));
                    break;
                }

                if (isset($this->equipos[$idDestino])) {
                    $payload = [
                        'tipo' => 'control',
                        'accion' => 'finalizar',
                        'mensaje' => 'Cierre de sesión solicitado por el administrador',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                    $this->equipos[$idDestino]->send(json_encode($payload));

                    $this->log("🛑 Comando 'finalizar' enviado al equipo '$idDestino'");

                    // Confirmar al dashboard
                    $from->send(json_encode([
                        'tipo' => 'comando_enviado',
                        'accion' => 'finalizar',
                        'id' => $idDestino,
                        'estado' => 'enviado',
                        'timestamp' => date('Y-m-d H:i:s')
                    ]));
                } else {
                    $this->log("⚠️ Equipo '$idDestino' no conectado para finalizar");
                    $from->send(json_encode([
                        'tipo' => 'error',
                        'mensaje' => "El equipo '$idDestino' no está conectado al servidor"
                    ]));
                }
                break;
            case 'confirmacion':
                $id = $data['nombre_eq'] ?? 'Desconocido';
                echo "✅ Confirmación de {$id}: {$data['accion']}\n";

                // Reenviar confirmación al dashboard
                foreach ($this->clients as $client) {
                    if (!isset($client->id_equipo)) {
                        $client->send(json_encode([
                            'tipo' => 'confirmacion',
                            'id' => $id,
                            'accion' => $data['accion'],
                            'resultado' => $data['resultado'] ?? 'ejecutado'
                        ]));
                    }
                }
                break;

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
            'equipos_conectados' => array_keys($this->equipos)
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
            'equipos_conectados' => array_keys($this->equipos)
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

    // ============================================================
    // 📈 OBTENER ESTADÍSTICAS
    // ============================================================
    private function getStats()
    {
        $stats = ['Abierto' => 0, 'Suspendido' => 0, 'Bloqueado' => 0, 'Finalizado' => 0];
        $sql = "SELECT e.nombre_estado, COUNT(*) AS total 
                FROM sesiones s
                LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                GROUP BY e.nombre_estado";
        $result = $this->conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $estado = $row['nombre_estado'];
            if (isset($stats[$estado])) $stats[$estado] = (int)$row['total'];
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
