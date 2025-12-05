# ============================================================
# 📋 RESUMEN DE CAMBIOS: Unificación solicitar_estado
# ============================================================

## ❌ PROBLEMA IDENTIFICADO
- Cliente PowerShell espera `respuesta_estado` con `destino='shell'` en `CommandQueue`
- Timeout de 30-35 segundos sin respuesta
- Servidor NO estaba incluyendo `nombre_equipo` ni `username` en la respuesta
- Sin logs detallados para diagnosticar dónde se perdía el mensaje

## ✅ SOLUCIONES IMPLEMENTADAS

### 1. **servers/server.php - procesarSolicitudEstado**

#### Antes:
```php
$respuesta = [
    'tipo' => 'respuesta_estado',
    'origen' => 'server',
    'destino' => 'shell',
    'estado' => $estado,
    'mensaje' => $mensaje,
];
$respuesta = array_merge($respuesta, $decoded);
$respuesta['api_response_raw'] = $apiResponse;
$from->send(json_encode($respuesta));
```

#### Ahora:
```php
// Respuesta base con campos obligatorios para que cliente la acepte
$respuesta = [
    'tipo' => 'respuesta_estado',
    'origen' => 'server',
    'destino' => 'shell',
    'estado' => $estado,
    'mensaje' => $mensaje,
    'nombre_equipo' => $nombreEquipo,        // ← AÑADIDO
    'username' => $username,                 // ← AÑADIDO
    'timestamp' => date('c')                 // ← AÑADIDO
];

// Fusionar con toda la respuesta API
$respuesta = array_merge($respuesta, $decoded);
// Garantizar que destino='shell' no sea sobreescrito por API
$respuesta['destino'] = 'shell';             // ← PROTECCIÓN

$jsonRespuesta = json_encode($respuesta);
$from->send($jsonRespuesta);
$this->log("📤 Enviado respuesta_estado: tipo={$respuesta['tipo']}, estado={$estado}, destino=shell");
```

#### Cambios:
- ✅ Añadido `nombre_equipo` → cliente puede validar que es su respuesta
- ✅ Añadido `username` → cliente puede verificar usuario correcto
- ✅ Añadido `timestamp` → para sincronización
- ✅ Protección: fuerza `destino='shell'` después del merge (por si API lo cambia)
- ✅ Mejorados logs con detalles del envío

### 2. **Flujo de Solicitud API**

#### Antes:
```php
$payload = [
    'tipo' => 'comando_api',
    'username' => $username,
    'mac_address' => $macAddress,
    'origen' => 'server',
    'destino' => 'api',
    'clave_admin' => $this->claveCorrecta
];
```

#### Ahora:
```php
$payload = [
    'tipo' => 'comando_api',
    'accion' => 'estado',                    // ← AÑADIDO
    'username' => $username,
    'mac_address' => $macAddress,
    'nombre_equipo' => $nombreEquipo,        // ← AÑADIDO
    'origen' => 'server',
    'destino' => 'api',
    'clave_admin' => $this->claveCorrecta,
    'timestamp' => date('c')                 // ← AÑADIDO
];
```

#### Cambios:
- ✅ Incluir `accion='estado'` para que API lo procese correctamente
- ✅ Incluir `nombre_equipo` para contexto
- ✅ Incluir `timestamp` para auditoría

### 3. **Mejoras de Logs**

| Punto | Antes | Ahora |
|-------|-------|-------|
| Recepción | `📊 Solicitud de estado de: ...` | `📬 Solicitud de estado recibida de: ...` |
| Llamada API | Sin log | `🌐 Llamando API con accion='estado'` |
| Respuesta API | `✅ Estado obtenido: ...` | `✅ Respuesta API: estado=..., mensaje=...` |
| Envío al equipo | Sin detalles | `📤 Enviado respuesta_estado: tipo=respuesta_estado, estado=..., destino=shell` |

## 🔗 FLUJO UNIFICADO AHORA

```
PowerShell Client
    ↓ solicitar_estado {tipo, origen='equipo', destino='server', ...}
    ↓
WebSocket Server (server.php)
    ↓ case 'solicitar_estado'
    ↓ procesarSolicitudEstado()
    ↓
    ├─ Validar credenciales
    ├─ Construir payload para API
    └─ Llamar http://localhost/autoprestamos/prueba_equipos/api.php
        ↓
        API (api.php)
        ↓ Procesa accion='estado'
        ↓ Retorna {estado, mensaje, auto_iniciada, sesion_id, ...}
        ↓
Server recibe respuesta API
    ↓
    ├─ Construir respuesta_estado {tipo, origen='server', destino='shell', estado, nombre_equipo, username, ...}
    ├─ Garantizar destino='shell'
    └─ $from->send() por socket
        ↓
PowerShell Client (runspace WebSocket)
    ↓ ReceiveAsync() recibe mensaje
    ↓ Valida origen='server'
    ↓ Validar tipo='respuesta_estado'
    ↓ Enqueue en CommandQueue
        ↓
Request-EstadoViaWS()
    ↓ Espera en CommandQueue
    ↓ Encuentra tipo='respuesta_estado' Y destino='shell'
    ↓ ✅ Retorna respuesta completa
```

## 📊 PRUEBA RECOMENDADA

Ejecutar script de test:
```powershell
c:\xampp\htdocs\autoprestamos\test_solicitar_estado.ps1
```

Verifica:
1. ✅ Conecta a WebSocket
2. ✅ Se registra como equipo
3. ✅ Solicita estado
4. ✅ **IMPORTANTE**: Respuesta incluya `destino='shell'` y `nombre_equipo`
5. ✅ Respuesta llega en < 5 segundos (no timeout 30s)

## 🔍 DIAGNÓSTICO SI TIMEOUT PERSISTE

Si el test aún tiene timeout:

1. **Verificar logs del servidor**:
   ```bash
   tail -f c:\xampp\htdocs\autoprestamos\servers\server.log
   ```
   Buscar líneas con `📬 Solicitud de estado recibida` y `📤 Enviado respuesta_estado`

2. **Verificar API responde**:
   ```powershell
   $payload = @{tipo='comando_api'; accion='estado'; username='test'; mac_address='AA:BB:CC:DD:EE:FF'} | ConvertTo-Json
   Invoke-RestMethod -Uri "http://localhost/autoprestamos/prueba_equipos/api.php" -Method Post -Body $payload -ContentType 'application/json'
   ```

3. **Verificar socket llega**:
   - Cliente ve "📩 Recibido:" en logs del runspace WebSocket
   - Si falta, problema de conectividad socket

