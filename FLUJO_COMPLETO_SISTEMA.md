# 📋 FLUJO COMPLETO DEL SISTEMA DE AUTOPRÉSTAMOS CON HIBERNACIÓN

**Versión:** 2.3  
**Última actualización:** Noviembre 2025  
**Archivo principal:** `prueba_equipos/win-server copy 2.txt`

---

## 🎯 Visión General

El sistema de autopréstamos es una **arquitectura de tiempo real** que:
1. **Cliente PowerShell** (equipo) → detecta inactividad y comunica estados
2. **API REST** (PHP) → maneja lógica de sesiones y conecta con FOLIO
3. **Servidor WebSocket** (Ratchet) → orquesta comunicación bidireccional
4. **Base de Datos** → almacena sesiones, equipos y estados
5. **Dashboard Web** → visualiza estado en tiempo real
6. **Sistema de Hibernación** → cierra sesiones inactivas automáticamente

---

## 📊 ARQUITECTURA DE COMPONENTES

```
┌─────────────────────────────────────────────────────────────────┐
│                    CLIENTE POWERSHELL (Win-Server)              │
│  ├─ UI Thread (WinForms)     → Interfaz de usuario             │
│  ├─ WS Runspace (WebSocket)  → Comunicación con servidor       │
│  └─ Monitor Inactividad      → Detección de idle (GetLastInputInfo)
│                                                                   │
│  ESTADOS POSIBLES:                                              │
│  🟢 Abierto       - Sesión activa, contador regresivo          │
│  🔒 Bloqueado     - Equipo bloqueado por clave o acción admin   │
│  ⏸️  Suspendido    - En espera de confirmación admin            │
│  ♻️  Renovado      - Sesión extendida automáticamente           │
│  😴 Hibernando    - Inactividad detectada, ventana modal       │
│  ✅ Finalizado    - Sesión cerrada, check-in en FOLIO          │
│  🚫 Restringido   - Usuario bloqueado en FOLIO (acceso denegado)
│                                                                   │
│  PUNTOS CRÍTICOS:                                               │
│  • OutgoingQueue → Cola segura para envíos (no bloquea UI)     │
│  • Runspace drena cola y envía mensajes desde WS context       │
│  • Sistema de reintentos con backoff exponencial               │
└─────────────────────────────────────────────────────────────────┘
                              ↓↑ JSON WebSocket
                              ↓↑ (puerto 8081)
┌─────────────────────────────────────────────────────────────────┐
│            SERVIDOR WEBSOCKET (Ratchet / PHP)                  │
│  ├─ Registro de clientes (equipos + dashboards)                │
│  ├─ Enrutamiento de comandos                                   │
│  ├─ Procesamiento de hibernación                               │
│  └─ Notificación de cambios de estado                          │
│                                                                   │
│  TIPOS DE MENSAJES:                                             │
│  • "registro"       - Cliente se identifica                     │
│  • "comando"        - Dashboard → Equipo (bloquear, suspender, etc)
│  • "confirmacion"   - Equipo confirma acción ejecutada         │
│  • "hibernado"      - Monitoreo de hibernación                 │
│  • "info_respuesta" - Equipo responde solicitud de info        │
│  • "log"            - Equipos envían logs del runspace         │
│  • "estado"         - Broadcast con estado global del sistema  │
└─────────────────────────────────────────────────────────────────┘
                              ↓↑ HTTP REST
                              ↓↑ (puerto 80)
┌─────────────────────────────────────────────────────────────────┐
│              API REST (api.php / PHP)                           │
│  ├─ Manejo de control: confirmar_inicio, finalizar, renovar   │
│  ├─ Integración con FOLIO (préstamos/devoluciones)            │
│  ├─ Cambios de estado en BD                                    │
│  └─ Validación de sesiones                                     │
│                                                                   │
│  ACCIONES SOPORTADAS:                                           │
│  • confirmar_inicio   - Abre sesión en BD y FOLIO              │
│  • renovar            - Extiende tiempo de sesión              │
│  • finalizar          - Cierra sesión, check-in en FOLIO       │
│  • bloquear           - Marca equipo como bloqueado            │
│  • suspender          - Suspende sesión                        │
└─────────────────────────────────────────────────────────────────┘
                              ↓↑ SQL
                              ↓↑ (puerto 3306)
┌─────────────────────────────────────────────────────────────────┐
│              BASE DE DATOS (MySQL)                              │
│  ├─ sesiones          - Registro de aperturas/cierres          │
│  ├─ equipos           - PC registrados del sistema              │
│  ├─ estados           - Estados posibles (Abierto, Bloqueado, etc)
│  ├─ usuarios_folio    - Datos de usuarios (sincronizado FOLIO) │
│  └─ logs_acciones     - Auditoría de acciones                  │
│                                                                   │
│  TABLA ESTADOS (CRÍTICA):                                       │
│  ├─ id_estado = 1 → Finalizado                                 │
│  ├─ id_estado = 2 → Abierto                                    │
│  ├─ id_estado = 3 → Bloqueado                                  │
│  ├─ id_estado = 4 → Suspendido                                 │
│  ├─ id_estado = 5 → Hibernando (CREADO POR SERVIDOR SI NO EXISTE)
│  └─ id_estado = 6 → Restringido                                │
└─────────────────────────────────────────────────────────────────┘
                              ↓↑ HTTP REST
                              ↓↑ (puerto 80)
┌─────────────────────────────────────────────────────────────────┐
│             DASHBOARD WEB (Bootstrap/JS)                        │
│  ├─ Visualización de sesiones en tiempo real                   │
│  ├─ Contador de estados (Abiertos, Hibernando, Finalizados)   │
│  ├─ Control remoto (bloquear, suspender, enviar mensaje)      │
│  └─ WebSocket escucha cambios de estado                        │
│                                                                   │
│  ESTADÍSTICAS EN VIVO:                                          │
│  • Abiertos        - Sesiones activas (estado = 2)             │
│  • Hibernando      - En hibernación (estado = 5)               │
│  • Bloqueados      - Equipos bloqueados (estado = 3)           │
│  • Suspendidos     - Sesiones suspendidas (estado = 4)         │
│  • Finalizados     - Sesiones cerradas (estado = 1)            │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔄 FLUJOS DE OPERACIÓN

### 1️⃣ INICIALIZACIÓN DE SESIÓN

```
[CLIENTE] Ejecuta win-server copy 2.txt
   ↓
