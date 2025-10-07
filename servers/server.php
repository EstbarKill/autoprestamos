<?php
// ========================================
// 🔰 SERVIDOR WEBSOCKET UNISIMÓN (Ratchet)
// ========================================

require_once __DIR__ . '/../prueba_equipos/db.php';
require_once __DIR__ . '/../prueba_equipos/utils.php';

// Cargar autoload de Composer (Ratchet)
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;

class DashboardServer implements MessageComponentInterface {
    protected $clients;
    protected $conn;

    public function __construct($conn) {
        $this->clients = new \SplObjectStorage;
        $this->conn = $conn;
        echo "🚀 Servidor WebSocket Unisimón activo en ws://localhost:8080\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "🟢 Cliente conectado: ({$conn->resourceId})\n";
        $this->enviarEstado($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) return;

        switch ($data['accion'] ?? '') {
            case 'getEstado':
                $this->enviarEstado($from);
                break;

            case 'mensaje':
                $texto = $data['mensaje'] ?? '';
                $destino = $data['destino'] ?? 'todos';
                $this->enviarMensaje($texto, $destino);
                break;

            case 'actualizar':
                $this->enviarEstadoATodos();
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "🔴 Cliente desconectado: ({$conn->resourceId})\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "⚠️ Error: {$e->getMessage()}\n";
        $conn->close();
    }

    // === Funciones internas ===

    private function enviarEstado($conn) {
        $sql = "SELECT s.id, s.username, s.fecha_inicio, s.fecha_final_programada, e.nombre_estado
                FROM sesiones s
                LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                ORDER BY s.id DESC";
        $result = $this->conn->query($sql);
        $sesiones = [];
        while ($row = $result->fetch_assoc()) $sesiones[] = $row;

        $data = [
            "tipo" => "estado",
            "sesiones" => $sesiones,
            "stats" => $this->getStats()
        ];

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

        $data = [
            "tipo" => "estado",
            "sesiones" => $sesiones,
            "stats" => $this->getStats()
        ];

        foreach ($this->clients as $client) {
            $client->send(json_encode($data));
        }
    }

    private function enviarMensaje($texto, $destino = 'todos') {
        $data = [
            "tipo" => "mensaje",
            "texto" => $texto,
            "destino" => $destino
        ];

        foreach ($this->clients as $client) {
            $client->send(json_encode($data));
        }
        echo "💬 Mensaje enviado: $texto → $destino\n";
    }

    private function getStats() {
        $stats = ["Abierto" => 0, "Suspendido" => 0, "Bloqueado" => 0, "Finalizado" => 0];
        $sql = "SELECT e.nombre_estado, COUNT(*) AS total 
                FROM sesiones s
                LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                GROUP BY e.nombre_estado";
        $result = $this->conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $estado = $row['nombre_estado'];
            if (isset($stats[$estado])) $stats[$estado] = $row['total'];
        }
        return $stats;
    }
}

// === Iniciar servidor ===
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new DashboardServer($conn)
        )
    ),
    8080
);

$server->run();
