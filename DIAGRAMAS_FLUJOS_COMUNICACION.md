# 🎯 FLUJOS DE COMUNICACIÓN - DIAGRAMAS DETALLADOS

## ARQUITECTURA ACTUAL

```
┌──────────────────────────────────────────────────────────────────┐
│                    SISTEMA AUTOPRÉSTAMOS                         │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────┐       ┌─────────────┐       ┌─────────────┐   │
│  │   SHELL     │       │   SERVER    │       │    API      │   │
│  │ PowerShell  │◄────►│ WebSocket   │◄────►│   PHP       │   │
│  │   v2.3      │   WS  │   v2.1      │ cURL  │   REST      │   │
│  └─────────────┘       └─────────────┘       └─────────────┘   │
│       ▲                       ▲                      │            │
│       │                       │                      ▼            │
│       │                       │                  ┌─────────────┐ │
│       │                       │                  │   BASE DE   │ │
│       │                       │                  │   DATOS     │ │
│       │                       │                  │   MySQL     │ │
│       └───────────────────────┼──────────────────┘             │ │
│                               │                                 │ │
│                        ┌──────┴────────┐                        │ │
│                        │               │                        │ │
│                   ┌─────────────┐ ┌──────────────┐              │ │
│                   │  DASHBOARD  │ │ PUNTOS DE    │              │ │
│                   │   WEB       │ │ SERVICIO     │              │ │
│                   │             │ │ (FOLIO)      │              │ │
│                   └─────────────┘ └──────────────┘              │ │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

## FLUJO 1: INICIALIZACIÓN (Shell → Server)

```
┌──────────────┐
│   SHELL      │ 1️⃣ Detecta MAC y usuario
│ (win-server) │   └─ Get-ActiveNetworkInterface()
└──────┬───────┘   └─ $env:USERNAME
       │
       │ 2️⃣ Conecta a WebSocket
       │   Start-WebSocketProcess()
       │   URI: ws://localhost:8081
       │
       ▼
┌──────────────────────────────────┐
│ WebSocket (PowerShell Runspace)  │
│                                  │
│  Connect-WSClient                │
│  ├─ Intenta conexión             │
│  ├─ Reintentos: 5 veces          │
│  └─ Espera 3 segundos            │
└──────────┬───────────────────────┘
           │
           │ 3️⃣ Envía REGISTRO
           │    {
           │      "tipo": "registro",
           │      "nombre_equipo": "DESKTOP-XYZ",
           │      "username": "usuario",
           │      "mac_address": "AA:BB:CC:DD:EE:FF",
           │      "origen": "equipo"
           │    }
           │
           ▼
┌────────────────────────────────────────────────────────┐
│              SERVER (WebSocket)                        │
│                                                        │
│  onMessage()                                           │
│  case 'registro':                                      │
│    ├─ Registra conexión                               │
│    ├─ Busca punto de servicio en BD                   │
│    └─ $this->equipos[$nombre] = $conexion             │
│                                                        │
│  4️⃣ OBTENER ESTADO INICIAL (cURL)                    │
│    [API Request]                                      │
│    ├─ tipo: "control"                                 │
│    ├─ origen: "equipo", destino: "api"               │
│    └─ username + mac_address                          │
│                                                        │
│  5️⃣ Envía confirmación + estado al Shell              │
│    {                                                   │
│      "tipo": "respuesta_estado",                       │
│      "estado": "Abierto|Suspendido|Finalizado",       │
│      "nombre_equipo": "DESKTOP-XYZ",                  │
│      "registro_completo": true                        │
│    }                                                   │
└────────────────────────────────────────────────────────┘
           │
           │ Confirmación + Estado
           │
           ▼
    ┌──────────────┐
    │   SHELL      │ 6️⃣ Recibe confirmación
    │ Start-Session│   └─ Inicia bucle principal
    │   Loop()     │
    └──────────────┘
```

---

## FLUJO 2: SOLICITUD DE ESTADO PERIÓDICA (Shell → Server → API)

```
┌────────────────────────────────────────────────┐
│  SHELL (Start-SessionLoop)                     │
│                                                │
│  Timer cada 10 segundos:                       │
│  Request-EstadoViaWS()                         │
│                                                │
│  Payload:                                      │
│  {                                             │
│    "tipo": "solicitar_estado",                │
│    "username": "usuario",                     │
│    "mac_address": "AA:BB:CC:DD:EE:FF",        │
│    "nombre_equipo": "DESKTOP-XYZ",            │
│    "origen": "shell",                         │
│    "destino": "server"                        │
│  }                                             │
└────────────────────┬───────────────────────────┘
                     │
                     │ WebSocket Send
                     │
                     ▼
