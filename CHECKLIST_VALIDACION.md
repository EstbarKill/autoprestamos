# ✔️ CHECKLIST DE VALIDACIÓN - Sistema de Autopréstamos

**Fecha de Validación:** _______________  
**Responsable:** _______________  
**Versión Cliente:** 2.3  
**Ambiente:** ☐ Desarrollo ☐ Staging ☐ Producción

---

## 🔍 VERIFICACIÓN DE ARCHIVOS

### Archivo Principal

- [ ] Existe: `c:\xampp\htdocs\autoprestamos\prueba_equipos\win-server.ps1`
- [ ] Tamaño: ~970 líneas
- [ ] Contiene `Invoke-EstadoRenovado`
- [ ] Contiene `Invoke-EstadoHibernando`
- [ ] Contiene `OutgoingQueue` en SharedState
- [ ] Contiene función `Enqueue-WSMessage`
- [ ] NO contiene directos `SendAsync(...).Wait(...` en UI thread

### Archivos Obsoletos

- [ ] `win-server.txt` NO se usa (puede eliminar)
- [ ] `win-server copy.txt` NO se usa (puede eliminar)
- [ ] `win-server copy 2.txt` NO se usa (puede eliminar)

### Documentación Creada

- [ ] Existe: `FLUJO_COMPLETO_SISTEMA.md` (920+ líneas)
- [ ] Existe: `README_WIN_SERVER.md` (guía de uso)
- [ ] Existe: `ESTRUCTURA_WIN_SERVER.md` (índice técnico)
- [ ] Existe: `RESUMEN_REVISION_COMPLETA.md` (resumen ejecutivo)
- [ ] Existe: `HIBERNACION_IMPLEMENTATION.md` (detalles hibernación)

---

## 🌐 VALIDACIÓN DE COMPONENTES

### Servidor WebSocket

```bash
# En terminal, navegar a:
cd C:\xampp\htdocs\autoprestamos\servers

# Verificar que existe:
- [ ] server.php (628 líneas)
- [ ] vendor/autoload.php (Ratchet)
- [ ] server.pid (creado al iniciar)

# Iniciar servidor:
php server.php

# Debe mostrar:
[✓] 🌐 Servidor WebSocket escuchando en puerto 8081...
```

### API REST

- [ ] Existe: `c:\xampp\htdocs\autoprestamos\prueba_equipos\api.php`
- [ ] URL accesible: `http://localhost/autoprestamos/prueba_equipos/api.php`
- [ ] Conecta a BD correctamente
- [ ] Integra con FOLIO (si está configurado)

### Base de Datos

```sql
-- Conectarse y validar:
MySQL> USE autoprestamos;

-- Verificar tabla estados:
[✓] SELECT * FROM estados;
-- Debe haber: id_estado = 1,2,3,4,5,6

-- Verificar tabla sesiones:
[✓] SHOW COLUMNS FROM sesiones;
-- Debe tener: id_estado_fk

-- Verificar tabla equipos:
[✓] SELECT COUNT(*) FROM equipos;
```

### Dashboard

- [ ] Existe: `c:\xampp\htdocs\autoprestamos\dashboard-unisimon\dashboard.php`
- [ ] Accesible en: `http://localhost/autoprestamos/dashboard-unisimon/`
- [ ] WebSocket escucha en puerto 8081
- [ ] Muestra contadores: Abiertos, Hibernando, Bloqueados, etc.

---

## 🚀 PRUEBA DE INICIO

### Paso 1: Iniciar Servidor

```powershell
cd C:\xampp\htdocs\autoprestamos\servers
php server.php
```

**Esperado en consola:**
```
🌐 Servidor WebSocket escuchando en puerto 8081...
```

- [ ] **PASS** - Servidor inicia sin errores
- [ ] **FAIL** - Hay errores en la consola

### Paso 2: Iniciar Cliente

```powershell
cd C:\xampp\htdocs\autoprestamos\prueba_equipos
Set-ExecutionPolicy -ExecutionPolicy Bypass -Scope Process
.\win-server.ps1
```

**Esperado en consola:**
```
[14:30:45] ℹ️ [Info] Detectando configuración de red...
[14:30:45] ✅ [Success] Interfaz detectada: Ethernet (MAC: XX:XX:XX:XX:XX:XX)
[14:30:45] ℹ️ [Info] Estableciendo conexión WebSocket...
[14:30:46] 🌐 [WS-PROCESS] ✅ Conectado exitosamente
[14:30:46] ✅ [Success] WebSocket conectado
```

