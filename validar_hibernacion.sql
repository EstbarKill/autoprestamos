-- ============================================================
-- SCRIPT DE VALIDACIÓN Y CORRECCIÓN - ESTADO HIBERNACIÓN
-- ============================================================
-- Ejecutar en MySQL como usuario root en la BD 'autoprestamo'

-- 1. VERIFICAR QUE ESTADO 5 (HIBERNANDO) EXISTE
-- ============================================================
SELECT 'VERIFICACIÓN: Buscando estado id=5' AS paso;
SELECT id_estado, nombre_estado, descripcion, color 
FROM estados 
WHERE id_estado = 5;

-- Si la consulta anterior devuelve vacío, ejecutar esto:
-- ============================================================
-- INSERTAR ESTADO 5 SI NO EXISTE
-- ============================================================
INSERT IGNORE INTO estados (id_estado, nombre_estado, descripcion, color) 
VALUES (5, 'Hibernando', 'Sesión en hibernación por inactividad', '#ffbb33');

-- Verificar que se insertó
SELECT 'VERIFICACIÓN DESPUÉS DE INSERT' AS paso;
SELECT * FROM estados WHERE id_estado = 5;

-- 2. LISTAR TODOS LOS ESTADOS
-- ============================================================
SELECT 'LISTA DE TODOS LOS ESTADOS' AS paso;
SELECT id_estado, nombre_estado 
FROM estados 
ORDER BY id_estado;

-- 3. CONTAR SESIONES POR ESTADO
-- ============================================================
SELECT 'CONTEO DE SESIONES POR ESTADO' AS paso;
SELECT COALESCE(e.nombre_estado, 'Desconocido') AS estado, COUNT(*) AS total
FROM sesiones s
LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
GROUP BY e.nombre_estado
ORDER BY total DESC;

-- 4. VER SESIONES EN ESTADO HIBERNANDO (id_estado_fk = 5)
-- ============================================================
SELECT 'SESIONES EN ESTADO HIBERNANDO' AS paso;
SELECT s.id, s.username, s.id_equipo_fk, e.nombre_estado, s.fecha_inicio, s.fecha_final_programada
FROM sesiones s
LEFT JOIN estados e ON e.id_estado = s.id_estado_fk
WHERE s.id_estado_fk = 5
ORDER BY s.fecha_inicio DESC;

-- 5. VALIDAR ALIAS id_sesion EN QUERY (como se usa en server.php)
-- ============================================================
SELECT 'VERIFICACIÓN DE ALIAS id_sesion (como en server.php)' AS paso;
SELECT s.id AS id_sesion, eq.nombre_pc, eq.id_p_servicio_fk AS id_p_servicio, s.id_estado_fk
FROM sesiones s
LEFT JOIN equipos eq ON eq.id_equipo = s.id_equipo_fk
WHERE eq.nombre_pc IS NOT NULL AND s.id_estado_fk IN (2,3,4,5)
LIMIT 5;

-- 6. ESTRUCTURA DE TABLA sesiones (para verificar que existe columna 'id')
-- ============================================================
SELECT 'ESTRUCTURA DE TABLA sesiones' AS paso;
DESCRIBE sesiones;

-- 7. ESTRUCTURA DE TABLA estados
-- ============================================================
SELECT 'ESTRUCTURA DE TABLA estados' AS paso;
DESCRIBE estados;

-- ============================================================
-- FIN DEL SCRIPT
-- ============================================================