┌────────────────────────────────────────────────┐
│  SERVER (onMessage)                            │
│                                                │
│  case 'solicitar_estado':                      │
│    ├─ Extrae credenciales                      │
│    ├─ Prepara payload API                      │
│    │                                            │
│    │  PAYLOAD:                                 │
│    │  {                                        │
│    │    "tipo": "control",                    │
│    │    "username": "usuario",                │
│    │    "mac_address": "AA:BB:...",           │
│    │    "origen": "server",    ◄─ CAMBIA     │
│    │    "destino": "api"                      │
│    │  }                                        │
│    │                                            │
│    └─ curl_exec() a API                        │
└────────────────────┬───────────────────────────┘
                     │
                     │ HTTP POST
                     │
                     ▼
┌────────────────────────────────────────────────┐
│  API (api.php)                                 │
│                                                │
│  Recibe:                                       │
│  {                                             │
│    "tipo": "control",                         │
│    "origen": "server",                        │
│    "destino": "api",                          │
│    "username": "usuario",                     │
│    "mac_address": "AA:BB:..."                 │
│  }                                             │
│                                                │
│  Procesa:                                      │
│  if ($tipo === 'control' &&                   │
│      $origen === 'server' &&                  │
│      $destino === 'api')                      │
│  {                                             │
│    $last = getUltimaSesion($userId);          │
│    $estado = $last['id_estado_fk'];           │
│                                                │
│    switch ($estado):                          │
│      case ESTADO_ABIERTO:                     │
│        → jsonOk({estado: "Abierto", ...})    │
│      case ESTADO_SUSPENDIDO:                 │
│        → jsonOk({estado: "Suspendido", ...}) │
│      case ESTADO_BLOQUEADO:                  │
│        → jsonOk({estado: "Bloqueado", ...})  │
│      case ESTADO_FINALIZADO:                 │
│        → AUTO-INICIO (si condiciones OK)     │
│  }                                             │
│                                                │
│  RESPUESTA:                                    │
│  {                                             │
│    "estado": "Abierto",                       │
│    "mensaje": "Sesión abierta en curso",      │
│    "tiempo_restante": 1245                    │
│  }                                             │
└────────────────────┬───────────────────────────┘
                     │
                     │ HTTP 200 + JSON
                     │
                     ▼
┌────────────────────────────────────────────────┐
│  SERVER                                        │
│                                                │
│  Recibe respuesta API:                         │
│  └─ Combina con datos de sesión                │
│  └─ Prepara respuesta para Shell               │
│                                                │
│  RESPUESTA A SHELL:                            │
│  {                                             │
│    "tipo": "respuesta_estado",                │
│    "estado": "Abierto",                       │
│    "mensaje": "Sesión abierta en curso",      │
│    "tiempo_restante": 1245,                   │
│    "nombre_equipo": "DESKTOP-XYZ",            │
│    "origen": "server",                        │
│    "destino": "shell"                         │
│  }                                             │
│                                                │
│  $from->send(json_encode($respuesta))         │
└────────────────────┬───────────────────────────┘
                     │
                     │ WebSocket Send
                     │
                     ▼
    ┌────────────────────────────────┐
    │  SHELL (Escucha WebSocket)     │
    │                                │
    │  Recibe respuesta:             │
    │  └─ estado = "Abierto"         │
    │  └─ tiempo_restante = 1245 seg │
    │                                │
    │  Actualiza UI:                 │
    │  └─ Label: "00:20:45"          │
    │  └─ Inicia countdown            │
    │                                │
    │  Próxima solicitud en 10 seg... │
    └────────────────────────────────┘
```

---

## FLUJO 3: USUARIO EJECUTA ACCIÓN (Shell → Server → API)

```
EJEMPLO: Usuario hace click en "FINALIZAR"