[Initialize-System]
   ├─ Detecta interfaz de red activa → MAC address
   ├─ Inicia WebSocket Runspace (independiente)
   └─ Espera 3 segundos para que WS se conecte
   ↓
[Start-WebSocketProcess]
   ├─ Crea runspace dedicado para ClientWebSocket
   ├─ Conecta a ws://localhost:8081 (servidor Ratchet)
   └─ Envía mensaje de registro (tipo: "registro", origen: "equipo")
   ↓
[SERVER - Ratchet]
   ├─ Recibe "registro" → tipo_cliente = "equipo"
   ├─ Almacena referencia en array $equipos
   └─ Envía confirmación
   ↓
[Start-SessionLoop]
   ├─ Abre interfaz gráfica (New-SessionForm)
   ├─ Llama API: confirmar_inicio=true
   └─ Inicia monitor de comandos WebSocket
   ↓
[API - php]
   ├─ Verifica usuario en BD y FOLIO
   ├─ Crea sesión en tabla sesiones (id_estado_fk = 2 → Abierto)
   ├─ Retorna: estado="Abierto", tiempo_restante=90
   └─ UI muestra contador regresivo
```

---

### 2️⃣ MONITOREO DE INACTIVIDAD Y HIBERNACIÓN

```
[CLIENTE - Main Loop]
   ↓
[Get-SystemIdleTime] → Lee segundos de inactividad (Win32 API)
   ↓
