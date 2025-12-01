# 🚀 GUÍA DE USO - CLIENTE POWERSHELL

## ARCHIVO PRINCIPAL

**`win-server.ps1`** es el archivo principal consolidado del cliente PowerShell.

Los archivos `win-server.txt`, `win-server copy.txt` y `win-server copy 2.txt` son versiones antiguas y **NO DEBEN USARSE**. Serán removidos en futuras versiones.

---

## INICIO RÁPIDO

### 1️⃣ Verificar Requisitos Previos

- ✅ Windows 10/11 (64 bits recomendado)
- ✅ PowerShell 5.1+ (de serie en Windows 10+)
- ✅ PHP CLI instalado en `C:\xampp\php` (XAMPP)
- ✅ Servidor Ratchet corriendo: `php c:\xampp\htdocs\autoprestamos\servers\server.php`
- ✅ API REST disponible: `http://localhost/autoprestamos/prueba_equipos/api.php`

### 2️⃣ Ejecutar el Cliente

Abre **PowerShell como Administrador** y ejecuta:

```powershell
cd C:\xampp\htdocs\autoprestamos\prueba_equipos
Set-ExecutionPolicy -ExecutionPolicy Bypass -Scope Process
.\win-server.ps1
```

**O simplemente:**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\autoprestamos\prueba_equipos\win-server.ps1"
```

### 3️⃣ Verificar Conexión

Si todo funciona correctamente, verás:

```
╔══════════════════════════════════════════════════════╗
║    SISTEMA DE AUTOPRÉSTAMOS - UNISIMÓN              ║
║    v2.3 - Arquitectura Dual Process                 ║
╚══════════════════════════════════════════════════════╝

[14:30:45] ℹ️ [Info] Detectando configuración de red...
[14:30:45] ✅ [Success] Interfaz detectada: Ethernet (MAC: 00:11:22:33:44:55)
[14:30:45] ℹ️ [Info] Estableciendo conexión WebSocket...
[14:30:45] 🌐 [WS-PROCESS] Conectando a ws://localhost:8081 (intento 1/5)...
[14:30:46] 🌐 [WS-PROCESS] ✅ Conectado exitosamente
[14:30:46] ✅ [Success] WebSocket conectado
```

---

## INTERFAZ GRÁFICA

Una pequeña ventana aparecerá en la **esquina inferior derecha** con:

- 👤 Usuario y MAC del equipo
- ⏱️ Estado actual (🟢 SESIÓN ACTIVA, 😴 HIBERNANDO, etc.)
- 🔽 Botón "Minimizar/Maximizar" para contraer/expandir la ventana

### Estados Mostrados

```
🟢 SESIÓN ACTIVA         - Sesión abierta (90 seg restantes)
🔒 BLOQUEADO             - Equipo bloqueado por admin
⏸️  SESIÓN SUSPENDIDA     - Esperando desbloqueo admin
😴 HIBERNANDO            - Inactividad detectada (60 seg para cerrar)
✅ SESIÓN FINALIZADA     - Sesión cerrada normalmente
```

---

## HIBERNACIÓN - COMPORTAMIENTO

### Cómo se Dispara

Si el equipo **está inactivo** (no hay movimiento de mouse/teclado) por **15 segundos consecutivos** (configurable):

1. **Cliente** detecta inactividad → `Get-SystemIdleTime > 15`
2. **Envía mensaje:** `{"tipo": "hibernado", "accion": "hibernar", ...}`
3. **Servidor** recibe → Actualiza BD: `id_estado_fk = 5` (Hibernando)
4. **Dashboard** ve cambio → Actualiza contadores en tiempo real
5. **UI Cliente** muestra ventana modal bloqueante: "💤 Modo Hibernación - 60 segundos"

### Cómo Se Cancela

Si se detecta **actividad** (movimiento mouse, clic, atajo de teclado):

1. **Cliente** detecta: `idle < 3 segundos`
2. **Ventana modal se cierra automáticamente**
3. **Envía:** `{"tipo": "hibernado", "accion": "cancelar", ...}`
4. **BD actualizada:** sesión regresa a "Abierto"
5. **MessageBox:** "Tu sesión ha sido renovada exitosamente"

### Cómo Se Finaliza

Si pasan **20 segundos en hibernación** sin actividad:

1. **Temporizador agota:** `Tiempo >= HIBERNATION_MAX_DURATION`
2. **Cliente finaliza:** `Invoke-FinalizarSesionRemota`
3. **Envía:** `{"tipo": "hibernado", "accion": "finalizar_por_hibernacion", ...}`
4. **Servidor:**
   - Actualiza BD: `id_estado_fk = 1` (Finalizado)
   - Llama API: `accion="finalizar"` (check-in en FOLIO)
   - Notifica dashboards
5. **Cliente se cierra**

---

## CONFIGURACIÓN

### Tiempos (Críticos)

En `win-server.ps1`, líneas 38-39:

```powershell
INACTIVITY_TIMEOUT       = 15    # segundos hasta hibernación
HIBERNATION_MAX_DURATION = 20    # segundos máximos en hibernación
```

**Para pruebas locales:** Reduce estos valores a 5 y 10 respectivamente.

**Para producción:** Usa 600 (10 min) y 3600 (1 hora) según política institucional.

### Servidor WebSocket

```powershell
ServidorWS = "ws://localhost:8081"  # Cambiar puerto si es necesario
```

### API REST

```powershell
ApiUrl = "http://localhost/autoprestamos/prueba_equipos/api.php"
```

---

## ARQUITECTURA TÉCNICA

### Dual Process

El cliente usa **2 procesos PowerShell paralelos**:

1. **UI Thread (Main)**: 
   - Interfaz gráfica WinForms
   - Monitor de comandos desde servidor
   - Procesamiento de estados

2. **WS Runspace (Independiente)**:
   - Conexión WebSocket persistente
   - Escucha continua de mensajes
   - Envío seguro de mensajes (OutgoingQueue)

**Ventaja:** La UI nunca se bloquea esperando respuesta de red.

### Manejo Seguro de WebSocket

```powershell
# En lugar de: $wsClient.SendAsync(...).Wait(3000)  ❌ Bloquea UI

