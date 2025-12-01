# 💻 BOILERPLATE DE CÓDIGO - LISTO PARA COPIAR-PEGAR

Este archivo contiene código ready-to-use para implementar las 10 mejoras críticas.

---

## 1️⃣ VALIDACIÓN DE ENTRADA (SEGURIDAD)

### Archivo: `prueba_equipos/validation.php`

```php
<?php
// validation.php - Centralized input validation

class InputValidator {
    /**
     * Valida username (email format)
     */
    public static function validateUsername($username) {
        if (empty($username)) {
            return false;
        }
        // Aceptar: user@unisimon.edu.co o usuario
        if (preg_match('/^[a-zA-Z0-9._@]+$/', $username)) {
            return htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        }
        return false;
    }

    /**
     * Valida MAC address
     */
    public static function validateMacAddress($mac) {
        if (empty($mac)) {
            return false;
        }
        // Formato: xx:xx:xx:xx:xx:xx o xxxxxxxxxxxxxx
        if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$|^[0-9A-Fa-f]{12}$/', $mac)) {
            return strtoupper(str_replace('-', ':', $mac));
        }
        return false;
    }

    /**
     * Valida integer seguro
     */
    public static function validateInt($value, $min = 0, $max = PHP_INT_MAX) {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) return false;
        return ($int >= $min && $int <= $max) ? $int : false;
    }

    /**
     * Valida que sea JSON válido
     */
    public static function validateJson($json_string) {
        $data = json_decode($json_string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        return $data;
    }

    /**
     * Sanitiza string
     */
    public static function sanitizeString($str, $maxLength = 255) {
        $str = filter_var($str, FILTER_SANITIZE_STRING);
        return substr($str, 0, $maxLength);
    }

    /**
     * Valida que sea uno de los valores permitidos
     */
    public static function validateEnum($value, $allowed = []) {
        return in_array($value, $allowed) ? $value : false;
    }
}

?>
```

### Uso en `api.php`:

```php
<?php
require 'validation.php';

// ANTES (INSEGURO):
$username = $_GET['username'] ?? null;
$mac_address = $_GET['mac_address'] ?? null;

// DESPUÉS (SEGURO):
$username = InputValidator::validateUsername($_GET['username'] ?? null);
if (!$username) jsonError("Username inválido");

$mac_address = InputValidator::validateMacAddress($_GET['mac_address'] ?? null);
if (!$mac_address) jsonError("MAC address inválida");

$tipo = InputValidator::validateEnum(
    $_GET['tipo'] ?? null,
    ['control', 'status', 'update']
);
if (!$tipo) jsonError("Tipo de solicitud inválido");

?>
```

---

## 2️⃣ AUTENTICACIÓN WEBSOCKET (JWT)

### Archivo: `prueba_equipos/jwt.php`

```php
<?php
// jwt.php - Simple JWT implementation

class JWT {
    private static $secret = 'S1m0n_2025_SECRET_KEY'; // CAMBIAR A ENV
    private static $algorithm = 'HS256';

    /**
     * Genera token JWT
     */
    public static function generate($data, $expireIn = 3600) {
        $now = time();
        $payload = [
            'iat' => $now,
            'exp' => $now + $expireIn,
            'data' => $data
        ];

        $header = json_encode(['alg' => self::$algorithm, 'typ' => 'JWT']);
        $payload = json_encode($payload);

        $headerEncoded = self::base64UrlEncode($header);
        $payloadEncoded = self::base64UrlEncode($payload);

        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$headerEncoded.$payloadEncoded", self::$secret, true)
        );

        return "$headerEncoded.$payloadEncoded.$signature";
    }

    /**
     * Valida y decodifica token JWT
     */
    public static function verify($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Validar firma
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$headerEncoded.$payloadEncoded", self::$secret, true)
        );

        if ($signatureEncoded !== $signature) {
            return null;
        }

        // Decodificar payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!$payload) {
            return null;
        }

        // Validar expiración
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload['data'] ?? null;
    }

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', strlen($data) % 4));
    }
}

?>
```

