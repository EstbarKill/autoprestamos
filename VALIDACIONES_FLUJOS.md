# ✅/❌ VALIDACIONES DE FLUJO DE COMUNICACIÓN

## 1️⃣ VALIDACIÓN DE ESTRUCTURA DE MENSAJES

### 1.1 WebSocket: Shell → Server (Solicitud de Estado)

| Aspecto | Validación | Status |
|---------|-----------|--------|
| `tipo` requerido | ✅ Presente: `"tipo": "solicitar_estado"` | ✅ CORRECTO |
| `username` requerido | ✅ Presente en formulario | ✅ CORRECTO |
| `mac_address` requerido | ✅ Detectado automáticamente | ✅ CORRECTO |
| `origen` especificado | ✅ Presente: `"origen": "shell"` | ✅ CORRECTO |
| `destino` especificado | ✅ Presente: `"destino": "server"` | ✅ CORRECTO |

**Ubicación:** `win-server.ps1` línea 1124

```powershell
@{
    tipo = "solicitar_estado"
    username = $Global:Config.Username
    mac_address = $Global:SharedState.MacAddress
    nombre_equipo = $Global:Config.IdEquipo
    origen = "shell"
    destino = "server"
}
```

✅ **VALIDADO CORRECTAMENTE**

---

### 1.2 cURL: Server → API (Comando)

| Aspecto | Validación | Status |
|---------|-----------|--------|
| `tipo` requerido | ✅ Presente: `"tipo": "comando_api"` | ✅ CORRECTO |
| `accion` requerido | ✅ Presente | ✅ CORRECTO |
| `username` requerido | ✅ Presente | ✅ CORRECTO |
| `mac_eq` requerido | ✅ Presente | ✅ CORRECTO |
| `origen` especificado | ✅ Presente: `"origen": "server"` | ✅ CORRECTO |
| `destino` especificado | ⚠️ **NO SIEMPRE** | ⚠️ INCOMPLETO |

**Ubicación:** `server.php` línea 1657-1672

```php
$apiPayload = [
    'tipo'      => 'comando_api',
    'accion'    => $accionAPI,
    'username'  => $username,
    'mac_eq'    => $mac_eq,
    'origen'    => 'server'
    // ❌ FALTA: 'destino' => 'api'
];
```

⚠️ **RECOMENDACIÓN:** Agregar campo `destino` consistentemente

---

### 1.3 WebSocket: Server → Dashboard (Notificación)

| Aspecto | Validación | Status |
|---------|-----------|--------|
| `tipo` requerido | ✅ Presente | ✅ CORRECTO |
| `origen` especificado | ⚠️ A veces | ⚠️ INCONSISTENTE |
| `timestamp` incluido | ⚠️ No siempre | ⚠️ INCONSISTENTE |

**Ubicación:** `server.php` línea 1228-1235, 1261-1275

```php
// ✅ Con timestamp
$this->notificarDashboards([
    'tipo'      => 'estado_cambiado',
    'id_sesion' => $idSesion,
    'estado'    => 'finalizado',
    'hora'      => date("Y-m-d H:i:s")  // ✅ incluido
]);

// ❌ Sin timestamp
foreach ($this->clients as $client) {
    $client->send(json_encode([
        'tipo' => 'log',
        'id' => $id,
        'mensaje' => $data['mensaje']
        // ❌ FALTA: timestamp
    ]));
}
```

⚠️ **RECOMENDACIÓN:** Usar timestamp en TODAS las notificaciones

---

## 2️⃣ VALIDACIÓN DE ENRUTAMIENTO (origen → destino)

### 2.1 Rutas Válidas

```
SHELL                          SERVER                         API
  │                              │                             │
  ├─ "solicitar_estado"  ──────→ procesarSolicitudEstado ──→ case 'control'
  │  origen: "shell"            origen: "server"
  │  destino: "server"          destino: "api"
  │
  ├─ "confirmacion"      ──────→ onMessage/confirmacion  ──→ case 'comando_api'
  │  resultado: ejecutando      (si ejecutando)
  │  
  ├─ "registro"          ──────→ procesarRegistroEquipo  ──→ State initial
  │                                                    
  │
┌─┘
│
DASHBOARD
  │
  ├─ "comando"           ──────→ case 'comando'         ──→ (Shell relay)
  │  origen: "dashboard"         Busca shell
  │  
  └─ "respuesta_solicitud" ────→ case 'respuesta_solicitud' ──→ case 'comando_api'
     action: "aceptar_renovacion"    Busca sesión en BD
                                     Llama API
```

