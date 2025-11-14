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
                    $from->puntoServicio = [
                        'id' => $data['id_p_servicio'] ?? null,
                        'nombre' => $data['nombre_p_servicio'] ?? 'Desconocido'
                    ];
                    $this->dashboards[$from->resourceId] = $from;
                    $this->enviarEstado($from);
                    $from->send(json_encode([
                        'tipo' => 'confirmacion_registro',
                        'origen' => 'server',
                        'usuario' => 'dashboard',
                        'nombre_eq' => $from->nombre_equipo,
                        'punto_servicio' => $from->puntoServicio,
                        'equipos' => array_keys($this->equipos)
                    ]));
                    echo "💻 Dashboard registrado: {$from->nombre_equipo}\n";
                }
                if ($data['origen'] == 'equipo') {
                    $from->tipoCliente = 'equipo';
                    $from->idCliente = $data['nombre_equipo'] ?? 'Desconocido';
                    $from->id_p_servicio = $data['id_p_servicio'] ?? null;
                    $from->puntoServicio = [
                        'id' => $data['id_p_servicio'] ?? null,
                        'nombre' => $data['nombre_p_servicio'] ?? null
                    ];
                    $this->equipos[$from->idCliente] = $from;
                    echo "🖥️ Equipo registrado: {$from->idCliente}\n";
                    $from->send(json_encode([
                        'tipo' => 'confirmacion_registro',
                        'origen' => 'server',
                        'usuario' => 'equipo',
                        'equipos' => array_keys($this->equipos)
                    ]));
                }
                break;
            case 'actualizar':
                if ($data['origen'] == 'dashboard') {
                    $from->tipoCliente = 'dashboard';
                    $this->dashboards[] = $from;
                    echo "📊 Dashboard conectado: ID:({$from->resourceId})\n";
                    $this->enviarEstado($from);
                }
                break;
            case 'comando':
                $accion = $data['accion'] ?? 'undefined';
                $nombre_pc = $data['nombre_eq'] ?? $data['destino'] ?? null;
                $destino = $data['destino'] ?? null;
                $manejo = null;
                $texto = $data['mensaje'] ?? null;
                $destino = $data['destino'] ?? null;
                $id_p_servicio = $data['id_p_servicio'] ?? null;

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

                if (!$nombre_pc) {
                    echo "⚠️ No se especificó ID de destino.\n";
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
                                'texto'   => $texto,
                                'origen'  => 'server',
                                'timestamp' => date('Y-m-d H:i:s')
                            ]));
                        }
                    }
                    break;
                }
                echo " Enviando comando '{$accion}' a equipo '{$nombre_pc}'...Manejo '{$manejo}'... Destino '{$destino}'\n";
                $found = false;

                foreach ($this->equipos as $equip) {
                    if (isset($equip->idCliente) && $equip->idCliente === $nombre_pc) {
                        $found = true;
                        $payload = [
                            'tipo' => 'control_server',
                            "manejo" => $manejo,
                            'accion' => $accion,
                            'id_p_servicio' => $id_p_servicio,
                            'texto'     => $texto,
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
            case 'confirmacion':
                if ($data['origen'] == 'equipo') {
                    $nombre_eq   = $data['nombre_eq'] ?? 'Desconocido';
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
                                'tipo'      => 'confirmacion',
                                'nombre_eq' => $nombre_eq,
                                'accion'    => $accion,
                                'resultado' => $data['resultado'] ?? 'pendiente',
                                'origen'    => 'server' // indicamos que lo reenvía el server
                            ]));
                        }
                    }
                    if ($resultado == 'ejecutado' && $accion != 'mensaje') {
                        // ======================================================
                        // 🧠 Llamada directa a la API (comando_api)
                        // ======================================================
                        $apiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php";
                        $payload = [
                            'tipo'        => 'comando_api',
                            'accion'      => $accion,
                            'username'    => $usuario,
                            'mac_eq' => $mac_eq,
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
                        $mensaje = $decoded['mensaje'] ?? 'Sin mensaje';

                        echo "📡 API respondió: [{$estado}] {$mensaje}\n";
                        $this->log("📡 API → {$estado} → {$mensaje}");

                        // ======================================================
                        // 📘 Registro según tipo de acción y resultado
                        // ======================================================
                        switch ($accion) {
                            case 'finalizar':
                                if (str_contains($estado, 'FINALIZADO')) {
                                    $this->log("✅ Check-in completado para {$nombre_eq} (acción: finalizar)");
                                } else {
                                    $this->log("⚠️ Fallo al finalizar sesión en {$nombre_eq}: {$mensaje}");
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
                                if (str_contains($estado, 'RENOVADO')) {
                                    $this->log("🔁 Sesión renovada correctamente para {$nombre_eq}");
                                } else {
                                    $this->log("⚠️ Fallo al renovar sesión en {$nombre_eq}: {$mensaje}");
                                }
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

            // ========================================
            // 😴 HIBERNACIÓN - MONITOREO DE INACTIVIDAD
            // ========================================
            case 'hibernado':
                $accion = $data['accion'] ?? null;
                $nombre_equipo = $data['nombre_eq'] ?? null;
                $timestamp = $data['timestamp_hibernacion'] ?? date('Y-m-d H:i:s');

                if (!$nombre_equipo) {
                    echo "⚠️ Comando hibernación sin nombre_equipo\n";
                    break;
                }


                // Buscar sesión activa del equipo usando la tabla equipos y id_estado_fk
                try {
                    $sqlSes = "SELECT s.id AS id_sesion, eq.id_p_servicio_fk AS id_p_servicio
                                   FROM sesiones s
                                   LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
                                   WHERE eq.nombre_pc = ? AND s.id_estado_fk IN (2,3,4)
                                   LIMIT 1";
                    $stmt = $this->conn->prepare($sqlSes);
                    $stmt->bind_param('s', $nombre_equipo);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $sesion = $res->fetch_assoc();

                    if (!$sesion) {
                        echo "⚠️ No se encontró sesión activa para {$nombre_equipo}\n";
                        break;
                    }

                    $id_sesion = (int)$sesion['id_sesion'];
                    $id_p_servicio = $sesion['id_p_servicio'];

                    // ========================================
                    // VALIDAR QUE ESTADO 5 (HIBERNANDO) EXISTE EN BD
                    // ========================================
                    $chkEstado = $this->conn->query("SELECT id_estado FROM estados WHERE id_estado = 5 LIMIT 1");
                    if ($chkEstado && $chkEstado->num_rows === 0) {
                        // Estado no existe, intentar insertarlo (safe: INSERT IGNORE)
                        $this->conn->query("INSERT IGNORE INTO estados (id_estado, nombre_estado, descripcion, color) VALUES (5, 'Hibernando', 'Sesión en hibernación por inactividad', '#ffbb33')");
                        $this->log("⚠️ Estado 'Hibernando' (id=5) fue creado automáticamente en tabla estados");
                    }

                    // ========================================
                    // 1️⃣ Si acción es 'hibernar': actualizar estado a Hibernando (id_estado_fk = 5)
                    // ========================================
                    if ($accion === 'hibernar') {
                        $update = $this->conn->prepare("UPDATE sesiones SET id_estado_fk = ? WHERE id = ?");
                        $hibernarId = 5; // según constantes en status.php
                        $update->bind_param('ii', $hibernarId, $id_sesion);
                        $update->execute();

                        $this->log("😴 HIBERNACIÓN INICIADA: Sesión {$id_sesion} de {$nombre_equipo}");

                        // Notificar a todos los dashboards del cambio de estado
                        $this->notificarDashboards([
                            'tipo' => 'cambio_estado',
                            'id_sesion' => $id_sesion,
                            'estado_nuevo' => 'Hibernando',
                            'nombre_equipo' => $nombre_equipo,
                            'id_p_servicio' => $id_p_servicio,
                            'timestamp' => $timestamp
                        ]);
                        $this->enviarEstadoATodos();
                    }
                    // ========================================
                    // 2️⃣ Si acción es 'finalizar_por_hibernacion': cerrar sesión (id_estado_fk = 1)
                    // ========================================
                    elseif ($accion === 'finalizar_por_hibernacion') {
                        $finalizarId = 1; // Finalizado
                        $update = $this->conn->prepare("UPDATE sesiones SET id_estado_fk = ?, fecha_final_real = ? WHERE id = ?");
                        $update->bind_param('isi', $finalizarId, $timestamp, $id_sesion);
                        $update->execute();

                        $this->log("⛔ SESIÓN FINALIZADA POR HIBERNACIÓN: {$id_sesion} de {$nombre_equipo}");

                        // Llamar a la API para cerrar la sesión en FOLIO
                        $apiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php";
                        $payload = [
                            'tipo' => 'comando_api',
                            'accion' => 'finalizar',
                            'id_sesion' => $id_sesion,
                            'razon' => 'inactividad_prolongada',
                            'origen' => 'hibernation_monitor'
                        ];

                        $ch = curl_init($apiUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode($payload),
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                            CURLOPT_TIMEOUT => 10
                        ]);

                        $apiResponse = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($httpCode === 200) {
                            $this->log("✅ API procesó finalización por hibernación: {$id_sesion}");
                        } else {
                            $this->log("⚠️ API respondió con código {$httpCode} al finalizar {$id_sesion}");
                        }

                        // Notificar a todos los dashboards del cambio de estado
                        $this->notificarDashboards([
                            'tipo' => 'cambio_estado',
                            'id_sesion' => $id_sesion,
                            'estado_nuevo' => 'Finalizado',
                            'nombre_equipo' => $nombre_equipo,
                            'id_p_servicio' => $id_p_servicio,
                            'timestamp' => $timestamp,
                            'razon' => 'hibernacion'
                        ]);
                        $this->enviarEstadoATodos();
                    }
                } catch (Exception $e) {
                    echo "❌ Error procesando hibernación: " . $e->getMessage() . "\n";
                    $this->log("❌ ERROR en hibernation: " . $e->getMessage());
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