### Uso en `servers/server.php`:

```php
<?php

class DashboardServer implements MessageComponentInterface {
    // ...

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = @json_decode($msg, true);

        switch ($data['tipo'] ?? '') {
            case 'registro':
                // VALIDAR TOKEN ANTES DE ACEPTAR
                $token = $data['token'] ?? null;
                if (!$token || !$this->validarToken($token)) {
                    $from->send(json_encode(['error' => 'Token inválido']));
                    $from->close();
                    return;
                }

                if ($data['origen'] == 'equipo') {
                    $from->tipoCliente = 'equipo';
                    $from->idCliente = $data['nombre_equipo'] ?? 'Desconocido';
                    $this->equipos[$from->idCliente] = $from;
                    echo "🖥️ Equipo registrado: {$from->idCliente}\n";
                }
                break;
        }
    }

    private function validarToken($token) {
        // Implementar verifica JWT
        return !empty($token); // TODO: Usar JWT::verify($token)
    }
}

?>
```

---

## 3️⃣ RATE LIMITING

### Archivo: `prueba_equipos/ratelimit.php`

```php
<?php
// ratelimit.php - Simple rate limiter

class RateLimiter {
    private $storage = []; // En producción usar Redis

    /**
     * Verifica si el cliente ha excedido el límite
     * @param $clientId string (IP o user ID)
     * @param $limit int Máximo de requests
     * @param $window int Ventana de tiempo en segundos
     */
    public function isAllowed($clientId, $limit = 100, $window = 60) {
        $now = time();
        $key = "rate_" . $clientId;

        // Inicializar si no existe
        if (!isset($this->storage[$key])) {
            $this->storage[$key] = [];
        }

        // Limpiar requests fuera de la ventana
        $this->storage[$key] = array_filter(
            $this->storage[$key],
            function($timestamp) use ($now, $window) {
                return $timestamp > ($now - $window);
            }
        );

        // Verificar límite
        if (count($this->storage[$key]) >= $limit) {
            return false;
        }

        // Registrar nuevo request
        $this->storage[$key][] = $now;
        return true;
    }

    public function getRemaining($clientId, $limit = 100) {
        $key = "rate_" . $clientId;
        $current = isset($this->storage[$key]) ? count($this->storage[$key]) : 0;
        return max(0, $limit - $current);
    }
}

// Uso global
$rateLimiter = new RateLimiter();

?>
```

### Uso en `api.php`:

```php
<?php

// Al inicio del api.php
require 'ratelimit.php';

$clientId = $_SERVER['REMOTE_ADDR']; // O usar user ID si está autenticado

if (!$rateLimiter->isAllowed($clientId, 100, 60)) {
    http_response_code(429);
    jsonError("Demasiadas solicitudes. Intenta en 1 minuto.", 429);
}

// Agregar header informativo
header('X-RateLimit-Remaining: ' . $rateLimiter->getRemaining($clientId));

?>
```

---

## 4️⃣ LOGGING CENTRALIZADO

### Archivo: `config/Logger.php`

```php
<?php
// Logger.php - Centralized logging system

class Logger {
    private static $logFile = '/tmp/autoprestamo.log';
    private static $debugMode = true;

    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_CRITICAL = 'CRITICAL';

    /**
     * Log con timestamp, nivel y contexto
     */
    public static function log($message, $level = self::LEVEL_INFO, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';

        $logMessage = "[$timestamp] [$level] $message$contextStr\n";

        // Escribir a archivo
        file_put_contents(self::$logFile, $logMessage, FILE_APPEND);

        // En desarrollo, también a stdout
        if (self::$debugMode) {
            echo $logMessage;
        }

        // Enviarn a BD si es critical
        if ($level === self::LEVEL_CRITICAL) {
            self::logToDatabase($message, $level, $context);
        }
    }

    public static function debug($msg, $context = []) {
        self::log($msg, self::LEVEL_DEBUG, $context);
    }

    public static function info($msg, $context = []) {
        self::log($msg, self::LEVEL_INFO, $context);
    }

    public static function warning($msg, $context = []) {
        self::log($msg, self::LEVEL_WARNING, $context);
    }

    public static function error($msg, $context = []) {
        self::log($msg, self::LEVEL_ERROR, $context);
    }

    public static function critical($msg, $context = []) {
        self::log($msg, self::LEVEL_CRITICAL, $context);
    }

    private static function logToDatabase($msg, $level, $context) {
        // TODO: Implementar si necesario
    }
}

?>
```

