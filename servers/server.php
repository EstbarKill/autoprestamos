<?php
// ========================================
// 🔰 SERVIDOR WEBSOCKET UNISIMÓN (Ratchet)
// ========================================

require_once __DIR__ . '/../prueba_equipos/db.php';
require_once __DIR__ . '/../prueba_equipos/utils.php';
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;

class DashboardServer implements MessageComponentInterface {
    protected $clients;
    protected $conn;
    protected $equipos = [];
    protected $estados = [];

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

private function broadcastMonitor() {
    $equipos = [];
    foreach ($this->estados as $id => $info) {
        $equipos[] = [
            "id" => $id,
            "estado" => $info['estado'],
            "ultimo_ping" => $info['hora']
        ];
    }

    $payload = json_encode(["tipo" => "monitor", "equipos" => $equipos]);

    foreach ($this->clients as $client) {
        $client->send($payload);
    }
}


public function onMessage(ConnectionInterface $from, $msg) {
    $data = json_decode($msg, true);
    if (!$data) return;

    switch ($data['accion'] ?? '') {
        case 'Register':
            $id = $data['id'] ?? 'Desconocido';
            $this->equipos[$id] = $from;
            echo "🖥️ Equipo registrado: $id\n";
            break;

case 'UpdateStatus':
    $id = $data['id'] ?? 'Desconocido';
    $estado = $data['estado'] ?? 'Desconocido';
    $hora = $data['timestamp'] ?? date('Y-m-d H:i:s');

    $this->estados[$id] = [
        'estado' => $estado,
        'hora' => $hora
    ];

    echo "📡 Estado actualizado ($id): $estado @ $hora\n";

    $this->broadcastMonitor();
    break;



        case 'mensaje':
            $texto = $data['mensaje'] ?? '';
            $destino = $data['destino'] ?? 'todos';
            $this->enviarComando('mensaje', $texto, $destino);
            break;

        case 'comando':
            $this->enviarComando($data['comando'], '', $data['destino'] ?? 'todos');
            break;

        default:
            echo "📩 Mensaje desconocido: " . json_encode($data) . "\n";
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

        // === Enviar comandos ===
private function enviarComando($comando, $texto = '', $destino = 'todos') {
    $payload = json_encode([
        "tipo" => "comando",
        "comando" => $comando,
        "texto" => $texto,
        "destino" => $destino
    ]);

    if ($destino === 'todos') {
        foreach ($this->equipos as $cli) $cli->send($payload);
        echo "🌍 Comando global: $comando\n";
    } elseif (isset($this->equipos[$destino])) {
        $this->equipos[$destino]->send($payload);
        echo "🎯 Comando '$comando' enviado a $destino\n";
    } else {
        echo "⚠️ Equipo no conectado: $destino\n";
    }
}

    // === Funciones internas ===
    private function enviarEstado($conn) {
        $conn->send(json_encode([
            "tipo" => "estado",
            "sesiones" => $this->getSesiones(),
            "stats" => $this->getStats()
        ]));
    }

    private function enviarEstadoATodos() {
        $data = [
            "tipo" => "estado",
            "sesiones" => $this->getSesiones(),
            "stats" => $this->getStats()
        ];
        foreach ($this->clients as $client) {
            $client->send(json_encode($data));
        }
    }

    public function enviarMensaje($texto, $destino = 'todos') {
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

    public function ejecutarAccionRemota($data) {
        $accion = $data['accion'] ?? '';
        $id = $data['id'] ?? null;

        if (!$accion || !$id) return;

        $sql = "";
        switch ($accion) {
            case 'renovar':
                $sql = "UPDATE sesiones SET id_estado_fk = 1 WHERE id = $id";
                break;
            case 'suspender':
                $sql = "UPDATE sesiones SET id_estado_fk = 2 WHERE id = $id";
                break;
            case 'bloquear':
                $sql = "UPDATE sesiones SET id_estado_fk = 3 WHERE id = $id";
                break;
            case 'finalizar':
                $sql = "UPDATE sesiones SET id_estado_fk = 4 WHERE id = $id";
                break;
        }

        if ($sql) {
            $this->conn->query($sql);
            echo "⚙️ Acción remota ejecutada: $accion sobre sesión #$id\n";
            $this->enviarEstadoATodos();
        }
    }

    private function getSesiones() {
        $sql = "SELECT s.id, s.username, s.fecha_inicio, s.fecha_final_programada, e.nombre_estado
                FROM sesiones s
                LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
                ORDER BY s.id DESC";
        $result = $this->conn->query($sql);
        $sesiones = [];
        while ($row = $result->fetch_assoc()) $sesiones[] = $row;
        return $sesiones;
    }

    private function getStats() {
        $stats = ["Abierto" => 0, "Suspendido" => 0, "Bloqueado" => 0, "Finalizado" => 0];
        $sql = "SELECT e.nombre_estado, COUNT(*) AS total FROM sesiones s
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

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new DashboardServer($conn)
        )
    ),
    8080
);
$server->run();
