# 📊 ANÁLISIS DE FLUJO DE COMUNICACIÓN - SISTEMA AUTOPRÉSTAMOS

## 🎯 Objetivo de Revisión
Validar que el flujo de comunicación sea: **Shell ↔ Server ↔ API** o **Dashboard ↔ Server ↔ API/Shell**

---

## 📋 FLUJOS IDENTIFICADOS

### ✅ FLUJO 1: SHELL → SERVER → API (Solicitud de Estado)

**Caso:** El PowerShell solicita el estado de una sesión

```
┌─────────────┐      WS       ┌─────────────┐      cURL      ┌─────────────┐
│   SHELL     │ ────────────→ │   SERVER    │ ────────────→  │    API      │
│ (PowerShell)│ solicitar_    │ (WebSocket) │  tipo:control  │  (PHP)      │
│             │  estado       │             │   origen:      │             │
└─────────────┘               └─────────────┘   server       └─────────────┘
                                    ↑                              │
                                    │                              │
                                    └──────────────────────────────┘
                                    respuesta JSON con estado
```

**Archivos:**
- **win-server.ps1** (Línea 1105): `Request-EstadoViaWS()`
  - Envía mensaje WebSocket tipo `solicitar_estado`
  - Campos: `tipo`, `username`, `nombre_equipo` , `mac_address`, `origen`, `destino`

- **server.php** (Línea 1486): `procesarSolicitudEstado()`
  - Recibe solicitud WebSocket
  - Llama a API con `cURL`
  - Retorna respuesta al Shell via WebSocket

- **api.php** (Línea 110-140): `case 'control'`
  - Valida origen: `origen == 'server' && destino == 'api'`
  - Procesa estado de sesión
  - Retorna JSON con `estado`, `mensaje`, `tiempo_restante`

**Status:** ✅ CORRECTO

---

### ✅ FLUJO 2: SHELL → SERVER → API (Envío de Comando)

**Caso:** El PowerShell ejecuta una acción (bloquear, finalizar, renovar)

```
┌─────────────┐      WS       ┌─────────────┐      cURL      ┌─────────────┐
│   SHELL     │ ────────────→ │   SERVER    │ ────────────→  │    API      │
│ (PowerShell)│ confirmacion  │ (WebSocket) │  tipo:         │  (PHP)      │
│             │ de comando    │             │   comando_api  │             │
└─────────────┘               └─────────────┘   origen:      └─────────────┘
                                    ↑            server           │
                                    │                             │
                                    └─────────────────────────────┘
                                    confirmacion_comando JSON
```

**Archivos:**
- **win-server.ps1** (Línea 469-565): `Invoke-AccionControl()`
  - Prepara payload con `tipo`: `confirmacion`, `resultado`: `ejecutando`
  - Envía via WebSocket al Server
  - Espera confirmación de ejecución

- **server.php** (Línea 1626): `case 'confirmacion'`
  - Recibe confirmación del Shell
  - **SI** `resultado == 'ejecutando'` y acción ≠ 'mensaje':
    - Llama a API con `cURL`
    - Tipo: `comando_api`
    - Acción: `finalizar`, `bloquear`, `renovar`, etc.
  - Reenvía confirmación al Shell
  - Notifica dashboards

- **api.php** (Línea 353): `case 'comando_api'`
  - Valida clave admin
  - Ejecuta acción correspondiente
  - Actualiza BD
  - Hace checkout/checkin en FOLIO
  - Retorna confirmación con `estado`: `Renovado`, `Bloqueado`, `FINALIZADO`

**Status:** ✅ CORRECTO

---

### ✅ FLUJO 3: DASHBOARD → SERVER → API

**Caso:** Dashboard solicita renovación (aceptar/rechazar)

```
┌─────────────┐      WS       ┌─────────────┐      cURL      ┌─────────────┐
│  DASHBOARD  │ ────────────→ │   SERVER    │ ────────────→  │    API      │
│ (Web)       │ respuesta_    │ (WebSocket) │  tipo:         │  (PHP)      │
│             │ solicitud     │             │   comando_api  │             │
└─────────────┘               └─────────────┘   accion:      └─────────────┘
                                    ↑            renovar/        │
                                    │            finalizar        │
                                    │                             │
                                    └─────────────────────────────┘
                                    Respuesta API
                                         │
                                         ↓
                                    ┌─────────────┐
                                    │   SHELL     │
                                    │ (PowerShell)│
                                    └─────────────┘
                                    confirmacion_solicitud
```

**Archivos:**
- **dashboard.php**: Envía `respuesta_solicitud` con `action`: `aceptar_renovacion` o `rechazar_renovacion`

- **server.php** (Línea 1596): `case 'respuesta_solicitud'`
  1. Recibe respuesta del Dashboard
  2. Busca datos de sesión en BD
  3. Determina acción API: `renovar` o `finalizar`
  4. Llama API con `comando_api`
  5. Envía confirmación al Shell (si conectado)
  6. Notifica dashboards del resultado

