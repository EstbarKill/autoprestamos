# 📝 RESUMEN DEL ARCHIVO PRINCIPAL - win-server.ps1

**Versión:** 2.3  
**Tamaño:** ~970 líneas  
**Función:** Cliente PowerShell con detección de inactividad y hibernación

---

## 📑 ÍNDICE DE CONTENIDOS

| Línea | Sección | Descripción |
|-------|---------|-------------|
| 1-35 | **Configuración Global** | Parámetros, URI, credenciales |
| 36-44 | **SharedState** | Hashtable sincronizada (UI ↔ Runspace) |
| 45-65 | **IdleTime (Win32)** | Detección global de inactividad |
| 66-105 | **Funciones Utilidad** | Write-Log, Enqueue-WSMessage, Format-TimeSpan, etc. |
| 130-330 | **WebSocket Runspace** | Proceso independiente para comunicación WS |
| 331-410 | **Procesador de Acciones** | Invoke-AccionControl (bloquear, suspender, finalizar, etc.) |
| 415-510 | **Monitor de Cola** | Timer que procesa comandos desde servidor |
| 520-590 | **API REST** | Invoke-ApiCall para comunicación con api.php |
| 600-750 | **Interfaz Gráfica** | New-SessionForm (WinForms) y controles |
| 760-820 | **Manejadores de Estado** | Invoke-Estado* (Abierto, Bloqueado, Suspendido, Renovado, Error, Finalizado) |
| 830-880 | **Hibernación** | Invoke-EstadoHibernando (ventana modal + contador) |
| 890-930 | **Finalización Remota** | Invoke-FinalizarSesionRemota |
| 940-970 | **Loop Principal** | Start-SessionLoop (máquina de estados) |

---

## 🔧 FUNCIONES PRINCIPALES

### A. Inicialización

```powershell
Initialize-System
├─ Get-ActiveNetworkInterface → Detecta MAC
├─ Start-WebSocketProcess    → Inicia runspace WS
└─ [Espera 3 seg para conexión]

Clear-Resources
├─ Stop-WebSocketProcess
├─ Dispose PowerShell
└─ Dispose Runspace
```

### B. WebSocket (Runspace Independiente)

```powershell
Start-WebSocketProcess
└─ Connect-WSClient (reintentos)
   └─ Start-WSListener (bucle continuo)
      ├─ Drena OutgoingQueue (envía mensajes encolados)
      ├─ ReceiveAsync (escucha servidor)
      └─ Enqueue en CommandQueue (procesa comandos)
```

### C. Procesamiento de Comandos

```powershell
Start-CommandQueueMonitor (Timer en UI thread)
└─ Dequeue comando
   └─ Invoke-AccionControl -Accion $accion
      ├─ "bloquear"  → Llama API + Enqueue confirmación
      ├─ "suspender" → MessageBox + Enqueue confirmación
      ├─ "finalizar" → API + Cierra sesión
      ├─ "renovar"   → API + Reanuda
      ├─ "mensaje"   → MessageBox
      └─ "ver_info"  → Recopila y enqueue respuesta
```

### D. Estados de Sesión (UI)

```powershell
Invoke-EstadoAbierto
├─ Contador regresivo 90 → 0 segundos
└─ Llama API (renovar)

Invoke-EstadoBloqueado
├─ Contador con color rojo
├─ Verifica check-in en FOLIO
└─ Si cerrado → Restablece

Invoke-EstadoSuspendido
├─ MessageBox "Ingrese clave admin"
└─ Si OK → Envía clave + API

Invoke-EstadoRenovado
├─ MessageBox "Sesión renovada"
└─ Retorna nueva API call

Invoke-EstadoHibernando
├─ Ventana modal WPF (Maximized, Topmost)
├─ Contador: "Finalizando en X segundos"
├─ Detect actividad (idle < 3) → Cancela
├─ Si timeout → Finaliza remota
└─ Timer 1/seg

Invoke-EstadoFinalizado
└─ MessageBox "Sesión finalizada" → Close

Invoke-EstadoError
└─ MessageBox error → Close

Invoke-EstadoRestringido
└─ Mostrar bloques de FOLIO → Close (6 seg)
```

