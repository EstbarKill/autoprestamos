# 🔧 EJEMPLOS DE CÓDIGO - CORRECCIONES DE FLUJO

## 1️⃣ AGREGAR DESTINO EN TODOS LOS PAYLOADS

### ANTES ❌

```php
// server.php línea 1657-1672
$apiPayload = [
    'tipo'      => 'comando_api',
    'accion'    => $accionAPI,
    'username'  => $username,
    'mac_eq'    => $mac_eq,
    'nombre_equipo' => $nombre_eq,
    'origen'    => 'server'
];
```

### DESPUÉS ✅

```php
// server.php línea 1657-1672
$apiPayload = [
    'tipo'      => 'comando_api',
    'accion'    => $accionAPI,
    'username'  => $username,
    'mac_eq'    => $mac_eq,
    'nombre_equipo' => $nombre_eq,
    'origen'    => 'server',
    'destino'   => 'api',  // ✅ AGREGADO
    'timestamp' => date('Y-m-d H:i:s')  // ✅ AGREGADO
];
```

**Archivos a actualizar:**
- `server.php` línea 136
- `server.php` línea 174
- `server.php` línea 203
- `server.php` línea 269
- `server.php` línea 304
- `server.php` línea 1657-1672
- `server.php` línea 1700

---

## 2️⃣ ESTANDARIZAR TIMEOUTS

### ANTES ❌

```powershell
# win-server.ps1 línea 1107
$TimeoutSeconds = 15  # Shell espera 15s

# server.php línea 553
CURLOPT_TIMEOUT => 30  # Server espera 30s

# server.php línea 1700
CURLOPT_TIMEOUT => 10  # Server espera 10s (distinto)
```

### DESPUÉS ✅

```powershell
# win-server.ps1 línea 1107
$TimeoutSeconds = 30  # Shell espera 30s (más permisivo)

# win-server.ps1 línea 1239
# Implementar reintentos
$reintentos = 0
$maxReintentos = 2
while ($reintentos -lt $maxReintentos) {
    $response = Request-EstadoViaWS -TimeoutSeconds 30
    if ($null -ne $response -and $response.estado) {
        break
    }
    $reintentos++
    if ($reintentos -lt $maxReintentos) {
        Start-Sleep -Seconds 2
    }
}
```

```php
// server.php línea 553 (estandarizar a 15s)
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => json_encode($payload),
    CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT         => 15,  // ✅ ESTANDARIZADO
    CURLOPT_CONNECTTIMEOUT  => 5    // ✅ AGREGADO
]);

// server.php línea 1700 (igualar a 15s)
curl_setopt_array($ch, [
    CURLOPT_RETURNTIMEOUT => true,
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => json_encode($apiPayload),
    CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT         => 15  // ✅ ESTANDARIZADO
]);
```

---

## 3️⃣ IMPLEMENTAR CORRELACION_ID

### ANTES ❌

```php
// server.php
$payload = [
    'tipo'      => 'solicitar_estado',
    'username'  => 'usuario',
    'mac_address' => 'AA:BB:CC:DD:EE:FF'
    // Sin manera de rastrear el mensaje
];
```

### DESPUÉS ✅

```php
// server.php - Nuevo helper function
function generateCorrelationId() {
    return strtoupper(bin2hex(random_bytes(8)));
    // Ejemplo: "A1B2C3D4E5F6G7H8"
}

// En procesarSolicitudEstado()
$correlacionId = generateCorrelationId();

$payload = [
    'tipo'           => 'solicitar_estado',
    'correlacion_id' => $correlacionId,  // ✅ AGREGADO
    'username'       => 'usuario',
    'mac_address'    => 'AA:BB:CC:DD:EE:FF',
    'timestamp'      => date('c'),  // ISO 8601
    'origen'         => 'server',
    'destino'        => 'shell'
];

// Log con correlacion_id
$this->log("📤 [ID: $correlacionId] Enviando solicitud a API");

// En respuesta del Shell
$respuesta = [
    'tipo'           => 'respuesta_estado',
    'correlacion_id' => $correlacionId,  // ✅ MISMO ID
    'estado'         => 'Abierto',
    'timestamp'      => date('c')
];
```

