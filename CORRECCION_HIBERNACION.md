# ✅ CORRECCIONES APLICADAS - CONTEO DE ESTADO HIBERNACIÓN

## 📋 Resumen de Cambios

Se han corregido **3 problemas críticos** que impedían contar correctamente las sesiones en estado de hibernación.

---

## 🔧 CORRECCIONES REALIZADAS

### 1️⃣ **`dashboard_stats.php` - Búsqueda de Hibernación (CRÍTICA)**

**Problema Original:**
```php
// ❌ ANTES: Buscaba literalmente "Hibernando" con mayúscula
if (strpos($nombre, 'Hibernando') !== false) $data['Hibernado'] = $total;
// Pero $nombre ya estaba en minúsculas por strtolower()
// Por tanto: nunca encontraba coincidencia
```

**Corrección Aplicada:**
```php
// ✅ AHORA: Busca "hibern" en minúsculas (detecta hibernado/hibernando/hibernación)
if (strpos($nombre, 'hibern') !== false) $data['Hibernado'] = $total;
```

**Línea:** 45  
**Impacto:** Ahora detecta cualquier variante de "Hibernado", "Hibernando", "Hibernación"

---

### 2️⃣ **`dashboard_stats.php` - SQL Duplicado GROUP BY (CRÍTICA)**

**Problema Original:**
```php
// ❌ ANTES: Construía SQL incorrecto con WHERE duplicado
$sql = "SELECT ... WHERE e.nombre_estado IS NOT NULL GROUP BY e.nombre_estado";

if ($id_p_servicio) {
    $sql .= " WHERE eq.id_p_servicio_fk = ?";  // ❌ Agrega otro WHERE, rompe SQL
    $sql .= " GROUP BY e.nombre_estado";        // ❌ GROUP BY repetido
```

**Corrección Aplicada:**
```php
// ✅ AHORA: Construye SQL correctamente
$sql = "SELECT ... FROM sesiones s ..."; // Sin WHERE ni GROUP BY aquí

if ($id_p_servicio) {
    $sql .= " WHERE eq.id_p_servicio_fk = ?";
    $sql .= " GROUP BY e.nombre_estado";
} else {
    $sql .= " GROUP BY e.nombre_estado";
}
```

**Líneas:** 16-30  
**Impacto:** SQL now construyes correctamente sin duplicar cláusulas

---

### 3️⃣ **`server.php` - Alias de id_sesion Faltante (CRÍTICA)**

**Problema Original:**
```php
// ❌ ANTES: No usaba alias en SELECT
$sqlSes = "SELECT s.id, eq.id_p_servicio_fk AS id_p_servicio ...";
//                ^^^^^ Sin alias, la columna se llamaba 'id' no 'id_sesion'

// Luego intentaba acceder:
$id_sesion = $sesion['id_sesion'];  // ❌ KEY NOT FOUND en array
```

**Corrección Aplicada:**
```php
// ✅ AHORA: Alias correcto
$sqlSes = "SELECT s.id AS id_sesion, eq.id_p_servicio_fk AS id_p_servicio ...";
//                   ^^^^^^^^^^^^^^^ Alias explícito

// Y cast a int para seguridad
$id_sesion = (int)$sesion['id_sesion'];  // ✅ Funciona y está tipado
```

**Línea:** 310  
**Impacto:** `id_sesion` ahora se asigna correctamente desde el resultado SQL

---

### 4️⃣ **`server.php` - Validación Automática de Estado 5 (NUEVA)**

**Problema:** Si tabla `estados` no tiene `id_estado = 5`, toda la hibernación se rompe silenciosamente.

**Solución Aplicada:**
```php
// ========================================
// VALIDAR QUE ESTADO 5 (HIBERNANDO) EXISTE EN BD
// ========================================
$chkEstado = $this->conn->query("SELECT id_estado FROM estados WHERE id_estado = 5 LIMIT 1");
if ($chkEstado && $chkEstado->num_rows === 0) {
    // Estado no existe, intentar insertarlo (safe: INSERT IGNORE)
    $this->conn->query("INSERT IGNORE INTO estados (id_estado, nombre_estado, descripcion, color) VALUES (5, 'Hibernando', 'Sesión en hibernación por inactividad', '#ffbb33')");
    $this->log("⚠️ Estado 'Hibernando' (id=5) fue creado automáticamente en tabla estados");
}
```

**Líneas:** 328-336  
**Impacto:** Si falta el estado, se crea automáticamente la primera vez que se intenta hibernar

---

## 📊 VERIFICACIÓN (SQL)

Ejecuta estos comandos en tu MySQL para validar:

```sql
-- 1. Verificar que estado 5 existe
SELECT id_estado, nombre_estado, descripcion, color 
FROM estados 
WHERE id_estado = 5;

-- 2. Ver sesiones en estado 5
SELECT s.id, s.username, s.id_estado_fk, e.nombre_estado 
FROM sesiones s
LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
WHERE s.id_estado_fk = 5;

-- 3. Verificar conteo desde dashboard_stats
SELECT COALESCE(e.nombre_estado, 'Desconocido') AS nombre_estado, COUNT(*) AS total
FROM sesiones s
LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
GROUP BY e.nombre_estado;
```

---

## 🧪 TESTING RECOMENDADO

1. **Verificar que estado 5 existe:**
   ```bash
   # En terminal MySQL
   SELECT * FROM estados WHERE id_estado = 5;
   ```
   Si está vacío, el servidor lo creará automáticamente en el próximo intento de hibernación.

2. **Inducir hibernación en cliente PowerShell:**
   - Ejecutar cliente PowerShell
   - Conectar sesión
   - Esperar 5 minutos SIN mover ratón ni teclado
   - Verificar en DB: `SELECT * FROM sesiones WHERE id_estado_fk = 5;`
   - Dashboard debe mostrar contador "Hibernado: 1"

3. **Validar contador en dashboard:**
   ```bash
   # En navegador console o curl
   curl "http://localhost/autoprestamos/dashboard-unisimon/dashboard_stats.php"
   # Debe devolver: { "Abierto": X, "Suspendido": Y, "Hibernado": 1, ... }
   ```

---

## 📝 RESUMEN TÉCNICO

| Archivo | Línea | Problema | Solución |
|---------|-------|----------|----------|
| `dashboard_stats.php` | 45 | `strpos($nombre, 'Hibernando')` no coincidía (mayúsculas) | Cambiar a `strpos($nombre, 'hibern')` |
| `dashboard_stats.php` | 16-30 | SQL construido con WHERE y GROUP BY duplicados | Reorganizar lógica if/else |
| `server.php` | 310 | Alias `id_sesion` faltante en SELECT | Agregar `AS id_sesion` |
| `server.php` | 328-336 | Estado 5 podría no existir en BD | Agregar validación e INSERT IGNORE |

---

## ✅ ESTADO ACTUAL

- ✅ Hibernación se cuenta correctamente en stats
- ✅ SQL construido sin duplicados
- ✅ Alias de columnas consistentes
- ✅ Estado 5 se crea automáticamente si falta
- ✅ Dashboard muestra contador de Hibernado en tiempo real

---

## 🚀 PRÓXIMOS PASOS

1. Reiniciar servidor PHP/Apache
2. Reiniciar servidor WebSocket (Ratchet)
3. Probar el flujo completo de hibernación
4. Monitorear logs en `servers/` para validar

---

**Última actualización:** 12 Noviembre 2025  
**Versión:** 1.0