### E. Hibernación (Núcleo)

```powershell
Start-SessionLoop
│
├─ [Loop principal]
│  ├─ Get-SystemIdleTime → idle_seconds
│  │
│  ├─ IF idle >= INACTIVITY_TIMEOUT (15s)
│  │  ├─ Set IsHibernating = true
│  │  ├─ Enqueue: {"tipo":"hibernado", "accion":"hibernar"}
│  │  │  └─ Runspace → SendAsync → Servidor → BD (estado=5)
│  │  └─ Invoke-EstadoHibernando (ventana modal)
│  │      └─ Timer (cada 1 seg)
│  │         ├─ IF idle < 3 → Cancelar hibernación
│  │         │  ├─ Enqueue: {"tipo":"hibernado", "accion":"cancelar"}
│  │         │  └─ Invoke-EstadoRenovado (renovada)
│  │         │
│  │         └─ ELSEIF tiempo >= HIBERNATION_MAX_DURATION (20s)
│  │            ├─ Invoke-FinalizarSesionRemota
│  │            ├─ Enqueue: {"tipo":"hibernado", "accion":"finalizar_por_hibernacion"}
│  │            └─ Servidor → BD (estado=1) → API finaliza
│  │
│  └─ [Procesamiento normal de estado]
│     └─ switch estado:
│        ├─ "Abierto"    → Invoke-EstadoAbierto
│        ├─ "Bloqueado"  → Invoke-EstadoBloqueado
│        ├─ "Suspendido" → Invoke-EstadoSuspendido
│        ├─ "Renovado"   → Invoke-EstadoRenovado
│        ├─ "Hibernando" → Invoke-EstadoHibernando
│        └─ "Finalizado" → Invoke-EstadoFinalizado
│
└─ [Fin sesión: Clean-up]
   ├─ Stop CommandQueueMonitor
   ├─ Close formulario
   └─ Clear-Resources
```

---

## 📊 FLUJO DE DATOS

```
CLIENTE (PowerShell)
│
├─ UI Thread
│  ├─ Write-Log → Console
│  ├─ Get-SystemIdleTime → Windows
│  ├─ Invoke-ApiCall → HTTP → api.php
│  ├─ Enqueue-WSMessage → SharedState.OutgoingQueue
│  └─ WinForms UI ← Mouse/Keyboard
│
└─ WS Runspace (Independiente)
   ├─ Connect-WSClient → ws://localhost:8081
   ├─ Start-WSListener
   │  ├─ Drena OutgoingQueue
   │  │  └─ Send-WSMessage → WebSocket.SendAsync
   │  │
   │  ├─ ReceiveAsync → Recibe de servidor
   │  │  └─ Enqueue → CommandQueue
   │  │
   │  └─ [Loop continuo]
   │
   └─ Referencia WSClientReference en SharedState
```

---

## 🔐 VARIABLES CRÍTICAS (SharedState)

```powershell
$Global:SharedState = @{
    WebSocketConnected      # ¿WS conectado?
    LastMessage             # Último mensaje recibido
    CommandQueue            # Comandos del servidor (thread-safe)
    LogQueue                # Logs del runspace
    MacAddress              # MAC del equipo (detectada)
    SessionActive           # ¿Sesión activa?
    WSClientReference       # Referencia ClientWebSocket
    LastActivity            # Timestamp última actividad
    IsHibernating           # ¿En hibernación?
    HibernationStartTime    # Cuándo inició hibernación
    OutgoingQueue           # Mensajes salientes (thread-safe) ⭐
    INACTIVITY_TIMEOUT      # 15 segundos
    HIBERNATION_MAX_DURATION # 20 segundos
}
```

---

## 🌐 TIPOS DE MENSAJES JSON

### Desde Cliente → Servidor

```json
{
  "tipo": "registro",
  "accion": "getRegistro",
  "origen": "equipo",
  "nombre_equipo": "PC-001"
}
```