¿Idle >= INACTIVITY_TIMEOUT (15 seg)?
   │
   ├─ NO  → Continue loop
   │
   └─ SÍ  → [HIBERNACIÓN INICIADA]
            ├─ Set IsHibernating = $true
            ├─ Set HibernationStartTime = Get-Date
            ├─ Enqueue-WSMessage: {"tipo": "hibernado", "accion": "hibernar", ...}
            ↓
            [Runspace drena OutgoingQueue]
            ├─ Dequeue mensaje de hibernado
            ├─ Send-WSMessage: envía a servidor WebSocket
            ↓
            [SERVER - Ratchet]
            ├─ Recibe: tipo="hibernado", accion="hibernar"
            ├─ Query: SELECT sesion WHERE nombre_equipo=? AND id_estado_fk IN (2,3,4)
            ├─ UPDATE sesiones SET id_estado_fk = 5 (Hibernando)
            ├─ notificarDashboards: cambio de estado
            └─ enviarEstadoATodos()
            ↓
            [BD - MySQL]
            ├─ sesiones.id_estado_fk = 5 (Hibernando)
            └─ Dashboard actualiza estadísticas
            ↓
            [UI - Cliente]
            ├─ Invoke-EstadoHibernando
            ├─ Muestra ventana modal bloqueante (Maximized, Topmost)
            ├─ Contador: "Finalizando en 60 segundos..."
            └─ Timer inicia: cada 1 segundo verifica estado

¿Se detecto actividad (idle < 3 seg)?
   │
   ├─ SÍ  → [HIBERNACIÓN CANCELADA]
           ├─ IsHibernating = $false
           ├─ HibernationStartTime = $null
           ├─ LastActivity = Get-Date
           ├─ Enqueue-WSMessage: {"tipo": "hibernado", "accion": "cancelar", ...}
           ├─ Cierra ventana modal
           └─ Reanuda sesión normal (Invoke-EstadoRenovado)
   │
   └─ NO + Tiempo >= HIBERNATION_MAX_DURATION (20 seg)?
            ├─ SÍ  → [HIBERNACIÓN EXPIRADA]
                    ├─ Invoke-FinalizarSesionRemota
                    ├─ Enqueue-WSMessage: {"tipo": "hibernado", "accion": "finalizar_por_hibernacion", ...}
                    ↓
                    [SERVER - Ratchet]
                    ├─ Recibe accion="finalizar_por_hibernacion"
                    ├─ UPDATE sesiones SET id_estado_fk = 1, fecha_final_real = now()
                    ├─ Llama API: accion="finalizar", razon="inactividad_prolongada"
                    ├─ notificarDashboards: cambio_estado → Finalizado
                    └─ enviarEstadoATodos()
                    ↓
                    [API - php]
                    ├─ Procesa check-in en FOLIO
                    ├─ Actualiza BD: sesión finalizada
                    └─ Retorna: estado="FINALIZADO"
                    ↓
                    [CLIENTE] Cierra formulario de sesión
            │
            └─ NO → Continue (contador sigue)
```

---

### 3️⃣ COMANDO DESDE DASHBOARD

```
[DASHBOARD]
   ├─ Usuario hace clic: "Bloquear equipo: PC-001"
   ├─ Envía WebSocket: {"tipo": "comando", "accion": "bloquear", "nombre_eq": "PC-001", ...}
   ↓
[SERVER - Ratchet]
   ├─ Recibe comando, busca equipo en $equipos[]
   ├─ Envía: {"tipo": "control_server", "accion": "bloquear", "manejo": "comandos", ...}
   ↓
[CLIENTE - Runspace Listener]
   ├─ Recibe comando
   ├─ Enqueue en CommandQueue
   ↓
[Start-CommandQueueMonitor] (timer en UI thread)
   ├─ Dequeue comando
   ├─ Invoke-AccionControl -Accion "bloquear"
   ├─ Envía API: accion="bloquear"
   ├─ Enqueue-WSMessage: {"tipo": "confirmacion", "resultado": "ejecutado", ...}
   ↓
[Runspace drena OutgoingQueue]
   ├─ Send-WSMessage: confirmación al servidor
   ↓
[SERVER]
   ├─ Recibe confirmación
   ├─ Llama API: accion="bloquear"
   ├─ notificarDashboards: cambio_estado → Bloqueado
   └─ enviarEstadoATodos()
```

---

### 4️⃣ RENOVACIÓN DE SESIÓN

```
[CLIENTE - Main Loop (estado Abierto)]
   ├─ Contador llega a 0
   ├─ Invoca Invoke-ApiCall → confirmar_inicio=true (renuevar)
   ↓
[API - php]
   ├─ Query: SELECT sesión WHERE usuario=?
   ├─ Valida en FOLIO si hay más tiempo
   ├─ Si SÍ: UPDATE sesiones SET fecha_final_programada = now() + 90min
   ├─ Retorna: estado="Renovado"
   └─ Envía: tiempo_restante=90
   ↓
