<?php
// servers/server.php
require_once __DIR__ . '/../prueba_equipos/db.php';
require_once __DIR__ . '/../prueba_equipos/utils.php';
require __DIR__ . '/vendor/autoload.php';
// Ejecuta el servidor en segundo plano si no está corriendo

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;

class DashboardServer implements MessageComponentInterface {
    protected $clients;
    protected $conn;
    protected $equipos; // mapping idEquipo => Connection

    public function __construct($conn) {
        $this->clients = new \SplObjectStorage;
        $this->equipos = []; // associative array map
        $this->conn = $conn;
        echo "🚀 Servidor WebSocket Unisimón activo en ws://localhost:8080\n";
    }

    // === CONEXIÓN ABIERTA ===
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $this->log("🟢 Cliente conectado: ({$conn->resourceId})");
        $this->enviarEstado($conn);
    }
    // === LOG BONITO EN CONSOLA ===
    public function log($msg) {
        echo "[" . date("H:i:s") . "] $msg\n";
    }

    // === MENSAJE ENTRANTE ===
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) return;

        if (!empty($data['accion']) && $data['accion'] === 'Register' && !empty($data['id'])) {
            $idEquipo = (string)$data['id'];
            $this->equipos[$idEquipo] = $from;
            $this->log("🔗 Equipo registrado: $idEquipo (resId {$from->resourceId})");
            $from->send(json_encode(["tipo"=>"info","mensaje"=>"Registrado","id"=>$idEquipo]));
            return;
        }

        switch ($data['accion'] ?? $data['tipo'] ?? '') {
            case 'getEstado':
            case 'dashboard':
                $this->enviarEstado($from);
                break;

            case 'mensaje':
                $this->enviarMensaje($data['mensaje'] ?? '', $data['destino'] ?? 'todos');
                break;

            case 'actualizar':
                $this->enviarEstadoATodos();
                break;

            case 'comando':
                $this->enviarComando($data['comando'] ?? '', $data['destino'] ?? 'todos');
                break;

            default:
                $this->log("📩 Mensaje no reconocido: " . json_encode($data));
                break;
        }
    }
    // === CONEXIÓN CERRADA ===


    // === CLIENTE DESCONECTADO ===
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        foreach ($this->equipos as $id => $c) {
            if ($c === $conn) {
                unset($this->equipos[$id]);
                $this->log("🔌 Equipo desconectado: $id");
                break;
            }
        }
        $this->log("🔴 Cliente desconectado: ({$conn->resourceId})");
    }

    // === ERRORES ===
    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->log("⚠️ Error: {$e->getMessage()}");
        $conn->close();
    }
    // === ESTADO DE SESIONES ===
    private function enviarEstado($conn) {
        $sql = "SELECT s.id, s.username, s.fecha_inicio, s.fecha_final_programada, e.nombre_estado
                FROM sesiones s
                LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                ORDER BY s.id DESC";
        $result = $this->conn->query($sql);
        $sesiones = [];
        while ($row = $result->fetch_assoc()) $sesiones[] = $row;

        $data = ["tipo" => "estado", "sesiones" => $sesiones, "stats" => $this->getStats()];
        $conn->send(json_encode($data));
    }

    private function enviarEstadoATodos() {
        $sql = "SELECT s.id, s.username, s.fecha_inicio, s.fecha_final_programada, e.nombre_estado
                FROM sesiones s
                LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                ORDER BY s.id DESC";
        $result = $this->conn->query($sql);
        $sesiones = [];
        while ($row = $result->fetch_assoc()) $sesiones[] = $row;

        $data = ["tipo" => "estado", "sesiones" => $sesiones, "stats" => $this->getStats()];
        foreach ($this->clients as $client) {
            $client->send(json_encode($data));
        }
    }

    private function enviarMensaje($texto, $destino = 'todos') {
        $data = ["tipo" => "mensaje", "texto" => $texto, "destino" => $destino];
        if ($destino === 'todos') {
            foreach ($this->clients as $client) $client->send(json_encode($data));
            $this->log("🌍 Mensaje global enviado: $texto");
            return;
        }
        if (isset($this->equipos[$destino])) {
            $this->equipos[$destino]->send(json_encode($data));
            $this->log("🎯 Mensaje enviado a $destino: $texto");
        } else {
            $this->log("⚠️ Destino '$destino' no conectado");
        }
    }

    private function enviarComando($comando, $destino = 'todos') {
        $payload = ["tipo"=>"comando","comando"=>$comando];
        if ($destino === 'todos') {
            foreach ($this->equipos as $cli) $cli->send(json_encode($payload));
            $this->log("🌍 Comando enviado a todos: $comando");
            return;
        }
        if (isset($this->equipos[$destino])) {
            $this->equipos[$destino]->send(json_encode($payload));
            $this->log("🎯 Comando '$comando' enviado a $destino");
        } else {
            $this->log("⚠️ Destino '$destino' no conectado");
        }
    }

    // === ESTADÍSTICAS ===
    private function getStats() {
        $stats = ["Abierto" => 0, "Suspendido" => 0, "Bloqueado" => 0, "Finalizado" => 0];
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
}

$server = IoServer::factory(
    new HttpServer(new WsServer(new DashboardServer($conn))),
    8080
);

$server->run();