✅ **VALIDADO**

---

### 2.2 Rutas Inválidas (Problemas Encontrados)

#### ❌ PROBLEMA 1: API recibe con `origen: "equipo"` en lugar de `"server"`

**Ubicación:** `api.php` línea 110

```php
if ($origen == 'server' && $destino == 'api') {
    // Procesa
}
```

**Issue:** Shell a veces envía directamente a API con `origen: "equipo"`

```powershell
# En win-server.ps1, algunos payloads tienen:
"origen" = "equipo"  ❌ (debería ser "server")
```

**Impacto:** Control principal falla si Shell envía directamente

**Solución:**
```php
// En api.php
if (($origen == 'server' || $origen == 'equipo') && $destino == 'api') {
    // Procesa ambos casos
}
```

---

#### ❌ PROBLEMA 2: `destino` no siempre presente en cURL API

**Ubicación:** `server.php` línea 1657-1672, 1700-1715

```php
// ❌ Sin destino
$apiPayload = [
    'tipo'      => 'comando_api',
    'accion'    => $accionAPI,
    'username'  => $username,
    'mac_eq'    => $mac_eq,
    'origen'    => 'server'
    // FALTA: 'destino' => 'api'
];
```

**Impacto:** API no puede validar completamente el flujo

---

#### ❌ PROBLEMA 3: Inconsistencia en estructura de `accion` vs `tipo`

| Componente | Usa | Ejemplo |
|-----------|-----|---------|
| Shell | `accion` | `"accion": "finalizar"` |
| Server | A veces `accion`, a veces `action` | Inconsistente |
| API | `accion` | `"accion": "renovar"` |
| Dashboard | `accion` | `"accion": "solicitar_renovacion"` |

**Ubicación:** `server.php` línea 1596

```php
// ❌ Dashboard usa "action" (singular vs accion)
$accionDashboard = $data['action'] ?? null;  // ❌ 'action'
if ($accionDashboard === "aceptar_renovacion") {
```

**Impacto:** Confusión entre campos, posible pérdida de datos

---

## 3️⃣ VALIDACIÓN DE ESTADO (Estados Consistentes)

### 3.1 Estados definidos en API

```php
// api.php - Estados de sesión
const ESTADO_ABIERTO     = 1;
const ESTADO_SUSPENDIDO  = 2;
const ESTADO_BLOQUEADO   = 3;
const ESTADO_FINALIZADO  = 4;
```

### 3.2 Valores de estado retornados

| Punto | Campo | Valores posibles | Status |
|-------|-------|-----------------|--------|
| API | `estado` | `"Abierto"`, `"Suspendido"`, `"Bloqueado"`, `"Finalizado"`, `"Renovado"` | ⚠️ Inconsistente |
| Server | `estado` | Mapea desde API | ⚠️ Inconsistente |
| Shell | `estado` | Recibe desde Server/API | ⚠️ Puede variar |

**Problem:** Los valores están en **minúsculas** en BD pero se retornan en **MayúsculasIníciales**

```php
// api.php - Retorna con formato MayúsculasIníciales
jsonOk(["estado" => "Abierto", ...])    // Mayúscula inicial
jsonOk(["estado" => "Suspendido", ...])

// BD - Almacena con CONSTANTES
ESTADO_ABIERTO = 1
ESTADO_SUSPENDIDO = 2
```

**Impacto:** ⚠️ MEDIO - Se entiende, pero inconsistente con BD

---

### 3.3 Validación de transiciones de estado

```
ABIERTO ──(timeout)──> SUSPENDIDO
  │                        │
  │                  (usuario confirma)
  │                        │
  │                        ├─→ BLOQUEADO (si rechaza)
  │                        │       │
  │                        │    (timeout)
  │                        │       │
  └────────────────────────┴──→ FINALIZADO


FINALIZADO ──(auto-inicio)──> ABIERTO
   │                             │
   └──(rechazado)────────────→ BLOQUEADO
```