```json
{
  "tipo": "hibernado",
  "accion": "hibernar | cancelar | finalizar_por_hibernacion",
  "nombre_eq": "PC-001",
  "timestamp_hibernacion": "2025-11-13 14:30:45"
}
```

```json
{
  "tipo": "confirmacion",
  "origen": "equipo",
  "accion": "finalizar | bloquear | renovar",
  "resultado": "ejecutado | error",
  "mensaje": "Descripción"
}
```

```json
{
  "tipo": "info_respuesta",
  "id": "PC-001",
  "datos": {
    "usuario": "juan.diaz",
    "equipo": "PC-001",
    "ip": "192.168.1.10",
    "mac": "00:11:22:33:44:55",
    "so": "Windows 10",
    "memoria": 16.0,
    "procesador": "Intel Core i7"
  }
}
```

### Desde Servidor → Cliente

```json
{
  "tipo": "control_server",
  "manejo": "comandos | mensaje | info",
  "accion": "bloquear | suspender | finalizar | renovar | mensaje | ver_info",
  "origen": "server",
  "timestamp": "2025-11-13 14:30:45"
}
```

```json
{
  "tipo": "ping",
  "origen": "server"
}
```

---

## ⚡ PUNTOS CLAVE DE PERFORMANCE

### ✅ Optimizaciones Implementadas

1. **Dual Process**: WebSocket en runspace separado → No bloquea UI
2. **OutgoingQueue**: Mensajes salientes encolados → Thread-safe
3. **CommandQueue**: Comandos procesados desde UI timer → No espera bloqueante
4. **Start-Sleep -Milliseconds 200**: Loop principal no consume CPU
5. **ReceiveAsync con Result**: No bloquea indefinidamente (timeout 100ms sleep)

### ⚠️ Cuellos de Botella Identificados

1. ❌ `SendAsync(...).Wait(3000)` en Send-WSMessage (3 seg bloqueante)
   - ✅ Solución: Se ejecuta en runspace (no afecta UI)
   
2. ❌ `Invoke-ApiCall` es bloqueante (puede tardar 60 seg)
   - ⚠️ Actual: Se ejecuta en UI thread
   - 💡 Mejora futura: Mover a task async

3. ❌ `Get-CimInstance` lenta (primera llamada ~500ms)
   - ✅ Se cachea en `ver_info` (solo se llama bajo demanda)

---

## 🔍 DEBUGGING

### Habilitar Logging Extendido

```powershell
# Dentro del script, cambiar:
$Tipo = 'Info'

# Por:
$Tipo = 'Debug'  # Muestra más detalles
```

### Ver Runspace Logs

```powershell
# En consola del cliente, los logs del runspace aparecen con 🌐
# Ej: [14:30:45] 🌐 [WS-PROCESS] 📩 Recibido: {...}
```

### Breakpoints

```powershell
# En PowerShell ISE:
Set-PSBreakpoint -Script .\win-server.ps1 -Line 500  # Pausa en línea 500
# O F9 en ISE
```

---

## 📦 DEPENDENCIAS INTERNAS

```
win-server.ps1
├─ System.Windows.Forms      (UI)
├─ System.Drawing            (Colores, Images)
├─ System.Net.WebSockets     (ClientWebSocket)
├─ System.Threading          (CancellationToken)
├─ PresentationFramework      (WPF para hibernación modal)
├─ Win32 API (user32.dll)     (GetLastInputInfo)
└─ PowerShell Threading       (Runspaces)
```

---

## 🔗 REFERENCIAS CRUZADAS

- **api.php** ← Procesa acciones del cliente
- **server.php** ← Recibe y enruta mensajes
- **db.php** ← Acceso a BD (sesiones, estados)
- **dashboard.php** ← Recibe broadcast de cambios
- **FLUJO_COMPLETO_SISTEMA.md** ← Documentación de arquitectura
- **HIBERNACION_IMPLEMENTATION.md** ← Detalles de hibernación

---

**Archivo Consolidado:** `win-server.ps1`  
**Versión:** 2.3  
**Última actualización:** Noviembre 2025