┌─────────────────────────────────────┐
│  SHELL (UI Form)                    │
│                                     │
│  Click en botón FINALIZAR           │
│  └─ Event Handler ejecuta:          │
│     └─ Invoke-AccionControl         │
│        -Accion "finalizar"          │
└────────────┬────────────────────────┘
             │
             │ 1️⃣ Preparar confirmación
             │
             ▼
    ┌────────────────────────────────┐
    │ Invoke-AccionControl()         │
    │                                │
    │ Payload:                       │
    │ {                              │
    │   "tipo": "confirmacion",      │
    │   "accion": "finalizar",       │
    │   "resultado": "ejecutando",   │
    │   "nombre_equipo": "....",     │
    │   "usuario": "usuario",        │
    │   "mac_eq": "AA:BB:...",       │
    │   "origen": "equipo"           │
    │ }                              │
    │                                │
    │ 2️⃣ Enqueue-WSMessage()        │
    │    (cola segura de mensajes)   │
    │                                │
    │ 3️⃣ Runspace WS envía           │
    │    por WebSocket               │
    └────────────┬────────────────────┘
                 │
                 │ WebSocket Send
                 │
                 ▼
┌──────────────────────────────────────────────────┐
│  SERVER (onMessage)                              │
│                                                  │
│  case 'confirmacion':                            │
│    if ($origen === 'equipo'):                   │
│                                                  │
│      ✅ Retransmite al Dashboard                │
│      {                                           │
│        "tipo": "proceso_comando",               │
│        "nombre_eq": "DESKTOP-XYZ",              │
│        "accion": "finalizar",                   │
│        "resultado": "ejecutando",               │
│        "origen": "server"                       │
│      }                                           │
│                                                  │
│      ✅ SI resultado == "ejecutando"            │
│         Y accion != "mensaje":                  │
│                                                  │
│         Prepara payload API:                    │
│         {                                       │
│           "tipo": "comando_api",               │
│           "accion": "finalizar",               │
│           "username": "usuario",               │
│           "mac_eq": "AA:BB:...",               │
│           "nombre_equipo": "...",              │
│           "origen": "server"    ◄─ CAMBIO      │
│         }                                       │
│                                                  │
│         curl_exec($apiUrl)                      │
└──────────────┬───────────────────────────────────┘
               │
               │ HTTP POST cURL
               │
               ▼
┌──────────────────────────────────────────────────┐
│  API (api.php)                                   │
│                                                  │
│  case 'comando_api':                             │
│    $accion = 'finalizar'                         │
│    $claveAdmin = $input['clave_admin'] ?? null  │
│                                                  │
│    if (!$claveAdmin):                            │
│      └─ jsonError("Falta clave admin")           │
│                                                  │
│    if ($claveAdmin !== $claveCorrecta):          │
│      └─ jsonError("Clave incorrecta")            │
│                                                  │
│    ✅ Si clave OK:                              │
│      $last = getUltimaSesion()                   │
│                                                  │
│      if ($last):                                 │
│        1. actualizarEstado()                     │
│           └─ Estado → FINALIZADO                │
│        2. folioCheckin()                         │
│           └─ Check-in en FOLIO                  │
│        3. folioCheckout()                        │
│           └─ Cierra préstamo                    │
│                                                  │
│      jsonOk({                                    │
│        "tipo": "confirmacion_comando",          │
│        "accion": "finalizar",                   │
│        "estado": "FINALIZADO",                  │
│        "mensaje": "Check-in completado..."      │
│      })                                          │
└──────────────┬───────────────────────────────────┘
               │
               │ HTTP 200 + JSON
               │
               ▼
┌──────────────────────────────────────────────────┐
│  SERVER                                          │
│                                                  │
│  Recibe respuesta API                            │
│  estado = "FINALIZADO"                           │
│                                                  │
│  1️⃣ Retransmite a Shell:                        │
│    {                                             │
│      "tipo": "confirmacion_comando",            │
│      "accion": "finalizar",                     │
│      "estado": "FINALIZADO",                    │
│      "origen": "server"                         │
│    }                                             │
│    → $equipos[$nombre]->send()                  │
│                                                  │
│  2️⃣ Notifica a Dashboard:                       │
│    {                                             │
│      "tipo": "proceso_comando",                 │
│      "accion": "finalizar",                     │
│      "estado": "FINALIZADO",                    │
│      "nombre_eq": "DESKTOP-XYZ",                │
│      "origen": "server"                         │
│    }                                             │
│    → notificarDashboards()                      │
└──────────┬───────────────────────────────────────┘
           │                          │
           │ WebSocket Send           │ Broadcast WS
           │                          │
           ▼                          ▼
   ┌──────────────┐         ┌──────────────────┐
   │   SHELL      │         │  DASHBOARD (Web) │
   │              │         │                  │
   │ Recibe:      │         │ Recibe:          │
   │ estado:      │         │ estado:          │
   │ FINALIZADO   │         │ FINALIZADO       │
   │              │         │                  │
   │ UI:          │         │ UI:              │
   │ Cierra form  │         │ Recarga estado   │
   │ ✅ Sesión    │         │ ✅ Sesión        │
   │    finalizada│         │    finalizada    │
   └──────────────┘         └──────────────────┘