[CLIENTE]
   ├─ switch (estado) → "Renovado"
   ├─ Invoke-EstadoRenovado
   ├─ MessageBox: "Tu sesión ha sido renovada"
   ├─ Vuelve al loop
   └─ Restablece contador a 90 segundos
```

---

### 5️⃣ FINALIZACIÓN NORMAL

```
[CLIENTE - Main Loop (estado Abierto)]
   ├─ Usuario hace clic: Logout / Finalizar
   ├─ O: Contador llega a 0 y no hay renovación
   ↓
[API - Finalizar]
   ├─ Valida sesión
   ├─ Envía solicitud de check-in a FOLIO (devolución)
   ├─ Si FOLIO retorna "Closed": UPDATE sesiones SET id_estado_fk=1, fecha_final_real=now()
   ├─ Retorna: estado="Finalizado"
   └─ Enqueue-WSMessage: {"tipo": "confirmacion", "accion": "finalizar", "resultado": "ejecutado"}
   ↓
[SERVER]
   ├─ Recibe confirmación finalizar
   ├─ notificarDashboards
   └─ enviarEstadoATodos()
   ↓
[CLIENTE]
   ├─ switch (estado) → "Finalizado"
   ├─ Invoke-EstadoFinalizado
   ├─ MessageBox: "Sesión finalizada correctamente"
   ├─ Cierra formulario
   ├─ Clear-Resources: libera WebSocket, runspace, etc.
   └─ Exit
```

---

## ⚙️ CONFIGURACIÓN CRÍTICA

### En `win-server copy 2.txt`

```powershell
# Tiempos de inactividad
INACTIVITY_TIMEOUT       = 15    # segundos hasta hibernación
HIBERNATION_MAX_DURATION = 20    # segundos máximos en hibernación

# WebSocket
ServidorWS = "ws://localhost:8081"

# API REST
ApiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php"
```

### En `api.php`

```php
// Tiempos de sesión
TIEMPO_SESION_ACTIVA = 90 minutos
// Tiempo máximo en hibernación antes de finalizar
// Se controla en cliente (HIBERNATION_MAX_DURATION)
```

### En Base de Datos

```sql
-- TABLA ESTADOS (CRÍTICA)
INSERT INTO estados VALUES 
(1, 'Finalizado', 'Sesión cerrada', '#999999'),
(2, 'Abierto', 'Sesión activa', '#00aa00'),
(3, 'Bloqueado', 'Equipo bloqueado', '#ff0000'),
(4, 'Suspendido', 'Sesión suspendida', '#ffbb33'),
(5, 'Hibernando', 'Sesión hibernando', '#ffbb33'),  -- NUEVO
(6, 'Restringido', 'Acceso denegado', '#ff0000');