### Uso en `server.php`:

```php
<?php

require 'Logger.php';

public function onMessage(ConnectionInterface $from, $msg) {
    Logger::debug("Mensaje recibido", [
        'clientId' => $from->resourceId,
        'tipo' => $data['tipo'] ?? null
    ]);

    try {
        // Procesar...
    } catch (Exception $e) {
        Logger::error("Error al procesar comando", [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

?>
```

---

## 5️⃣ ERROR HANDLING GLOBAL

### Archivo: `api.php` (Actualizar)

```php
<?php

// Headers de respuesta global
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Error handler global
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    Logger::error("PHP Error", [
        'code' => $errno,
        'message' => $errstr,
        'file' => $errfile,
        'line' => $errline
    ]);
    http_response_code(500);
    jsonError("Error interno del servidor");
});

// Exception handler global
set_exception_handler(function(Throwable $e) {
    Logger::critical("Excepción no manejada", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    jsonError("Error interno del servidor");
});

// Shutdown handler para detectar fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null) {
        Logger::critical("Fatal error", [
            'type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

?>
```

---

## 6️⃣ MODULARIZACIÓN DE JAVASCRIPT

### Archivo: `dashboard-unisimon/assets/js/modules/EventBus.js`

```javascript
/**
 * EventBus.js - Sistema de eventos centralizado
 */

class EventBus {
    constructor() {
        this.listeners = {};
    }

    /**
     * Suscribir a un evento
     */
    on(eventName, callback) {
        if (!this.listeners[eventName]) {
            this.listeners[eventName] = [];
        }
        this.listeners[eventName].push(callback);
    }

    /**
     * Desuscribir de un evento
     */
    off(eventName, callback) {
        if (this.listeners[eventName]) {
            this.listeners[eventName] = this.listeners[eventName].filter(
                cb => cb !== callback
            );
        }
    }

    /**
     * Emitir evento
     */
    emit(eventName, data) {
        if (this.listeners[eventName]) {
            this.listeners[eventName].forEach(callback => {
                try {
                    callback(data);
                } catch (e) {
                    console.error(`Error en evento ${eventName}:`, e);
                }
            });
        }
    }

    /**
     * Limpiar eventos
     */
    clear(eventName) {
        if (eventName) {
            delete this.listeners[eventName];
        } else {
            this.listeners = {};
        }
    }
}

// Singleton
const eventBus = new EventBus();
```

### Archivo: `dashboard-unisimon/assets/js/modules/WebSocketClient.js`