- [ ] **PASS** - Cliente inicia y se conecta
- [ ] **FAIL** - Hay errores, WebSocket no conecta

### Paso 3: Verificar Interfaz Gráfica

**Esperado:**
- Pequeña ventana en esquina inferior derecha
- Muestra usuario: `Usuario: NT AUTHORITY\SYSTEM`
- Muestra MAC: `MAC: XX:XX:XX:XX:XX:XX`
- Botón "Minimizar" funciona

- [ ] **PASS** - Interfaz gráfica visible y funcional
- [ ] **FAIL** - Interfaz no aparece o tiene errores

---

## 😴 PRUEBA DE HIBERNACIÓN

### Paso 4: Inactividad → Hibernación

**Configuración para prueba:**
```powershell
# En win-server.ps1, línea ~39, cambiar a:
INACTIVITY_TIMEOUT       = 5     # 5 segundos (en lugar de 15)
HIBERNATION_MAX_DURATION = 10    # 10 segundos (en lugar de 20)
```

**Procedimiento:**
1. Iniciar cliente
2. NO tocar mouse ni teclado por > 5 segundos
3. Observar consola

**Esperado:**
```
[14:30:52] 😴 [Warning] Inactividad detectada (5 s) → Entrando en modo hibernación
```

**En UI:**
- Ventana modal aparece (Maximized, Topmost)
- Muestra: "💤 El equipo entró en modo de hibernación"
- Muestra contador: "Finalizando en X segundos..."

- [ ] **PASS** - Hibernación se dispara correctamente
- [ ] **FAIL** - No entra en hibernación

### Paso 5: Cancelación de Hibernación

**Mientras está en ventana modal:**
1. Mover mouse O presionar cualquier tecla
2. Observar

**Esperado:**
```
[14:30:55] 🟢 [Info] Actividad detectada → Cancelando hibernación
[14:30:55] ✅ [Success] Hibernación cancelada o finalizada correctamente
```

**En UI:**
- Ventana modal se cierra
- MessageBox: "Tu sesión ha sido renovada exitosamente"
- Contador regresa a 0

- [ ] **PASS** - Hibernación se cancela
- [ ] **FAIL** - Ventana modal no se cierra

### Paso 6: Finalización por Timeout

**Procedimiento:**
1. Esperar a que se dispare hibernación (5 seg inactividad)
2. NO hacer actividad por 10 segundos más

**Esperado:**
```
[14:31:05] ⏰ Tiempo agotado — finalizando sesión automáticamente
[14:31:05] 🔚 Finalizando sesión en servidor...
[14:31:06] ✅ Sesión finalizada correctamente (hibernación)
```

**En UI:**
- Ventana modal se cierra
- Cliente se cierra automáticamente
- Console muestra "⛔ Limpieza completada"

- [ ] **PASS** - Sesión se finaliza por timeout
- [ ] **FAIL** - Cliente no se cierra

---

## 🔗 VALIDACIÓN DE FLUJOS

### Flujo 1: API ↔ BD ↔ Servidor

```
Cliente llama API:
curl -X POST http://localhost/autoprestamos/prueba_equipos/api.php \
  -H "Content-Type: application/json" \
  -d '{"accion":"confirmar_inicio", "username":"test", "mac_address":"XX:XX:XX:XX:XX:XX"}'

Respuesta esperada:
{
  "estado": "Abierto",
  "tiempo_restante": 90,
  ...
}
```

- [ ] **PASS** - API responde correctamente
- [ ] **FAIL** - Error 500 o respuesta vacía

### Flujo 2: WebSocket ↔ Servidor

**En consola de servidor, debe ver:**
```
🟢 Cliente conectado: (1)
📝 Cliente registrado: PC-HOSTNAME
👂 Iniciando escucha continua de mensajes...
```

- [ ] **PASS** - Servidor registra cliente
- [ ] **FAIL** - No hay registros

### Flujo 3: Hibernación ↔ BD

**Luego de entrar en hibernación, verificar BD:**
```sql
SELECT id, username, id_estado_fk FROM sesiones 
WHERE fecha_inicio > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY id DESC LIMIT 1;
```

**Esperado:** id_estado_fk = 5 (Hibernando)

- [ ] **PASS** - Estado actualizado a 5
- [ ] **FAIL** - Estado no cambia

### Flujo 4: Dashboard ↔ Servidor

**Abrir dashboard en navegador:**
`http://localhost/autoprestamos/dashboard-unisimon/`