```

---

## FLUJO 4: DASHBOARD SOLICITA RENOVACIÓN

```
EJEMPLO: Dashboard usuario hace click en "Renovar sesión"

┌─────────────────────────────────────┐
│  DASHBOARD (Web)                    │
│                                     │
│  Usuario hace click: "Solicitar     │
│  Renovación"                        │
│                                     │
│  Envía:                             │
│  {                                  │
│    "tipo": "comando",               │
│    "accion": "solicitar_renovacion" │
│    "origen": "dashboard",           │
│    "nombre_equipo": "DESKTOP-XYZ"   │
│  }                                  │
└────────────┬────────────────────────┘
             │
             │ WebSocket Send
             │
             ▼
┌────────────────────────────────────────────────┐
│  SERVER (onMessage)                            │
│                                                │
│  case 'comando':                               │
│    if ($origen === 'dashboard'):               │
│      accion = 'solicitar_renovacion'           │
│      nombre_equipo = 'DESKTOP-XYZ'             │
│                                                │
│      1️⃣ Buscar Shell conectado                │
│      if (isset($this->equipos[$nombre])):     │
│        equipos[$nombre]->send({               │
│          "tipo": "control_server",            │
│          "accion": "solicitar_renovacion",    │
│          "origen": "server",                  │
│          "destino": "shell"                   │
│        })                                      │
│                                                │
│      2️⃣ Notificar Dashboard que fue enviado   │
└────────────┬───────────────────────────────────┘
             │                        │
             │ WebSocket             │ Broadcast
             │                        │
             ▼                        ▼
   ┌─────────────────────┐    ┌─────────────────┐
   │   SHELL             │    │  DASHBOARD      │
   │                     │    │                 │
   │ Recibe solicitud    │    │ Notificación    │
   │ "solicitar_renovar" │    │ "Solicitud      │
   │                     │    │  enviada al     │
   │ Moestra UI:         │    │  Shell"         │
   │ ┌─────────────────┐ │    │                 │
   │ │ Renovación:     │ │    │ Status: PENDIENTE
   │ │ ├─ Cancelar     │ │    │ ⏳ Esperando...  │
   │ │ ├─ Confirmar    │ │    │                 │
   │ │ └─ Expirado     │ │    │ Cuando Shell    │
   │ │                 │ │    │ responda →      │
   │ │ (Timer 30 seg)  │ │    │ Refresh estado  │
   │ └─────────────────┘ │    │                 │
   │                     │    │                 │
   │ Usuario selecciona: │    │                 │
   │ "Confirmar"         │    │                 │
   │                     │    │                 │
   │ Envía:              │    │                 │
   │ {                   │    │                 │
   │   "tipo":           │    │                 │
   │   "confirmacion",   │    │                 │
   │   "accion":         │    │                 │
   │   "solicitar_       │    │                 │
   │   renovacion",      │    │                 │
   │   "resultado":      │    │                 │
   │   "ejecutando"      │    │                 │
   │ }                   │    │                 │
   └────────┬────────────┘    └─────────────────┘
            │
            │ WebSocket Send
            │
            ▼
    ┌──────────────────────────────────────────┐
    │  SERVER                                  │
    │                                          │
    │  case 'confirmacion':                    │
    │    if ($resultado === 'ejecutando'):     │
    │      → curl_exec(API)                    │
    │        {                                 │
    │          "tipo": "comando_api",          │
    │          "accion": "solicitar_renovacion"│
    │          "username": "usuario",          │
    │          "mac_eq": "AA:BB:...",          │
    │          "origen": "server"              │
    │        }                                 │
    └──────────┬───────────────────────────────┘
               │
               ▼
    ┌──────────────────────────────────────────┐
    │  API                                     │
    │                                          │
    │  case 'comando_api':                     │
    │    accion = 'solicitar_renovacion'       │
    │                                          │
    │    → Solicita renovación                 │
    │    → En FOLIO de usuario                 │
    │    → Retorna confirmación                │
    │      {                                   │
    │        "estado": "Renovado",             │
    │        "mensaje": "Solicitada..."        │
    │      }                                   │
    └───────────────────────────────────────────┘