-- TABLA SESIONES
CREATE TABLE sesiones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_equipo_fk INT,
    username VARCHAR(100),
    id_estado_fk INT DEFAULT 2,  -- Por defecto Abierto
    fecha_inicio DATETIME,
    fecha_final_programada DATETIME,
    fecha_final_real DATETIME,
    -- ...
);
```

---

## 🚨 PROBLEMAS CONOCIDOS Y SOLUCIONES

### ❌ Problema 1: SendAsync(...).Wait() Bloquea UI

**Síntoma:** UI se congela 3 segundos en cada envío.

**Causa:** Envío de WebSocket desde main thread.

**Solución:** ✅ IMPLEMENTADA
- Usar `Enqueue-WSMessage` desde UI thread
- Runspace drena OutgoingQueue y llama SendAsync desde contexto WS
- No hay bloqueo en UI

---

### ❌ Problema 2: Mensajes Inconsistentes ("hibernation" vs "hibernado")

**Síntoma:** Servidor espera "hibernado" pero cliente envía "hibernation"

**Causa:** Nombre de mensaje incoherente

**Solución:** ✅ IMPLEMENTADA
- Normalizar a `tipo = "hibernado"` en TODOS los mensajes
- Test script actualizado
- Documentación actualizada

---

### ❌ Problema 3: Cross-Runspace WebSocket Access

**Síntoma:** Access violation o deadlock al acceder ClientWebSocket desde múltiples threads

**Causa:** ClientWebSocket NO es thread-safe

**Solución:** ✅ IMPLEMENTADA
- Mantener referencia en runspace (no compartir)
- Compartir OutgoingQueue (es thread-safe)
- Runspace es el ÚNICO que llama SendAsync/ReceiveAsync

---

### ❌ Problema 4: Hibernación No Se Dispara

**Síntoma:** Equipo permanece en "Abierto" aunque está inactivo

**Causa:** 
- INACTIVITY_TIMEOUT muy alto
- Get-SystemIdleTime retorna 0 (usuario activo)
- LastActivity se actualiza constantemente

**Solución:**
- Bajar INACTIVITY_TIMEOUT para pruebas (ej: 15 seg)
- Usar prueba manual: no tocar mouse/teclado por INACTIVITY_TIMEOUT
- Revisar Get-SystemIdleTime en cliente (debe estar > timeout)

---

### ❌ Problema 5: Servidor No Crea Estado "Hibernando"

**Síntoma:** 500 error en servidor, no actualiza estado

**Causa:** Estado id=5 no existe en tabla

**Solución:** ✅ IMPLEMENTADA
- Servidor auto-crea estado si no existe:
  ```php
  $chkEstado = $this->conn->query("SELECT id_estado FROM estados WHERE id_estado = 5");
  if ($chkEstado && $chkEstado->num_rows === 0) {
      $this->conn->query("INSERT IGNORE INTO estados (id_estado, nombre_estado, ...) VALUES (5, 'Hibernando', ...)");
  }
  ```

---

## 📈 MONITOREO Y DEBUGGING

### 1. Ver logs del cliente PowerShell

```powershell
# Los Write-Log aparecen en consola con timestamp y color
# Ej: [14:30:45] ℹ️ [Info] Estado actual: Abierto
```

### 2. Ver logs del servidor Ratchet

```bash
cd C:\xampp\htdocs\autoprestamos\servers
php server.php
# Aparecen líneas como:
# 🟢 Cliente conectado: (123)
# 📡 API respondió: [FINALIZADO] Check-in exitoso
```

### 3. Ver cambios en BD

```sql
SELECT * FROM sesiones ORDER BY id DESC LIMIT 5;
-- Ver último estado de todas las sesiones

SELECT id_estado, COUNT(*) as cantidad FROM sesiones 
GROUP BY id_estado;
-- Contador por estado
```

### 4. Ver WebSocket en cliente (PowerShell)

```powershell
# Buscar en console output líneas con emoji 🌐 (runspace log)
# Ej: [14:32:10] 🌐 [WS-PROCESS] 📩 Recibido: {...}
```

---

## ✅ CHECKLIST DE PRUEBA E2E

- [ ] **1. Iniciar servidor Ratchet**: `php server.php`
- [ ] **2. Iniciar cliente PowerShell**: Ejecutar `win-server copy 2.txt`
- [ ] **3. Verificar registro**: Console muestra "Cliente registrado"
- [ ] **4. Abrir Dashboard**: Ver equipo en lista "Abiertos"
- [ ] **5. Inactividad 15+ seg**: Cliente entra en hibernación (ventana modal)
- [ ] **6. Esperar 20+ seg en hibernación**: Sistema finaliza automáticamente
- [ ] **7. Verificar BD**: sesión tiene id_estado_fk = 1 (Finalizado)
- [ ] **8. Verificar Dashboard**: Contadores se actualizan correctamente
- [ ] **9. Hacer clic en "Detectar actividad"**: Hibernación se cancela (Renovado)
- [ ] **10. Comando "Bloquear" desde Dashboard**: Cliente recibe y ejecuta

---

## 📚 REFERENCIAS

- **Cliente Principal:** `c:\xampp\htdocs\autoprestamos\prueba_equipos\win-server copy 2.txt`
- **Servidor WebSocket:** `c:\xampp\htdocs\autoprestamos\servers\server.php`
- **API REST:** `c:\xampp\htdocs\autoprestamos\prueba_equipos\api.php`
- **Dashboard:** `c:\xampp\htdocs\autoprestamos\dashboard-unisimon\dashboard.php`
- **BD Config:** `c:\xampp\htdocs\autoprestamos\config\db.php`

---

**Fin de documentación**