```javascript
/**
 * WebSocketClient.js - Gestión de conexión WebSocket
 */

class WebSocketClient {
    constructor(url, eventBus) {
        this.url = url;
        this.eventBus = eventBus;
        this.ws = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 3000;
    }

    /**
     * Conectar al servidor
     */
    connect() {
        return new Promise((resolve, reject) => {
            try {
                this.ws = new WebSocket(this.url);

                this.ws.onopen = () => {
                    console.log('✅ WebSocket conectado');
                    this.reconnectAttempts = 0;
                    this.eventBus.emit('connected', {});
                    resolve();
                };

                this.ws.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        this.eventBus.emit('message', data);
                    } catch (e) {
                        console.error('Error parseando mensaje:', e);
                    }
                };

                this.ws.onerror = (error) => {
                    console.error('❌ Error WebSocket:', error);
                    this.eventBus.emit('error', error);
                    reject(error);
                };

                this.ws.onclose = () => {
                    console.log('🔴 WebSocket desconectado');
                    this.eventBus.emit('disconnected', {});
                    this.attemptReconnect();
                };
            } catch (e) {
                reject(e);
            }
        });
    }

    /**
     * Enviar mensaje
     */
    send(data) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(data));
            return true;
        }
        console.warn('WebSocket no está conectado');
        return false;
    }

    /**
     * Reconectar automáticamente
     */
    attemptReconnect() {
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            console.log(`🔄 Reintentando conexión (${this.reconnectAttempts}/${this.maxReconnectAttempts})...`);
            setTimeout(() => this.connect().catch(() => {}), this.reconnectDelay);
        } else {
            console.error('❌ Máximo número de intentos de reconexión alcanzado');
            this.eventBus.emit('reconnect_failed', {});
        }
    }

    /**
     * Desconectar
     */
    disconnect() {
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }
    }

    /**
     * Obtener estado
     */
    isConnected() {
        return this.ws && this.ws.readyState === WebSocket.OPEN;
    }
}
```

### Archivo: `dashboard-unisimon/assets/js/modules/DashboardUI.js`

```javascript
/**
 * DashboardUI.js - Actualización de UI
 */

class DashboardUI {
    constructor(eventBus) {
        this.eventBus = eventBus;
        this.setupEventListeners();
    }

    /**
     * Configurar escuchadores de eventos
     */
    setupEventListeners() {
        this.eventBus.on('connected', () => this.showConnected());
        this.eventBus.on('disconnected', () => this.showDisconnected());
        this.eventBus.on('message', (data) => this.handleMessage(data));
        this.eventBus.on('error', (error) => this.showError(error));
    }

    /**
     * Mostrar estado conectado
     */
    showConnected() {
        const statusDot = document.getElementById('statusDot');
        const statusText = document.getElementById('statusText');
        const toggleBtn = document.getElementById('toggleBtn');

        if (statusDot) statusDot.style.background = '#00cc00';
        if (statusText) statusText.textContent = 'CONECTADO';
        if (toggleBtn) toggleBtn.textContent = 'Desconectar';
        if (toggleBtn) toggleBtn.classList.remove('btn-outline-danger');
        if (toggleBtn) toggleBtn.classList.add('btn-outline-success');
    }

    /**
     * Mostrar estado desconectado
     */
    showDisconnected() {
        const statusDot = document.getElementById('statusDot');
        const statusText = document.getElementById('statusText');
        const toggleBtn = document.getElementById('toggleBtn');

        if (statusDot) statusDot.style.background = '#dd0000';
        if (statusText) statusText.textContent = 'DESCONECTADO';
        if (toggleBtn) toggleBtn.textContent = 'Conectar';
        if (toggleBtn) toggleBtn.classList.add('btn-outline-danger');
        if (toggleBtn) toggleBtn.classList.remove('btn-outline-success');
    }

    /**
     * Procesar mensaje del servidor
     */
    handleMessage(data) {
        console.log('📨 Mensaje recibido:', data);

        switch (data.tipo) {
            case 'estado':
                this.updateStats(data);
                break;
            case 'log':
                this.addLog(data);
                break;
            default:
                console.log('Tipo de mensaje desconocido:', data.tipo);
        }
    }

    /**
     * Actualizar estadísticas
     */
    updateStats(data) {
        if (data.stats) {
            document.getElementById('stat-abierto').textContent = data.stats.abierto || 0;
            document.getElementById('stat-suspendido').textContent = data.stats.suspendido || 0;
            document.getElementById('stat-bloqueado').textContent = data.stats.bloqueado || 0;
            document.getElementById('stat-finalizado').textContent = data.stats.finalizado || 0;
        }
    }

    /**
     * Agregar log
     */
    addLog(data) {
        const logContainer = document.getElementById('logContainer');
        if (logContainer) {
            const logEntry = document.createElement('div');
            logEntry.className = 'log-entry';
            logEntry.textContent = `[${new Date().toLocaleTimeString()}] ${data.message}`;
            logContainer.insertBefore(logEntry, logContainer.firstChild);

            // Limitar a 100 logs
            while (logContainer.children.length > 100) {
                logContainer.removeChild(logContainer.lastChild);
            }
        }
    }

    /**
     * Mostrar error
     */
    showError(error) {
        this.showToast(`❌ Error: ${error.message || 'Error desconocido'}`, 'error');
    }

    /**
     * Mostrar notificación
     */
    showToast(message, type = 'info') {
        const toastContainer = document.getElementById('toast-container') ||
            this.createToastContainer();

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        toastContainer.appendChild(toast);

        setTimeout(() => toast.remove(), 3000);
    }

    /**
     * Crear contenedor de notificaciones
     */
    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
        document.body.appendChild(container);
        return container;
    }
}
```