# Usamos: Enqueue-WSMessage $payload  ✅ No bloquea
# El runspace drena la cola y envía desde su contexto
```

---

## SOLUCIÓN DE PROBLEMAS

### ❌ Error: "No se encontró interfaz de red"

```powershell
# Solución: Ejecutar en Admin y verificar conexión
ipconfig /all
```

### ❌ WebSocket no conecta: "Connection refused"

```powershell
# Verificar que el servidor está corriendo:
# En otra ventana PowerShell:
cd C:\xampp\htdocs\autoprestamos\servers
php server.php

# Debe mostrar:
# 🌐 Servidor WebSocket escuchando en port 8081...
```

### ❌ Hibernación no se dispara

```powershell
# Verificar configuración:
# - INACTIVITY_TIMEOUT = 15 (está bajo, debería funcionar)
# - NO tocar mouse/teclado por > 15 segundos
# - Revisar console: debería decir "Inactividad detectada (X s)"

# Para debugging, reduce a 5 segundos:
# INACTIVITY_TIMEOUT = 5
# Luego vuelve a tocar keyboard después de 10 segundos
```

### ❌ "Línea 1, carácter 0 - Cannot parse token"

```powershell
# Problema: Archivo guardado con encoding incorrecto
# Solución:
# 1. Abre win-server.ps1 en VS Code
# 2. Esquina inferior: busca "UTF-8 with BOM" (o similar)
# 3. Haz clic y selecciona "UTF-8 without BOM"
# 4. Guarda (Ctrl+S)
```

### ❌ "ExecutionPolicy: Cannot be loaded because running scripts is disabled"

```powershell
# Solución (ejecutar en Admin):
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
# O usar el bypass de una línea:
powershell -ExecutionPolicy Bypass -File ".\win-server.ps1"
```

---

## MONITOREO EN TIEMPO REAL

### Ver Logs en Consola PowerShell

Todos los eventos aparecen con **timestamp** y **emojis**:

```
[14:30:45] ℹ️  [Info] Estado actual: Abierto
[14:31:02] 😴 [Warning] Inactividad detectada (15 s) → Entrando en modo hibernación
[14:31:22] 🟢 [Info] Actividad detectada → Cancelando hibernación
[14:31:22] ✅ [Success] Renovación confirmada
```

### Ver Logs en Base de Datos

```sql
-- Conectar a MySQL y ejecutar:
SELECT * FROM sesiones WHERE username='tu_usuario' ORDER BY id DESC LIMIT 10;

-- Ver cambios de estado:
SELECT id, username, id_estado_fk, fecha_inicio, fecha_final_real 
FROM sesiones 
WHERE fecha_inicio > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY id DESC;
```

---

## INTEGRACIÓN CON SISTEMAS EXTERNOS

### FOLIO (Sistema de Préstamos)

El cliente automáticamente:
- ✅ Valida usuario en FOLIO al iniciar
- ✅ Registra préstamo (checkout) en FOLIO
- ✅ Confirma devolución (checkin) al finalizar

### Dashboard Web

El Dashboard **recibe actualizaciones en tiempo real**:
- Contador "Hibernando" se actualiza
- Estados de equipos se sincronizan
- Pueden enviar comandos remotos

---

## REFERENCIA RÁPIDA

| Acción | Tecla / Método |
|--------|---|
| Minimizar/Maximizar ventana | Botón en esquina inf-der |
| Detectar inactividad | Sistema automático (GetLastInputInfo) |
| Cancelar hibernación | Mover mouse o presionar tecla |
| Finalizar sesión manualmente | Contador llega a 0 sin renovación |
| Ver estado | Ventana principal (emoji + texto) |

---

## DOCUMENTACIÓN RELACIONADA

- 📋 **Flujo Completo:** `FLUJO_COMPLETO_SISTEMA.md`
- 🔧 **Implementación Hibernación:** `HIBERNACION_IMPLEMENTATION.md`
- 📊 **API REST:** Docs en `api.php`
- 🌐 **Dashboard:** `dashboard-unisimon/README.md`
- 🗄️ **BD:** Schema en `config/db.php`

---

**Última actualización:** Noviembre 2025
