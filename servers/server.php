<?php
// server/server.php
require_once __DIR__ . '/../prueba_equipos/db.php'; // ajustar si tu db.php está en otro lugar
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;

class DashboardServer implements MessageComponentInterface {
    protected $clients;
    protected $conn;
    protected $equipos;

    public function __construct($conn) {
        $this->clients = new \SplObjectStorage;
        $this->equipos = [];
        $this->conn = $conn;
        $this->log("🚀 Servidor WebSocket Unisimón (PHP) inicializado ws://localhost:8081");
    }
   // === CONEXIÓN ABIERTA ===
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $this->log("🟢 Cliente conectado: ({$conn->resourceId})");
        // enviar estado inicial
        $this->enviarEstado($conn);
    }

    // === MENSAJE ENTRANTE ===
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = @json_decode($msg, true);
        if (!$data) {
            $this->log("Mensaje no JSON: $msg");
            return;
        }

        // registro de equipo por si clientes (equipos) se identifiquen
        if (!empty($data['accion']) && $data['accion'] === 'Register' && !empty($data['id'])) {
            $idEquipo = (string)$data['id'];
            $this->equipos[$idEquipo] = $from;
            $from->send(json_encode(["tipo"=>"info","mensaje"=>"Registrado","id"=>$idEquipo]));
            $this->log("🔗 Equipo registrado: $idEquipo");
            return;
        }

        // Acciones que modifican la BD (ahora manejadas por el servidor WS)
        if (!empty($data['accion']) && isset($data['id'])) {
            $accion = $data['accion'];
            $id = (int)$data['id'];
            if (in_array($accion, ['renovar','finalizar','bloquear'])) {
                $this->aplicarAccion($accion, $id, $from);
                return;
            }
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

    private function aplicarAccion($accion, $id, $from) {
        switch ($accion) {
            case "renovar":
                $sql = "UPDATE sesiones SET id_estado_fk = 2, fecha_final_programada = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE id = $id";
                $mensaje = "Sesión renovada";
                break;
            case "finalizar":
                $sql = "UPDATE sesiones SET id_estado_fk = 1, fecha_final_real = NOW() WHERE id = $id";
                $mensaje = "Sesión finalizada";
                break;
            case "bloquear":
                $sql = "UPDATE sesiones SET id_estado_fk = 4 WHERE id = $id";
                $mensaje = "Sesión bloqueada";
                break;
            default:
                $from->send(json_encode(["tipo"=>"error","mensaje"=>"Acción desconocida"]));
                return;
        }

        if ($this->conn->query($sql)) {
            $from->send(json_encode(["tipo"=>"info","mensaje"=>$mensaje]));
            $this->log("✅ Acción aplicada ($accion) sobre id=$id");
            // reenviar estado actualizado a todos
            $this->enviarEstadoATodos();
        } else {
            $err = $this->conn->error;
            $from->send(json_encode(["tipo"=>"error","mensaje"=>"Error SQL: $err"]));
            $this->log("❌ Error SQL al aplicar $accion sobre id=$id : $err");
        }
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
    // === ESTADO A TODAS LAS SESIONES ===
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
    // === LOG BONITO EN CONSOLA ===
    private function log($msg) {
        echo "[" . date("Y-m-d H:i:s") . "] $msg\n";
    }

        // Especificamos un método para manejar la terminación y eliminar el archivo PID cuando el proceso se cierre
    public function __destruct() {
        // Eliminar el archivo PID cuando el servidor se cierra
        $pidFile = __DIR__ . '/server.pid';
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    }
}


$server = IoServer::factory(
    new HttpServer(new WsServer(new DashboardServer($conn))),
    8081
);
$server->run();