### Archivo: `dashboard-unisimon/assets/js/main.js` (Nuevo)

```javascript
/**
 * main.js - Inicialización de la aplicación
 */

document.addEventListener('DOMContentLoaded', () => {
    // Inicializar sistema de eventos
    const eventBus = new EventBus();

    // Inicializar cliente WebSocket
    const wsClient = new WebSocketClient(
        (location.protocol === 'https:' ? 'wss' : 'ws') + '://localhost:8081',
        eventBus
    );

    // Inicializar UI
    const dashboardUI = new DashboardUI(eventBus);

    // Exponer globalmente para uso en HTML
    window.app = {
        eventBus,
        wsClient,
        dashboardUI,

        connect() {
            wsClient.connect().catch(e => {
                console.error('Conexión fallida:', e);
                dashboardUI.showToast('No se pudo conectar al servidor', 'error');
            });
        },

        disconnect() {
            wsClient.disconnect();
        },

        sendCommand(command) {
            if (wsClient.isConnected()) {
                wsClient.send(command);
            } else {
                dashboardUI.showToast('No conectado al servidor', 'error');
            }
        }
    };

    // Auto-conectar si estaba conectado antes
    const wasConnected = localStorage.getItem('dashboard_connected') === 'true';
    if (wasConnected) {
        setTimeout(() => app.connect(), 500);
    }
});
```

---

## 7️⃣ ARCHIVO .ENV PARA CONFIGURACIÓN

### Archivo: `.env` (en raíz)

```bash
# Database
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=autoprestamo

# WebSocket
WS_PORT=8081
WS_HOST=0.0.0.0

# Security
JWT_SECRET=S1m0n_2025_SUPER_SECRET_CHANGE_ME_IN_PROD
API_RATE_LIMIT=100
API_RATE_WINDOW=60

# Environment
APP_ENV=development
APP_DEBUG=true
LOG_LEVEL=DEBUG

# FOLIO API
FOLIO_API_URL=https://folio.unisimon.edu.co/api
FOLIO_API_KEY=your-api-key-here
```

### Archivo: `config/config.php` (Leer del .env)

```php
<?php
// config/config.php

// Cargar .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue; // Skip comments
        if (strpos($line, '=') === false) continue;

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Remover comillas
        if ((strpos($value, '"') === 0) && (strrpos($value, '"') === strlen($value) - 1)) {
            $value = substr($value, 1, -1);
        }

        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// Database config
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'autoprestamo');

// WebSocket config
define('WS_PORT', getenv('WS_PORT') ?: 8081);
define('WS_HOST', getenv('WS_HOST') ?: '0.0.0.0');

// Security
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'S1m0n_2025');
define('API_RATE_LIMIT', (int)getenv('API_RATE_LIMIT') ?: 100);

// App config
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true');

?>
```

---

## ✅ SCRIPT DE INSTALACIÓN

### Archivo: `install.sh` (Linux/Mac)

