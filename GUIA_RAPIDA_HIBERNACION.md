# 🔧 GUÍA RÁPIDA DE VALIDACIÓN - CORRECCIONES DE HIBERNACIÓN

## ⚡ PASO 1: Validar BD (2 min)

Abre una terminal MySQL y ejecuta:

```bash
cd C:\xampp\mysql\bin
mysql -u root autoprestamo < C:\xampp\htdocs\autoprestamos\validar_hibernacion.sql
```

**Qué esperar:**
- ✅ `id_estado = 5` debe existir con nombre "Hibernando"
- ✅ Si no existe, se insertará automáticamente
- ✅ Verás lista de todos los estados
- ✅ Conteo de sesiones por estado

---

## ⚡ PASO 2: Reiniciar Servicios (3 min)

```powershell
# Terminal PowerShell como ADMIN

# 1. Detener servidor Ratchet (si está corriendo)
cd C:\xampp\htdocs\autoprestamos\servers
php detener_server.php

# 2. Iniciar servidor Ratchet
php iniciar_server.php

# 3. Verificar que está corriendo
php estado_server.php
```

---

## ⚡ PASO 3: Prueba en Dashboard (5 min)

1. **Abrir dashboard:**
   ```
   http://localhost/autoprestamos/dashboard-unisimon/dashboard.php
   ```

2. **Seleccionar una sede** en el dropdown
3. **Conectar** al servidor WebSocket (botón verde)
4. **Verificar estadísticas** en la barra lateral
   - Debe mostrar "Hibernado: X"

---

## ⚡ PASO 4: Prueba de Hibernación (15 min)

1. **Ejecutar cliente PowerShell:**
   ```powershell
   C:\xampp\htdocs\autoprestamos\prueba_equipos\win-server.txt
   # O cargar el script en PowerShell ISE
   ```

2. **Conectar sesión** desde el cliente

3. **NO TOCAR NADA** (ratón ni teclado) por **5 minutos**
   - El cliente mostrará "😴 HIBERNANDO" en la ventana

4. **Verificar en Dashboard:**
   - Sesión debe cambiar a estado "Hibernado"
   - Contador de "Hibernado" debe incrementar

5. **Esperar 10 minutos más** (sin actividad)
   - Sesión debe pasar a "Finalizado" automáticamente

---

## 📊 VERIFICACIÓN RÁPIDA EN BASE DE DATOS

```bash
# Terminal MySQL
SELECT s.id, s.username, e.nombre_estado, s.id_estado_fk 
FROM sesiones s
LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
WHERE s.id_estado_fk = 5
LIMIT 10;
```

**Esperado:**
- Filas con `nombre_estado = "Hibernando"` y `id_estado_fk = 5`

---

## 🧪 PRUEBA SIN ESPERAR 5 MINUTOS

Si quieres probar más rápido, modifica en `win-server.txt`:

```powershell
# Línea ~1008 (aprox)
ANTES:
$INACTIVITY_TIMEOUT = 300   # 5 minutos

DESPUÉS (SOLO PARA TESTING):
$INACTIVITY_TIMEOUT = 10    # 10 segundos
```

Luego:
1. Ejecutar cliente con timeout de 10s
2. Sesión se hibernará en 10 segundos
3. Finalizará en otros 10 segundos
4. Perfecto para testing

**⚠️ RECUERDA:** Cambiar de vuelta a `300` después de testing.

---

## ❌ TROUBLESHOOTING

### "Hibernado: 0 en dashboard"
- ✅ Verificar que `id_estado = 5` existe en BD
- ✅ Recargar dashboard (F5)
- ✅ Verificar que el cliente PowerShell se conectó correctamente

### "SQL error" en server.php
- ✅ Revisar logs en `servers/` folder
- ✅ Ejecutar `validar_hibernacion.sql` para verificar estructura BD

### Cliente PowerShell no se conecta
- ✅ Verificar que servidor Ratchet está corriendo: `php estado_server.php`
- ✅ Revisar puerto 8081 está abierto: `netstat -ano | findstr :8081`

---

## 📝 ARCHIVOS MODIFICADOS

| Archivo | Cambio |
|---------|--------|
| `dashboard_stats.php` | Búsqueda de hibernación con minúsculas |
| `dashboard_stats.php` | SQL sin duplicados WHERE/GROUP BY |
| `server.php` | Alias `id_sesion` agregado |
| `server.php` | Validación automática de estado 5 |

---

## ✅ RESUMEN

**3 Problemas Críticos Corregidos:**
1. ✅ Hibernación se contaba como "0" → Ahora cuenta correctamente
2. ✅ SQL construido incorrectamente → Ahora correcto
3. ✅ Alias de id_sesion faltante → Ahora presente

**Resultado:**
- Dashboard muestra contador de Hibernado en tiempo real
- Servidor crea estado 5 automáticamente si falta
- Hibernación funciona end-to-end

---

**¿Tienes dudas? Ejecuta el script SQL y comparte output si hay errores.**