```powershell
# win-server.ps1
function Request-EstadoViaWS {
    param([int]$TimeoutSeconds = 30)
    
    $correlacionId = [System.Guid]::NewGuid().ToString().Substring(0, 16)
    
    $payload = @{
        tipo = "solicitar_estado"
        correlacion_id = $correlacionId  # ✅ AGREGADO
        username = $Global:Config.Username
        mac_address = $Global:SharedState.MacAddress
        timestamp = (Get-Date -AsUTC -Format "o")
        origen = "shell"
        destino = "server"
    }
    
    # Guardar para validar respuesta después
    $Global:LastCorrelationId = $correlacionId
    
    # ... enviar y esperar respuesta ...
}
```

---

## 4️⃣ ELIMINAR VALIDACIÓN DUPLICADA DE CLAVE

### ANTES ❌

```php
// server.php línea 136-145
case 'renovar_clave':
    $claveAdmin = $data['clave_admin'] ?? null;
    
    if (!$claveAdmin) {
        $this->enviarAEquipo($nombreEquipo, [...]);
        return;
    }
    
    // ❌ VALIDA CLAVE EN SERVER
    if ($claveAdmin !== $this->claveCorrecta) {
        $this->enviarAEquipo($nombreEquipo, [
            'tipo' => 'error',
            'mensaje' => 'Clave incorrecta'  // ❌ Revelación de info
        ]);
        return;
    }
    
    // Luego llama a API...
    $resultadoAPI = $this->llamarAPI([...]);  // ❌ API valida AGAIN
    
// api.php línea 373
if ($claveAdmin !== $claveCorrecta) {  // ❌ DUPLICADO
    jsonOk(["estado" => "Error", "mensaje" => "Clave de administrador incorrecta"]);
    exit;
}
```

### DESPUÉS ✅

```php
// server.php línea 136-145
case 'renovar_clave':
    $claveAdmin = $data['clave_admin'] ?? null;
    
    if (!$claveAdmin) {
        $this->enviarAEquipo($nombreEquipo, [
            'tipo' => 'error',
            'mensaje' => 'Clave no proporcionada'
        ]);
        return;
    }
    
    // ✅ NO valida, deja que API lo haga
    // Llama directamente a API
    $resultadoAPI = $this->llamarAPI([
        'tipo' => 'comando_api',
        'accion' => 'validar_admin',
        'username' => $username,
        'mac_eq' => $macAddress,
        'clave_admin' => $claveAdmin,
        'origen' => 'server',
        'destino' => 'api',
        'timestamp' => date('c')
    ]);
    
    // Procesa respuesta (que viene de API)
    if ($resultadoAPI['estado'] === 'Renovado') {
        $this->enviarAEquipo($nombreEquipo, $resultadoAPI);
    } else {
        $this->enviarAEquipo($nombreEquipo, [
            'tipo' => 'error',
            'mensaje' => $resultadoAPI['mensaje']
        ]);
    }
    break;

// api.php línea 373 (ÚNICA validación)
if ($claveAdmin !== $claveCorrecta) {  // ✅ ÚNICA validación aquí
    jsonOk(["estado" => "Error", "mensaje" => "Clave de administrador incorrecta"]);
    exit;
}
```

---

## 5️⃣ CENTRALIZAR AUTO-INICIO EN API

### ANTES ❌

```php
// api.php línea 220-287
case ESTADO_FINALIZADO:
    // ✅ Verifica condiciones
    $loanExist = loanExists($token, $folio_item_barcode);
    if ($loanExist) { /* rechaza */ }
    
    // ✅ Auto-inicia dentro de API
    $checkout_resp = folioCheckout(...);
    $sesion_id = crearSesion(...);
    jsonOk(["auto_iniciada" => true, ...]);

// server.php línea 578
if ($decoded['estado'] === 'Finalizado') {
    // ❌ Server TAMBIÉN intenta auto-iniciar
    $payload = [
        'tipo' => 'control',
        'accion' => 'iniciar_auto',
        'auto_iniciada' => true
    ];
}

// win-server.ps1 línea 1407
if ($response.estado -eq 'Finalizado') {
    # ❌ Shell TAMBIÉN intenta auto-iniciar
    $autoInicio = $true
}
```