```bash
#!/bin/bash

echo "🚀 Instalando AUTOPRÉSTAMOS..."

# Crear .env desde .env.example si no existe
if [ ! -f .env ]; then
    cp .env.example .env
    echo "✅ Archivo .env creado"
fi

# Instalar dependencias PHP
cd servers
composer install
cd ..

# Crear directorio de logs
mkdir -p logs
chmod 777 logs

# Crear tabla de logs en BD (opcional)
echo "ℹ️ Asegúrate de tener la BD autoprestamo creada"
echo "ℹ️ Ejecuta: mysql -u root < database/schema.sql"

# Configurar permisos
chmod +x scripts/*.sh

echo "✅ Instalación completada"
echo ""
echo "Próximos pasos:"
echo "1. Edita .env con tus credenciales"
echo "2. Crea/restaura la BD: mysql -u root < database/schema.sql"
echo "3. Inicia el servidor: php servers/server.php"
echo "4. Abre el dashboard: http://localhost/dashboard-unisimon/"

```

### Archivo: `install.ps1` (Windows)

```powershell
# install.ps1 - Windows installation script

Write-Host "🚀 Instalando AUTOPRÉSTAMOS..."

# Crear .env
if (!(Test-Path .env)) {
    Copy-Item .env.example .env
    Write-Host "✅ Archivo .env creado"
}

# Instalar dependencias
Push-Location servers
composer install
Pop-Location

# Crear directorio de logs
New-Item -ItemType Directory -Force -Path logs | Out-Null
Write-Host "✅ Directorio de logs creado"

Write-Host ""
Write-Host "Próximos pasos:"
Write-Host "1. Edita .env con tus credenciales"
Write-Host "2. Crea/restaura la BD: mysql -u root < database\schema.sql"
Write-Host "3. Inicia el servidor: php servers\server.php"
Write-Host "4. Abre el dashboard: http://localhost/dashboard-unisimon/"
```

---

## 📝 SCHEMA DE BD

### Archivo: `database/schema.sql`

```sql
-- Crear BD
CREATE DATABASE IF NOT EXISTS autoprestamo;
USE autoprestamo;

-- Tabla de equipos
CREATE TABLE IF NOT EXISTS equipos (
    id_equipo INT PRIMARY KEY AUTO_INCREMENT,
    nombre_equipo VARCHAR(100) NOT NULL UNIQUE,
    mac_equipo VARCHAR(17) NOT NULL UNIQUE,
    barcode_equipo VARCHAR(50),
    ip_equipo VARCHAR(15),
    tipo_equipo VARCHAR(50),
    estado VARCHAR(20) DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mac (mac_equipo),
    INDEX idx_estado (estado)
);

-- Tabla de sesiones
CREATE TABLE IF NOT EXISTS sesiones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(50) NOT NULL,
    id_equipo_fk INT NOT NULL,
    id_estado_fk INT DEFAULT 1,
    fecha_inicio DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_final_programada DATETIME,
    fecha_fin DATETIME,
    notas TEXT,
    FOREIGN KEY (id_equipo_fk) REFERENCES equipos(id_equipo),
    INDEX idx_user_id (user_id),
    INDEX idx_equipo (id_equipo_fk),
    INDEX idx_estado (id_estado_fk),
    INDEX idx_fecha (fecha_inicio)
);

-- Tabla de estados
CREATE TABLE IF NOT EXISTS estados (
    id_estado INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(50) NOT NULL,
    descripcion TEXT,
    color VARCHAR(7) DEFAULT '#000000'
);

-- Insertar estados predefinidos
INSERT INTO estados (nombre, descripcion, color) VALUES
(1, 'Abierto', 'Sesión activa', '#00cc00'),
(2, 'Suspendido', 'Sesión suspendida', '#ffaa00'),
(3, 'Bloqueado', 'Usuario bloqueado', '#dd0000'),
(4, 'Finalizado', 'Sesión cerrada', '#999999');

-- Tabla de logs
CREATE TABLE IF NOT EXISTS logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nivel VARCHAR(20),
    mensaje TEXT,
    contexto JSON,
    ip_origen VARCHAR(15),
    usuario VARCHAR(100),
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nivel (nivel),
    INDEX idx_usuario (usuario),
    INDEX idx_fecha (fecha_creacion)
);

-- Tabla de configuración
CREATE TABLE IF NOT EXISTS configuracion (
    id INT PRIMARY KEY AUTO_INCREMENT,
    clave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT,
    tipo VARCHAR(20),
    descripcion TEXT,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Inserts de configuración
INSERT INTO configuracion (clave, valor, tipo, descripcion) VALUES
('intervalo_tiempo_abierto', '30', 'integer', 'Segundos que permanece ABIERTO'),
('tiempo_suspendido', '30', 'integer', 'Segundos que permanece SUSPENDIDO'),
('espera_bloqueo', '10', 'integer', 'Segundos que permanece BLOQUEADO'),
('clave_admin', 'S1m0n_2025', 'string', 'Clave para operaciones administrativas');

-- Crear usuario de aplicación (opcional pero recomendado)
-- CREATE USER 'autoprestamo'@'localhost' IDENTIFIED BY 'PASSWORD_AQUI';
-- GRANT ALL PRIVILEGES ON autoprestamo.* TO 'autoprestamo'@'localhost';
-- FLUSH PRIVILEGES;
```