```

---

## FLUJO 5: DASHBOARD APRUEBA RENOVACIÓN

```
┌────────────────────────────────────────────┐
│  DASHBOARD (Web)                           │
│                                            │
│  Mostraba "Solicitud de renovación        │
│  de DESKTOP-XYZ para usuario"              │
│                                            │
│  Usuario hace click:                       │
│  ┌──────────────────┐                      │
│  │ ✅ ACEPTAR       │  ← Click aquí       │
│  │ ❌ RECHAZAR      │                      │
│  └──────────────────┘                      │
│                                            │
│  Envía:                                    │
│  {                                         │
│    "tipo": "respuesta_solicitud",          │
│    "action": "aceptar_renovacion",         │
│    "session": 42,                          │
│    "origen": "dashboard"                   │
│  }                                         │
└────────────┬─────────────────────────────┘
             │
             │ WebSocket Send
             │
             ▼
┌────────────────────────────────────────────────────┐
│  SERVER (onMessage)                                │
│                                                    │
│  case 'respuesta_solicitud':                       │
│                                                    │
│    1️⃣ Extrae acción                               │
│    action = 'aceptar_renovacion'                   │
│    sessionId = 42                                  │
│                                                    │
│    2️⃣ Busca sesión en BD                          │
│    SELECT * FROM sesiones WHERE id = 42           │
│    → Obtiene: username, nombre_pc, mac_eq         │
│                                                    │
│    3️⃣ Determina acción API                        │
│    if (action === 'aceptar_renovacion'):          │
│      accionAPI = 'renovar'                         │
│                                                    │
│    4️⃣ Llama API                                   │
│    curl_exec({                                    │
│      "tipo": "comando_api",                       │
│      "accion": "renovar",                         │
│      "username": "usuario",                       │
│      "mac_eq": "AA:BB:...",                       │
│      "origen": "server"                           │
│    })                                              │
│                                                    │
│    5️⃣ Recibe respuesta API                        │
│    estado = "RENOVADO_COMANDO"                    │
│                                                    │
│    6️⃣ Retransmite a Shell                         │
│    if (isset($this->equipos['DESKTOP-XYZ'])):    │
│      equipos['DESKTOP-XYZ']->send({              │
│        "tipo": "confirmacion_solicitud",         │
│        "accion": "renovar",                      │
│        "estado": "RENOVADO_COMANDO",             │
│        "origen": "server"                        │
│      })                                           │
│                                                    │
│    7️⃣ Notifica Dashboard del resultado            │
│    notificarDashboards({                          │
│      "tipo": "resultado_solicitud",               │
│      "accion": "renovar",                         │
│      "estado": "RENOVADO_COMANDO",                │
│      "sessionId": 42,                             │
│      "usuario": "usuario"                         │
│    })                                              │
└───────────┬──────────────────────────────────────┘
            │                           │
            │ WebSocket                 │ Broadcast
            │                           │
            ▼                           ▼
   ┌───────────────┐            ┌──────────────┐
   │   SHELL       │            │  DASHBOARD   │
   │               │            │              │
   │ Recibe:       │            │ Recibe:      │
   │ "renovar"     │            │ "renovado"   │
   │ "RENOVADO"    │            │              │
   │               │            │ Actualiza UI │
   │ Actualiza UI: │            │ ✅ Sesión    │
   │ ✅ Renovada   │            │    renovada  │
   │               │            │              │
   │ Nuevo timeout:│            │ Nueva sesión │
   │ 30 segundos   │            │ iniciada     │
   │ Countdown...  │            │              │
   └───────────────┘            └──────────────┘
```

---

## RESUMEN FLUJOS PRINCIPALES

| # | Iniciador | Path | Protocolo | Resultado |
|---|-----------|------|-----------|-----------|
| 1 | Shell | Shell → Server | WS | Confirmación registro |
| 2 | Shell/Timer | Shell → Server → API | WS + cURL | Estado actualizado |
| 3 | Shell/User | Shell → Server → API | WS + cURL | Acción ejecutada |
| 4 | Dashboard | Dashboard → Server → Shell | WS | Solicitud mostrada |
| 5 | Dashboard | Dashboard → Server → API → Shell | WS + cURL | Renovación ejecutada |
| 6 | Server/Timer | Server → API | cURL | Desbloqueos automáticos |
| 7 | Server | Server → Dashboard | WS | Notificaciones en tiempo real |

---

**Documentación generada:** 2025-12-04