### DESPUÉS ✅

```php
// ✅ API ÚNICAMENTE controla auto-inicio
// api.php línea 220-287
case ESTADO_FINALIZADO:
    $loanExist = loanExists($token, $folio_item_barcode);
    if ($loanExist) {
        jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
        exit;
    }
    
    $manualblock = folioManualBlock($userId, $token);
    $autoblock = folioAutoBlock($userId, $token);
    if (!empty($manualblock) || !empty($autoblock)) {
        jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
        exit;
    }
    
    $bloqueadoHasta = isset($last['bloqueado_hasta'])
        ? new DateTime($last['bloqueado_hasta'], new DateTimeZone('America/Bogota'))
        : null;
    
    if ($bloqueadoHasta && $now < $bloqueadoHasta) {
        jsonOk(["estado" => "Finalizado", "puede_auto_iniciar" => false]);
        exit;
    }
    
    // ✅ TODAS las condiciones OK, auto-inicia
    try {
        $checkout_resp = folioCheckout($token, $folio_item_barcode, $userBarcode, $servicePointId);
        $sesion_id = crearSesion($conn, $userId, $username_full, $id_equipo, $intervaloTiempo);
        
        jsonOk([
            "estado" => "Abierto",
            "auto_iniciada" => true,  // ✅ Indica que fue auto-inicio
            "sesion_id" => $sesion_id,
            "tiempo_restante" => $intervaloTiempo,
            "mensaje" => "Sesión iniciada automáticamente"
        ]);
    } catch (Exception $e) {
        jsonOk([
            "estado" => "Finalizado",
            "puede_auto_iniciar" => true,
            "puede_auto_iniciar_error" => $e->getMessage()
        ]);
    }
    exit;

// ✅ Server simplemente retransmite
// server.php línea 578
// NO intenta auto-iniciar, solo retransmite lo que dijo API
if (isset($decoded['auto_iniciada']) && $decoded['auto_iniciada']) {
    $this->log("✅ Auto-inicio realizado por API");
}

// Envía al Shell exactamente lo que dijo API
$respuesta = [
    'tipo' => 'respuesta_estado',
    'estado' => $decoded['estado'],
    'auto_iniciada' => $decoded['auto_iniciada'] ?? false,
    'sesion_id' => $decoded['sesion_id'] ?? null,
    'correlacion_id' => $correlacionId,
    'timestamp' => date('c')
];

// ✅ Shell simplemente recibe y actualiza UI
// win-server.ps1 línea 1407
if ($response.auto_iniciada -eq $true) {
    Write-Log "✅ Sesión auto-iniciada por API" -Tipo Success
    $Controles.LabelTimer.Text = "00:00:30"
    # Actualiza UI, punto
}
```

---

## 6️⃣ VALIDAR ORIGEN Y DESTINO (WHITELIST)

### ANTES ❌

```php
// api.php
if ($origen == 'server' && $destino == 'api') {
    // Procesa, pero no valida exhaustivamente
}
```

### DESPUÉS ✅