✅ **VALIDADO** - Las transiciones son correctas

---

## 4️⃣ VALIDACIÓN DE CRIPTOGRAFÍA Y SEGURIDAD

### 4.1 Clave de administrador

| Lugar | Validación |
|-------|-----------|
| `api.php` línea 373 | ✅ Valida `if ($claveAdmin !== $claveCorrecta)` |
| `server.php` línea 142 | ⚠️ También valida (DUPLICADA) |
| `win-server.ps1` línea 22 | ✅ Definida: `"S1m0n_2025"` |

**Issue:** La clave se valida en DOS lugares (API y Server)

```php
// Validación duplicada
// 1. En server.php (línea 142)
if (!$claveAdmin) {
    // Error
}
if ($claveAdmin !== $claveCorrecta) {
    // Error
}

// 2. En api.php (línea 373)
if ($claveAdmin !== $claveCorrecta) {
    jsonOk(["estado" => "Error", ...]);
    exit;
}
```

**Impacto:** 🔴 ALTO
- Lógica duplicada
- Posible desincronización
- Difícil de mantener

**Recomendación:**
```php
// Opción 1: Validar solo en API
// Server confía en API

// Opción 2: Validar solo en Server  
// API no valida de nuevo
```

---

### 4.2 Token FOLIO

| Lugar | Uso |
|-------|-----|
| `tokenByron.php` | ✅ Gestiona token de autenticación |
| `api.php` | ✅ Usa token en todas las llamadas FOLIO |
| `server.php` | ❌ No usa (no tiene acceso a BD) |

✅ **CORRECTO** - Token centralizado en API

---

## 5️⃣ VALIDACIÓN DE TIEMPOS Y TIMEOUTS

### 5.1 Timeouts definidos

| Componente | Timeout | Ubicación |
|-----------|---------|-----------|
| Shell → Server | 15 segundos | `win-server.ps1` línea 1107 |
| Server → API | 30 segundos | `server.php` línea 553 |
| Server → API (renovación) | 10 segundos | `server.php` línea 1700 |
| WebSocket listener | ∞ (bucle infinito) | `win-server.ps1` línea 245 |

**Issue:** Hay inconsistencia en timeouts

```powershell
# win-server.ps1
$timeout = 15  # segundos

# server.php
CURLOPT_TIMEOUT => 30  # segundos
CURLOPT_TIMEOUT => 10  # segundos (diferente)
```

**Impacto:** ⚠️ MEDIO - Shell espera 15s pero Server timeout es 30s

---

### 5.2 Reintentos

| Componente | Reintentos | Status |
|-----------|-----------|--------|
| WebSocket (Shell) | 5 intentos | ✅ Implementado |
| API (Server) | 0 (sin reintentos) | ❌ NO hay reintentos |
| FOLIO (API) | No definido | ⚠️ Desconocido |

**Issue:** Server no reintentar si API falla la primera vez

```php
// server.php - Sin reintentos
$respuestaApi = curl_exec($ch);
if ($error) {
    $this->log("❌ Error: $error");
    return;  // ❌ Sale directo
}
```

**Recomendación:**
```php
$maxReintentos = 3;
$intento = 0;
while ($intento < $maxReintentos) {
    $respuestaApi = curl_exec($ch);
    if (!$error) break;
    $intento++;
    if ($intento < $maxReintentos) sleep(2);
}
```

---

## 6️⃣ VALIDACIÓN DE SINCRONIZACIÓN

### 6.1 ¿Shell y Server sincronizan correctamente?

**Flujo esperado:**
```
Shell envía → Server recibe → API procesa → Server responde → Shell recibe
```

**Validación:**

| Paso | Sincronización | Status |
|------||---|--------|
| Shell → Server | ✅ WebSocket (instant) | ✅ OK |
| Server → API | ✅ cURL (blocking) | ✅ OK |
| API → Server | ✅ cURL response (blocking) | ✅ OK |
| Server → Shell | ✅ WebSocket (instant) | ✅ OK |
| Shell recibe | ⚠️ Listener async | ⚠️ PUEDE FALLAR |

**Issue:** Shell tiene un listener que recibe async, pero no siempre procesa correctamente