- **api.php** (Línea 353): `case 'comando_api'`
  - Ejecuta acción
  - Retorna confirmación

**Status:** ✅ CORRECTO

---

### ⚠️ FLUJO 4: SERVER → DASHBOARD (Notificaciones)

**Caso:** Server notifica a todos los dashboards sobre cambios

```
┌─────────────┐      WS       ┌─────────────┐      WS       ┌─────────────┐
│   SERVER    │ ────────────→ │  DASHBOARD  │               │  DASHBOARD  │
│ (WebSocket) │ Broadcast a   │ (Web)       │               │ (Web)       │
│             │ todos los     └─────────────┘               └─────────────┘
└─────────────┘ dashboards
```

**Archivos:**
- **server.php** (Línea 1263): `notificarDashboards()`
  - Envía JSON a todos los clientes con `tipoCliente === 'dashboard'`
  - Se llama desde:
    - Desbloqueos automáticos
    - Solicitadas renovaciones
    - Resultados de comandos
    - Desconexiones de equipos
    - Cambios de estado

**Status:** ✅ CORRECTO

---

### ⚠️ FLUJO 5: SERVER → SHELL (Control de Comandos)

**Caso:** Dashboard envía comando, Server se lo retransmite al Shell

```
┌─────────────┐      WS       ┌─────────────┐      WS       ┌─────────────┐
│  DASHBOARD  │ ────────────→ │   SERVER    │ ────────────→ │   SHELL     │
│ (Web)       │ comando       │ (WebSocket) │ control_      │ (PowerShell)│
│             │ origen:       │             │  server       │             │
└─────────────┘ dashboard     └─────────────┘               └─────────────┘
                                    ↑                             │
                                    │                             │
                                    └─────────────────────────────┘
                                    Confirmación de ejecución
```

**Archivos:**
- **dashboard.php**: Envía `comando` con `accion`: `mensaje`, `info`, etc. y `origen`: `dashboard`

- **server.php** (Línea 519): `case 'comando'`
  - Si `origen === 'dashboard'`
  - Busca el Shell conectado por nombre de equipo
  - Envía `control_server` con detalles del comando

- **win-server.ps1** (Línea 591): `Start-CommandQueueMonitor()`
  - Timer monitorea `CommandQueue`
  - Recibe `control_server` del Server
  - Ejecuta acción mediante `Invoke-AccionControl()`
  - Retorna `confirmacion`

**Status:** ✅ CORRECTO

---

## 🔍 PROBLEMAS ENCONTRADOS

### ❌ PROBLEMA 1: Origen/Destino inconsistente en algunos casos

**Ubicación:** `api.php` línea 110

```php
if ($origen == 'server' && $destino == 'api') {
    // Solo procesa si viene del SERVER
```

**Issue:** El Shell a veces envía con `origen: 'equipo'` en lugar de `origen: 'server'`

**Impacto:** ⚠️ BAJO - El control principal va bien, pero hay inconsistencia de terminología

---

### ❌ PROBLEMA 2: Validación de clave admin mixta

**Ubicación:** `api.php` línea 353 y `server.php` línea 136

```php
// En API.php (línea 373)
if ($claveAdmin !== $claveCorrecta) {
    jsonOk(["estado" => "Error", "mensaje" => "Clave de administrador incorrecta"]);
    exit;
}

// En server.php (línea 142)
// Además valida en server ANTES de llamar API
$claveAdmin = $data['clave_admin'] ?? null;
```

**Issue:** La clave se valida en AMBOS lugares

**Impacto:** ⚠️ MEDIO - Hay lógica duplicada y potencial inconsistencia

---

### ❌ PROBLEMA 3: Flujo de auto-inicio confuso

**Ubicación:** `api.php` línea 220-287, `server.php` línea 578

**Issue:** 
- El auto-inicio se activa en el estado FINALIZADO
- Pero hay validación en VARIOS puntos
- Shell y Server pueden desincronizarse

**Impacto:** 🔴 ALTO - Puede causar sesiones fantasma o no iniciarse

---

### ❌ PROBLEMA 4: No hay confirmación de entrega (ACK)

**Ubicación:** Todos los flujos

**Issue:** 
- Shell envía mensaje al Server
- Server envía a API
- API responde al Server
- Pero Shell NO sabe si llegó correctamente

**Impacto:** 🔴 ALTO - Mensajes pueden perderse sin saberlo

---

### ❌ PROBLEMA 5: Inconsistencia en estructura de respuestas

**Ubicación:** Múltiples archivos