```php
// api.php - Agregar al inicio del procesamiento
class ComunicacionValidator {
    
    // Definir orígenes y destinos válidos
    const ORIGENES_VALIDOS = ['shell', 'server', 'dashboard', 'api'];
    const DESTINOS_VALIDOS = ['api', 'shell', 'server', 'dashboard'];
    
    // Rutas permitidas (origen → destino)
    const RUTAS_PERMITIDAS = [
        'shell' => ['server', 'api'],
        'server' => ['api', 'shell', 'dashboard'],
        'dashboard' => ['server', 'api'],
        'api' => ['shell', 'server', 'dashboard']
    ];
    
    public static function validar($origen, $destino, $tipo) {
        // Validar que origen existe
        if (!in_array($origen, self::ORIGENES_VALIDOS)) {
            return [
                'valido' => false,
                'error' => "Origen inválido: $origen"
            ];
        }
        
        // Validar que destino existe
        if (!in_array($destino, self::DESTINOS_VALIDOS)) {
            return [
                'valido' => false,
                'error' => "Destino inválido: $destino"
            ];
        }
        
        // Validar ruta permitida
        if (!in_array($destino, self::RUTAS_PERMITIDAS[$origen] ?? [])) {
            return [
                'valido' => false,
                'error' => "Ruta no permitida: $origen → $destino"
            ];
        }
        
        // Validaciones específicas por tipo
        switch ($tipo) {
            case 'control':
                if ($origen !== 'server' || $destino !== 'api') {
                    return [
                        'valido' => false,
                        'error' => "Para 'control', solo server→api es válido"
                    ];
                }
                break;
                
            case 'comando_api':
                if ($origen !== 'server' || $destino !== 'api') {
                    return [
                        'valido' => false,
                        'error' => "Para 'comando_api', solo server→api es válido"
                    ];
                }
                break;
        }
        
        return ['valido' => true];
    }
}

// Uso en api.php
$validacion = ComunicacionValidator::validar($origen, $destino, $tipo);
if (!$validacion['valido']) {
    jsonError($validacion['error']);
    exit;
}

// Si llegamos aquí, la ruta es válida
// Procesar normalmente...
```

---

## 7️⃣ USAR CAMPOS CONSISTENTES

### ANTES ❌

```php
// Dashboard envía "action"
$data['action'] = 'aceptar_renovacion';

// API espera "accion"
$accion = $data['accion'];  // NULL - error silencioso

// server.php a veces usa $accionDashboard
$accionDashboard = $data['action'] ?? null;

// Inconsistencia total
```

### DESPUÉS ✅

```php
// ✅ DEFINIR CONSTANTES GLOBALES
const CAMPOS_REQUERIDOS = [
    'tipo',
    'origen',
    'destino',
    'accion',  // ✅ SIEMPRE "accion", nunca "action"
    'timestamp',
    'correlacion_id'
];

const CAMPOS_OPCIONALES = [
    'mensaje',
    'resultado',
    'estado',
    'usuario'
];

// ✅ FUNCIÓN PARA VALIDAR ESTRUCTURA
function validarMensaje($data, $requeridos = CAMPOS_REQUERIDOS) {
    $faltantes = [];
    foreach ($requeridos as $campo) {
        if (!isset($data[$campo])) {
            $faltantes[] = $campo;
        }
    }
    
    if (!empty($faltantes)) {
        return [
            'valido' => false,
            'error' => 'Campos faltantes: ' . implode(', ', $faltantes)
        ];
    }
    
    return ['valido' => true];
}

// ✅ EN TODOS LOS LUGARES
// server.php
$validation = validarMensaje($data);
if (!$validation['valido']) {
    $this->log("❌ Mensaje inválido: {$validation['error']}");
    return;
}

// Ahora sabemos que $data['accion'] siempre existe
$accion = $data['accion'];
$origen = $data['origen'];
$destino = $data['destino'];

// api.php - Igual
$validation = validarMensaje($input);
if (!$validation['valido']) {
    jsonError($validation['error']);
    exit;
}

// Ahora sabemos que todo está presente
$accion = $input['accion'];
```

---

## 8️⃣ ESTRUCTURA ESTÁNDAR DE MENSAJES

### Plantilla ✅

```json
{
  "tipo": "solicitar_estado|respuesta_estado|comando|confirmacion|etc",
  "accion": "renovar|finalizar|bloquear|etc (si aplica)",
  "origen": "shell|server|api|dashboard",
  "destino": "shell|server|api|dashboard",
  "correlacion_id": "A1B2C3D4E5F6G7H8 (mismo en toda la cadena)",
  "timestamp": "2025-12-04T10:30:45.123Z (ISO 8601 UTC)",
  "username": "usuario (si aplica)",
  "mac_address": "AA:BB:CC:DD:EE:FF (si aplica)",
  "estado": "Abierto|Suspendido|Finalizado (si aplica)",
  "mensaje": "Descripción o error",
  "datos": {
    "campo_extra": "valor"
  }
}
```