**Esperado:**
- Contador "Abiertos" muestra 1
- Cuando cliente entra hibernación → "Hibernando" incrementa
- Cuando se finaliza → "Finalizados" incrementa

- [ ] **PASS** - Dashboard actualiza en tiempo real
- [ ] **FAIL** - Contadores no cambian

---

## 🔐 VALIDACIÓN DE SEGURIDAD

### Verificación 1: MAC Address

```powershell
# En cliente, verificar:
$Global:SharedState.MacAddress

# Debe mostrar algo como: AA:BB:CC:DD:EE:FF
```

- [ ] **PASS** - MAC se detecta correctamente
- [ ] **FAIL** - MAC es $null o vacío

### Verificación 2: Validación en Servidor

**En servidor.php, línea ~50, verificar:**
```php
if ($data['origen'] != 'server') {
    return; // Rechaza
}
```

- [ ] **PASS** - Servidor valida origen
- [ ] **FAIL** - No hay validación

### Verificación 3: Encriptación (Futuro)

- [ ] [ ] WebSocket está en WSS (wss://) - NO IMPLEMENTADO TODAVÍA
- [ ] [ ] JWT token en mensajes - NO IMPLEMENTADO TODAVÍA

---

## 📊 VALIDACIÓN DE PERFORMANCE

### CPU Usage

**Con cliente en idle (sin inactividad):**
```powershell
Get-Process -Name powershell | Select-Object @{n='CPU %';e={$_.CPU}}

# Esperado: < 2%
```

- [ ] **PASS** - CPU bajo (< 2%)
- [ ] **FAIL** - CPU alto (> 5%)

### Memory Usage

```powershell
Get-Process -Name powershell | Select-Object @{n='Mem MB';e={$_.WS/1MB}}

# Esperado: 100-200 MB
```

- [ ] **PASS** - Memoria razonable (< 300 MB)
- [ ] **FAIL** - Memory leak (> 500 MB)

### WebSocket Latency

**Enviar ping/pong en servidor y medir:**
- Esperado: < 50 ms (localhost)
- [ ] **PASS** - Latencia baja
- [ ] **FAIL** - Latencia alta (> 500 ms)

---

## 🧹 LIMPIEZA Y CIERRE

### Detener Cliente

```powershell
# En ventana del cliente, presionar: Ctrl+C
# O cerrar ventana
```

**Esperado en consola:**
```
🛑 Limpiando recursos...
✅ Recursos liberados completamente
```

- [ ] **PASS** - Cierre limpio
- [ ] **FAIL** - Procesos huérfanos

### Detener Servidor

```powershell
# En ventana del servidor, presionar: Ctrl+C
```

**Esperado:**
```
🛑 Servidor detenido
```

- [ ] **PASS** - Servidor se detiene
- [ ] **FAIL** - Procesos o errores

### Verificar BD Limpieza

```sql
-- Verificar que no hay referencias a procesos cerrados
SELECT COUNT(*) FROM sesiones WHERE fecha_final_real IS NULL;

-- Expectedalor: Solo sesiones activas (0 si todas finalizadas)
```

- [ ] **PASS** - BD limpia
- [ ] **FAIL** - Sesiones huérfanas

---

## ✅ RESUMEN FINAL

**Total de validaciones:** _____ / _____  
**Pasadas:** _____ (___%)  
**Fallidas:** _____ (___%)

### Estado General

- [ ] ✅ **LISTO PARA PRODUCCIÓN** - Todas las pruebas pasaron
- [ ] ⚠️ **CASI LISTO** - Fallos menores (especificar abajo)
- [ ] ❌ **NO LISTO** - Fallos críticos (ver sección de problemas)

### Problemas Encontrados

```
1. _________________________________________________________________
   Severidad: [ ] Crítico [ ] Mayor [ ] Menor
   Acción: _________________________________________________________
   
2. _________________________________________________________________
   Severidad: [ ] Crítico [ ] Mayor [ ] Menor
   Acción: _________________________________________________________
```

### Aprobaciones

**Validador:** _______________________ **Fecha:** _______________

**Responsable SO:** _________________ **Fecha:** _______________

**Gerente Proyecto:** ______________ **Fecha:** _______________

---

## 📚 Referencias

- Guía de uso: `README_WIN_SERVER.md`
- Solución de problemas: `FLUJO_COMPLETO_SISTEMA.md` → Sección "Problemas Conocidos"
- Contacto: [Especificar contacto técnico]

---

**Documento:** Checklist de Validación  
**Versión:** 1.0  
**Última actualización:** Noviembre 13, 2025