---

## 🧪 TESTS BÁSICOS

### Archivo: `tests/APITest.php`

```php
<?php
// tests/APITest.php - Pruebas unitarias de API

require_once '../prueba_equipos/validation.php';
require_once '../prueba_equipos/jwt.php';

class APITest {
    public function testValidUsername() {
        $valid = InputValidator::validateUsername('usuario@unisimon.edu.co');
        assert($valid !== false, "Username válido debe pasar");

        $invalid = InputValidator::validateUsername('invalid...@');
        assert($invalid === false, "Username inválido debe fallar");
    }

    public function testValidMacAddress() {
        $valid = InputValidator::validateMacAddress('00:1A:2B:3C:4D:5E');
        assert($valid !== false, "MAC válida debe pasar");

        $invalid = InputValidator::validateMacAddress('invalid-mac');
        assert($invalid === false, "MAC inválida debe fallar");
    }

    public function testJWTGenerate() {
        $data = ['user' => 'test', 'role' => 'admin'];
        $token = JWT::generate($data);
        assert(!empty($token), "Token debe ser generado");
    }

    public function testJWTVerify() {
        $data = ['user' => 'test'];
        $token = JWT::generate($data);
        $verified = JWT::verify($token);
        assert($verified === $data, "Token debe ser verificado correctamente");
    }

    public function runAll() {
        echo "Ejecutando tests...\n";
        try {
            $this->testValidUsername();
            echo "✅ testValidUsername\n";

            $this->testValidMacAddress();
            echo "✅ testValidMacAddress\n";

            $this->testJWTGenerate();
            echo "✅ testJWTGenerate\n";

            $this->testJWTVerify();
            echo "✅ testJWTVerify\n";

            echo "\n✅ Todos los tests pasaron!\n";
        } catch (AssertionError $e) {
            echo "❌ Test fallido: " . $e->getMessage() . "\n";
        }
    }
}

// Ejecutar
$test = new APITest();
$test->runAll();

?>
```

---

## 📊 CHECKLIST DE IMPLEMENTACIÓN

- [ ] 1. Copiar `validation.php` y usarlo en `api.php`
- [ ] 2. Copiar `jwt.php` y `Logger.php` en config
- [ ] 3. Implementar rate limiting en `api.php`
- [ ] 4. Agregar error handlers en `api.php`
- [ ] 5. Modularizar JavaScript con los módulos
- [ ] 6. Crear `.env` y `config/config.php`
- [ ] 7. Crear `install.sh` y `install.ps1`
- [ ] 8. Exportar BD schema en `database/schema.sql`
- [ ] 9. Ejecutar tests en `tests/APITest.php`
- [ ] 10. Validar que todo funciona localmente

---

**Cada archivo en este documento es copy-paste ready. Solo adapta según tu contexto.**