### Ejemplo completo ✅

```json
{
  "tipo": "solicitar_estado",
  "accion": null,
  "origen": "shell",
  "destino": "server",
  "correlacion_id": "A1B2C3D4E5F6G7H8",
  "timestamp": "2025-12-04T10:30:45.123Z",
  "username": "usuario@example.com",
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "nombre_equipo": "DESKTOP-XYZ",
  "estado": null,
  "mensaje": "Solicitando estado actual",
  "datos": {}
}

→ Server procesa →

{
  "tipo": "respuesta_estado",
  "accion": null,
  "origen": "server",
  "destino": "shell",
  "correlacion_id": "A1B2C3D4E5F6G7H8",
  "timestamp": "2025-12-04T10:30:46.456Z",
  "username": "usuario@example.com",
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "nombre_equipo": "DESKTOP-XYZ",
  "estado": "Abierto",
  "mensaje": "Sesión abierta en curso",
  "datos": {
    "tiempo_restante": 1245,
    "sesion_id": 42
  }
}
```

---

## 📋 LISTA DE VERIFICACIÓN POR ARCHIVO

### server.php

```php
// Línea 136: ✅ case 'renovar_clave'
// - [ ] Agregar 'destino': 'api'
// - [ ] Agregar 'timestamp'
// - [ ] Eliminar validación de clave

// Línea 174: ✅ case 'renovar_clave' (segundo)
// - [ ] Mismo que arriba

// Línea 203: ✅ Envío a API
// - [ ] Agregar 'destino': 'api'
// - [ ] Agregar 'timestamp'

// Línea 269: ✅ Validación admin
// - [ ] Agregar 'destino': 'api'
// - [ ] Agregar 'timestamp'

// Línea 304: ✅ Renovación admin
// - [ ] Agregar 'destino': 'api'
// - [ ] Agregar 'timestamp'

// Línea 402: ✅ Función llamarAPI
// - [ ] Implementar reintentos
// - [ ] Agregar logging con correlacion_id

// Línea 1486: ✅ procesarSolicitudEstado
// - [ ] Agregar correlacion_id
// - [ ] Agregar validación de origen/destino
// - [ ] Agregar reintentos

// Línea 1596: ✅ respuesta_solicitud
// - [ ] Cambiar 'action' a 'accion'
// - [ ] Agregar correlacion_id
// - [ ] Agregar validación

// Línea 1657: ✅ Envío a API para comando
// - [ ] Agregar 'destino': 'api'
// - [ ] Agregar 'timestamp'
// - [ ] Agregar 'correlacion_id'

// Línea 1700: ✅ Envío a API para renovación
// - [ ] Cambiar timeout de 10s a 15s
// - [ ] Agregar 'destino': 'api'
// - [ ] Agregar 'timestamp'
// - [ ] Agregar 'correlacion_id'
```

### api.php

```php
// Línea 110: ✅ case 'control'
// - [ ] Agregar validación de origen/destino
// - [ ] Agregar correlacion_id en logs

// Línea 353: ✅ case 'comando_api'
// - [ ] Agregar validación de origen/destino
// - [ ] Implementar reintentos para FOLIO
// - [ ] Agregar correlacion_id en logs

// Línea 220: ✅ ESTADO_FINALIZADO
// - [ ] Centralizar auto-inicio
// - [ ] Eliminar duplicaciones
// - [ ] Agregar correlacion_id
```

### win-server.ps1

```powershell
# Línea 1107: ✅ Request-EstadoViaWS
# - [ ] Aumentar timeout a 30s
# - [ ] Agregar correlacion_id
# - [ ] Agregar reintentos

# Línea 1239: ✅ Start-SessionLoop
# - [ ] Agregar reintentos para Request-EstadoViaWS
# - [ ] Validar correlacion_id en respuesta

# Línea 1407: ✅ Estado FINALIZADO
# - [ ] NO intentar auto-inicio localmente
# - [ ] Solo confiar en API
```

---

**Documentación de correcciones generada:** 2025-12-04