| Componente | Campo de estado | Posibles valores |
|-----------|-----------------|------------------|
| API | `estado` | `Abierto`, `Suspendido`, `Bloqueado`, `Finalizado`, `Renovado` |
| Server | `estado` | A veces usa `estado`, a veces `resultado` |
| Shell | `estado` | Mapea desde API pero con variaciones |

**Impacto:** ⚠️ MEDIO - Dificulta depuración y mantenimiento

---

## 📊 TABLA RESUMEN DE FLUJOS

| Flujo | Shell | Server | API | Dashboard | Estado |
|-------|-------|--------|-----|-----------|--------|
| **Solicitar estado** | WS | WS→cURL | PHP | — | ✅ OK |
| **Ejecutar comando** | WS | WS→cURL | PHP | — | ✅ OK |
| **Solicitar renovación** | — | — | PHP | — | ✅ OK |
| **Dashboard aprueba** | WS | WS→cURL | PHP | WS | ✅ OK |
| **Notificar cambios** | — | Broadcast | — | WS | ✅ OK |
| **Comando dashboard** | WS | WS | — | WS | ✅ OK |
| **Auto-inicio** | WS | — | PHP | — | ⚠️ COMPLEJO |

---

## 🎯 RECOMENDACIONES

### 1. **Implementar patrón ACK (Acknowledgment)**
```
Shell → Server: "ejecutando comando X"
Server → Shell: "✅ Recibido comando X"
Server → API: "procesar comando X"
API → Server: "✓ Procesado"
Server → Shell: "✅ Completado"
Shell → Server: "✅ Confirmado"
```

### 2. **Estandarizar estructura de respuestas**
```json
{
  "tipo": "respuesta_estado",
  "accion": "nombre_accion",
  "estado": "ABIERTO|SUSPENDIDO|BLOQUEADO|FINALIZADO|ERROR",
  "mensaje": "descripción",
  "timestamp": "2025-12-04T10:30:00",
  "origen": "api|server|shell",
  "destino": "shell|dashboard|api",
  "correlacion_id": "uuid"
}
```

### 3. **Centralizar validación de claves**
- Solo en un lugar (preferentemente API)
- Server confía en API

### 4. **Implementar timeout y reintentos**
```powershell
$timeout = 30 segundos
$reintentos = 3
Si no responde → reintentar
```

### 5. **Log de correlación**
Cada mensaje debe tener un `correlacion_id` único para rastrear flujos completos

### 6. **Validar origen/destino consistentemente**
```php
$origenesValidos = ['shell', 'server', 'dashboard', 'api'];
$destinosValidos = ['shell', 'server', 'dashboard', 'api'];

if (!in_array($origen, $origenesValidos)) {
    jsonError("Origen inválido: $origen");
}
```

---

## 🔄 FLUJO IDEAL PROPUESTO

### Caso: Shell ejecuta comando "finalizar"

```
1. SHELL ENVÍA
   ├─ tipo: "solicitar_accion"
   ├─ accion: "finalizar"
   ├─ correlacion_id: "abc123"
   └─ origen: "shell", destino: "server"

2. SERVER RECIBE
   ├─ Confirma al Shell: ACK + correlacion_id
   ├─ Valida estructura
   └─ Enruta a API

3. SERVER → API (cURL)
   ├─ tipo: "comando_api"
   ├─ accion: "finalizar"
   ├─ correlacion_id: "abc123"
   └─ origen: "server", destino: "api"

4. API PROCESA
   ├─ Valida sesión
   ├─ Ejecuta acción
   ├─ Actualiza BD
   └─ Retorna respuesta con correlacion_id

5. SERVER → SHELL (Confirmación)
   ├─ tipo: "respuesta_accion"
   ├─ correlacion_id: "abc123"
   ├─ estado: "FINALIZADO"
   └─ origen: "server", destino: "shell"

6. SHELL CONFIRMA
   ├─ Recibe confirmación
   ├─ Actualiza UI
   └─ ACK al Server

7. SERVER → DASHBOARD (Broadcast)
   ├─ tipo: "notificacion_cambio"
   ├─ accion: "finalizar"
   ├─ correlacion_id: "abc123"
   └─ estado: "FINALIZADO"
```

---

## 📋 CHECKLIST DE VALIDACIÓN

- [ ] Todos los mensajes tienen `origen` y `destino`
- [ ] Los campos `estado` son consistentes
- [ ] Hay confirmación (ACK) en cada salto
- [ ] Timeout implementado en todos los WS
- [ ] Reintentos automáticos para fallos transitorios
- [ ] Logs incluyen `correlacion_id`
- [ ] Shell puede retransmitir si no recibe ACK
- [ ] API valida `origen` y `destino`
- [ ] Server no realiza doble procesamiento
- [ ] Dashboard recibe notificaciones de todos los cambios

---

**Última revisión:** 2025-12-04
**Versión del código analizado:** Shell v2.3, Server v2.1, API v1.0