```powershell
# Shell recibe mensajes en un runspace separado
$buffer = New-Object Byte[] 8192
while ($WsClient.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
    $result = $WsClient.ReceiveAsync($segment, $ct)
    # Procesa mensaje...
}
```

**Impacto:** ⚠️ BAJO - Generalmente funciona, pero hay race conditions

---

## 7️⃣ MATRIZ DE VALIDACIÓN FINAL

```
┌─────────────────────────────────────────────────────────────────────┐
│  VALIDACIÓN DE FLUJOS DE COMUNICACIÓN - RESUMEN                     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  FLUJO 1: Shell → Server → API (Solicitud Estado)                  │
│  ├─ Estructura: ✅ OK                                              │
│  ├─ Routing: ✅ OK                                                 │
│  ├─ Timeouts: ⚠️ INCONSISTENTE (15s vs 30s)                       │
│  ├─ ACK/Confirmación: ❌ NO EXISTE                                 │
│  └─ Status: ✅ FUNCIONAL, pero mejorable                           │
│                                                                     │
│  FLUJO 2: Shell → Server → API (Comando)                           │
│  ├─ Estructura: ⚠️ FALTA destino en algunos payloads              │
│  ├─ Routing: ✅ OK                                                 │
│  ├─ Validación: ⚠️ DUPLICADA (server + api validan clave)        │
│  ├─ Timeouts: ⚠️ INCONSISTENTE                                    │
│  └─ Status: ⚠️ FUNCIONAL, pero con duplicaciones                  │
│                                                                     │
│  FLUJO 3: Dashboard → Server → API                                 │
│  ├─ Estructura: ⚠️ Usa "action" en lugar de "accion"             │
│  ├─ Routing: ✅ OK                                                 │
│  ├─ Validación: ✅ OK                                              │
│  └─ Status: ✅ FUNCIONAL                                           │
│                                                                     │
│  FLUJO 4: Dashboard → Server → Shell                               │
│  ├─ Estructura: ✅ OK                                              │
│  ├─ Routing: ✅ OK                                                 │
│  ├─ Timeouts: ⚠️ Algunos comandos esperan respuesta               │
│  └─ Status: ✅ FUNCIONAL                                           │
│                                                                     │
│  FLUJO 5: Server → Dashboard (Notificaciones)                      │
│  ├─ Estructura: ⚠️ Inconsistente en timestamps                    │
│  ├─ Routing: ✅ OK (Broadcast)                                     │
│  └─ Status: ✅ FUNCIONAL                                           │
│                                                                     │
│  FLUJO 6: Auto-inicio (API)                                        │
│  ├─ Estructura: ✅ OK                                              │
│  ├─ Condiciones: ⚠️ COMPLEJO, varias validaciones                │
│  ├─ Sincronización: ⚠️ PUEDE DESINCRONIZARSE                      │
│  └─ Status: ⚠️ FUNCIONAL pero riesgoso                            │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 📋 CHECKLIST DE CORRECCIONES RECOMENDADAS

### 🔴 ALTA PRIORIDAD

- [ ] **Implementar ACK** en todos los flujos (Shell → Server → API)
- [ ] **Estandarizar timeouts** (decidir: 10s, 15s, 30s)
- [ ] **Eliminar validación duplicada** de clave admin (solo en API)
- [ ] **Agregar `destino`** en TODOS los payloads cURL

### 🟡 MEDIA PRIORIDAD

- [ ] **Estandarizar estructura de respuestas** (campos consistentes)
- [ ] **Usar "accion"** en lugar de "action" (consistencia)
- [ ] **Agregar timestamps** a TODAS las notificaciones
- [ ] **Implementar reintentos** en Server → API
- [ ] **Añadir `correlacion_id`** para rastrear flujos completos

### 🟢 BAJA PRIORIDAD

- [ ] **Documentar transiciones de estado** en código
- [ ] **Agregar validación** de origen/destino (whitelist)
- [ ] **Implementar rate limiting** en API
- [ ] **Mejorar logs** con más detalles de flujo

---

**Análisis completado:** 2025-12-04  
**Documentos generados:**
1. `ANALISIS_FLUJO_COMUNICACION.md` - Análisis detallado
2. `DIAGRAMAS_FLUJOS_COMUNICACION.md` - Diagramas visuales
3. `VALIDACIONES_FLUJOS.md` - Este documento (validaciones)

