# 📋 CHECKLIST DIARIAS - 7 DÍAS PARA ENTREGA

## 📅 TIMELINE FINAL (11 NOV - 18 NOV 2025)

```
NOV 11 (Hoy)     → Análisis + Planificación ✅ (COMPLETADO)
NOV 15 (Viernes) → Hardening de Seguridad 🔐
NOV 16 (Sábado)  → Confiabilidad del Server 💪
NOV 17 (Domingo) → Refactorización y Limpieza 🧹
NOV 18 (Lunes)   → Testing + Documentación Final 📚
                   ENTREGA 🚀
```

---

# 🔐 VIERNES 15 NOV - HARDENING DE SEGURIDAD

## Objetivo: Cerrar todas las vulnerabilidades críticas

### Bloque 1: 08:00 - 10:00 (2h) - VALIDACIÓN DE ENTRADA

**Checklist:**
- [ ] Crear archivo `prueba_equipos/validation.php` (copiar de BOILERPLATE)
- [ ] Actualizar `prueba_equipos/api.php` para usar validador
  - [ ] Validar `username` en toda entrada
  - [ ] Validar `mac_address` en toda entrada
  - [ ] Validar `tipo` contra enum permitido
  - [ ] Validar `id_equipo` como integer positivo
- [ ] Probar que requests inválidas son rechazadas
- [ ] Verificar en browser console que no hay errores 500

**Validación:**
```bash
# ✅ Request válida
curl "http://localhost/prueba_equipos/api.php?username=usuario&mac_address=00:1A:2B:3C:4D:5E&tipo=control"

# ❌ Request inválida - debe fallar
curl "http://localhost/prueba_equipos/api.php?username=<script>&mac_address=invalid"
```

**Tiempo estimado:** 2 horas  
**Riesgo:** Bajo - cambios aislados  
**Rollback:** Git checkout si falla

---

### Bloque 2: 10:00 - 12:00 (2h) - AUTENTICACIÓN WEBSOCKET

**Checklist:**
- [ ] Crear archivo `prueba_equipos/jwt.php` (copiar de BOILERPLATE)
- [ ] Generar token JWT en `api.php` cuando usuario se autentica
  - [ ] Token contiene: user_id, mac_address, timestamp, expiry
  - [ ] Enviar token en respuesta al cliente
- [ ] Modificar `servers/server.php` para validar token en registro
  - [ ] En `case 'registro'`: verificar token antes de aceptar
  - [ ] Rechazar con `$from->close()` si token inválido
- [ ] Probar desde PowerShell client que debe enviar token

**Validación:**
```php
// ✅ Token válido - conexión acepta
// ❌ Token inválido - conexión rechazada
```

**Tiempo estimado:** 2 horas  
**Riesgo:** Medio - cambios en servidor crítico  
**Rollback:** Guardar versión anterior de `server.php`

---

### Bloque 3: 14:00 - 15:00 (1h) - RATE LIMITING

**Checklist:**
- [ ] Crear archivo `prueba_equipos/ratelimit.php` (copiar de BOILERPLATE)
- [ ] Integrar en inicio de `api.php`
  - [ ] Verificar `$_SERVER['REMOTE_ADDR']` contra límite
  - [ ] Retornar 429 si excedido
  - [ ] Agregar header `X-RateLimit-Remaining`
- [ ] Probar enviando 101 requests rápido
  - [ ] Primeros 100 → éxito
  - [ ] Request 101 → error 429

**Validación:**
```bash
# Enviar 101 requests
for i in {1..101}; do
  curl -s "http://localhost/api.php?username=test&mac=00:1A" | grep -o "estado"
done
# Resultado: 100 éxitos + 1 error 429
```

**Tiempo estimado:** 1 hora  
**Riesgo:** Bajo  
**Rollback:** Comentar rate limiter, revertir

---

### Bloque 4: 15:00 - 16:00 (1h) - HEADERS DE SEGURIDAD

**Checklist:**
- [ ] Agregar headers en `api.php`
  ```php
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('X-XSS-Protection: 1; mode=block');
  header('Strict-Transport-Security: max-age=31536000');
  ```
- [ ] Agregar CORS solo para dominio permitido
  ```php
  header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
  header('Access-Control-Allow-Credentials: true');
  ```
- [ ] Verificar con curl que headers se envían
  ```bash
  curl -I http://localhost/api.php | grep X-
  ```

**Tiempo estimado:** 1 hora  
**Riesgo:** Bajo  
**Rollback:** Trivial

---

## 📊 REPORTE FIN DE DÍA (Viernes 15 NOV)

**Checklist Final:**
- [ ] Seguridad: De 30 → 75 (Score)
- [ ] Sin errores PHP en logs
- [ ] Validación rechaza inputs malformados
- [ ] WebSocket requiere token
- [ ] Rate limiter funciona
- [ ] Headers incluidos

**Commits esperados:**
```bash
git add .
git commit -m "🔐 Hardening seguridad: validacion, JWT, rate-limit"
git push
```

**Status:** ✅ COMPLETADO si:
- Todo valida correctamente
- No hay regressions
- Tests locales pasan

---

# 💪 SÁBADO 16 NOV - CONFIABILIDAD DEL SERVER

## Objetivo: Hacer servidor resiliente y debuggable

### Bloque 1: 08:00 - 10:00 (2h) - LOGGING CENTRALIZADO

**Checklist:**
- [ ] Crear archivo `config/Logger.php` (copiar de BOILERPLATE)
- [ ] Crear directorio `/logs` con permisos 777
- [ ] Integrar Logger en `servers/server.php`
  - [ ] Logger::info() en onOpen
  - [ ] Logger::error() en excepciones
  - [ ] Logger::debug() en mensajes
- [ ] Integrar Logger en `prueba_equipos/api.php`
  - [ ] Logger::info() en acciones importantes
  - [ ] Logger::warning() en situaciones sospechosas
- [ ] Verificar que `/logs/autoprestamo.log` se crea
- [ ] Tail log en tiempo real:
  ```bash
  tail -f /var/log/autoprestamo.log
  ```

**Validación:**
```bash
# Ver últimos logs
tail -50 logs/autoprestamo.log

# Debe mostrar timestamps y niveles
# [2025-11-16 10:15:32] [INFO] Equipo registrado: PC-LAB-01
# [2025-11-16 10:15:35] [DEBUG] Comando recibido: status
```

**Tiempo estimado:** 2 horas  
**Riesgo:** Bajo - aditivo  
**Rollback:** Remover Logger calls

---

### Bloque 2: 10:00 - 12:00 (2h) - ERROR HANDLING GLOBAL

**Checklist:**
- [ ] Agregar `set_error_handler()` en `api.php`
- [ ] Agregar `set_exception_handler()` en `api.php`
- [ ] Agregar `register_shutdown_function()` para fatal errors
- [ ] Pruebas:
  - [ ] Trigger PHP warning → loguea y no interrumpe
  - [ ] Trigger exception → loguea y retorna JSON error
  - [ ] Divide por cero → captura error fatal
- [ ] Verificar que errors no muestra stacktrace al usuario
  - [ ] Usuario recibe: `"Error interno del servidor"`
  - [ ] Log tiene: stacktrace completo

**Validación:**
```php
// Test error handling
// En api.php, trigger:
undefined_function();  // → ERROR capturado
1/0;                   // → ERROR capturado
throw new Exception(); // → ERROR capturado
```

**Tiempo estimado:** 2 horas  
**Riesgo:** Medio - toca core flow  
**Rollback:** Revertir handlers

---

### Bloque 3: 14:00 - 15:30 (1.5h) - LIMPIEZA DE CONEXIONES

**Checklist:**
- [ ] En `servers/server.php`, agregar en `onClose()`:
  ```php
  public function onClose(ConnectionInterface $conn) {
      $this->clients->detach($conn);
      if (isset($this->equipos[$conn->idCliente])) {
          unset($this->equipos[$conn->idCliente]);
      }
      Logger::info("Conexión cerrada: {$conn->resourceId}");
  }
  ```
- [ ] Agregar timeout para conexiones inactivas (ping/pong)
- [ ] Probar matando conexión con `kill -9` en PowerShell
  - [ ] Server debe limpiar sin crash
  - [ ] Memory no debe crecer indefinidamente

**Monitoreo:**
```bash
# En otra terminal, ejecutar cada 5s:
watch -n5 "ps aux | grep php"
# Verificar que memoria no sube constantemente
```

**Tiempo estimado:** 1.5 horas  
**Riesgo:** Medio  
**Rollback:** Restaurar `server.php`

---

### Bloque 4: 15:30 - 16:30 (1h) - RETRY LOGIC

**Checklist:**
- [ ] En `WebSocketClient.js`, implementar reconexión
  - [ ] Intenta 5 veces
  - [ ] Espera 3 segundos entre intentos
  - [ ] Backoff exponencial (3s, 6s, 12s, etc)
- [ ] Probar matando servidor y reiniciando
  - [ ] Dashboard debe reconectar automáticamente
  - [ ] Sin intervención manual

**Validación:**
```javascript
// En console del navegador
// Matar server
// Ver en consola: "🔄 Reintentando..."
// Server se reinicia
// Ver en consola: "✅ Reconectado"
```

**Tiempo estimado:** 1 hora  
**Riesgo:** Bajo - cambios en JS  
**Rollback:** Restaurar websocket.js

---

## 📊 REPORTE FIN DE DÍA (Sábado 16 NOV)

**Checklist Final:**
- [ ] Logging completo en `/logs/autoprestamo.log`
- [ ] Errors se capturan sin crashes
- [ ] Conexiones se limpian
- [ ] Dashboard reconecta automáticamente
- [ ] Memory usage estable bajo carga
- [ ] Server aguanta 1 hora sin problemas

**Tests de estrés:**
```bash
# En PowerShell, conectar 10 clientes simulados
for ($i=1; $i -le 10; $i++) {
  Start-Process powershell -ArgumentList "-NoExit -Command 'Connect-WebSocket'"
}
# Verificar que server no muere
```

**Commits esperados:**
```bash
git add .
git commit -m "💪 Confiabilidad: logging, error handling, cleanup"
git push
```

**Status:** ✅ COMPLETADO si:
- Logs son detallados
- No hay crashes
- Reconexión automática funciona
- Memory stable

---

# 🧹 DOMINGO 17 NOV - REFACTORIZACIÓN Y LIMPIEZA

## Objetivo: Código maintainable y profesional

### Bloque 1: 08:00 - 11:00 (3h) - MODULARIZAR JAVASCRIPT

**Checklist:**
- [ ] Crear directorio `dashboard-unisimon/assets/js/modules/`
- [ ] Crear `modules/EventBus.js` (copiar de BOILERPLATE)
- [ ] Crear `modules/WebSocketClient.js` (copiar de BOILERPLATE)
- [ ] Crear `modules/DashboardUI.js` (copiar de BOILERPLATE)
- [ ] Crear `main.js` que orquesta todo
- [ ] En `dashboard.php`, actualizar scripts:
  ```html
  <script src="./assets/js/modules/EventBus.js"></script>
  <script src="./assets/js/modules/WebSocketClient.js"></script>
  <script src="./assets/js/modules/DashboardUI.js"></script>
  <script src="./assets/js/main.js"></script>
  ```
- [ ] Remover/archiva el viejo `dashboard.js` de 868 líneas
- [ ] Probar que dashboard funciona
  - [ ] Botones responden
  - [ ] WebSocket conecta
  - [ ] Sin errores en console

**Checklist de Código:**
- [ ] Máximo 50 líneas por función
- [ ] Funciones con nombre descriptivo
- [ ] Comentarios en funciones complejas
- [ ] Sin variables globales sueltas (todo en `app`)
- [ ] Eventos centralizados en EventBus

**Tiempo estimado:** 3 horas  
**Riesgo:** Medio - cambios visuales  
**Rollback:** Restaurar `dashboard.js` viejo

---

### Bloque 2: 11:00 - 13:00 (2h) - SEPARAR CONCERNS EN PHP

**Checklist:**
- [ ] Crear `config/config.php` (copiar de BOILERPLATE)
  - [ ] Cargar desde `.env`
  - [ ] Usar constantes globales
- [ ] Crear `config/database.php`
  ```php
  $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
  ```
- [ ] Refactorizar `api.php`:
  - [ ] Remover hardcoding de BD
  - [ ] Usar config central
  - [ ] Separar rutas en función dispatch
- [ ] Refactorizar `server.php`:
  - [ ] Separar lógica de WebSocket de lógica de negocio
  - [ ] Crear clase `CommandHandler`
- [ ] Verificar que nada se rompió

**Validación:**
```bash
# API debe funcionar igual
curl "http://localhost/api.php?username=x&mac=y&tipo=z"

# Server debe iniciar sin errores
php servers/server.php
```

**Tiempo estimado:** 2 horas  
**Riesgo:** Medio  
**Rollback:** Git checkout de archivos

---

### Bloque 3: 13:00 - 14:30 (1.5h) - CODE CLEANUP

**Checklist:**
- [ ] Remover console.log() del JS que vaya a producción
- [ ] Remover var_dump() y print_r() del PHP
- [ ] Agregar comentarios a funciones compleja
- [ ] Revisar indentación (4 espacios PHP, 2 JS)
- [ ] Remover archivos obsoletos
  - [ ] Renombra archivo viejo: `dashboard.js` → `dashboard.js.bak`
  - [ ] Remover archivos de debug
- [ ] Verificar no hay espacios en blanco al final de líneas
- [ ] Verificar charset UTF-8 en todos archivos

**Lint checks:**
```bash
# PHP
php -l prueba_equipos/api.php
php -l servers/server.php

# Revisar errores de sintaxis
```

**Tiempo estimado:** 1.5 horas  
**Riesgo:** Bajo  
**Rollback:** Trivial

---

### Bloque 4: 14:30 - 16:00 (1.5h) - SETUP FILES

**Checklist:**
- [ ] Crear `.env.example` (plantilla sin secrets)
  ```bash
  cp .env .env.example
  # Cambiar valores a placeholders
  DB_PASS=your-password-here
  JWT_SECRET=your-secret-here
  ```
- [ ] Crear `install.sh` (copiar de BOILERPLATE)
- [ ] Crear `install.ps1` (copiar de BOILERPLATE)
- [ ] Crear `.gitignore`:
  ```
  .env
  /logs/*
  /vendor/*
  *.log
  .DS_Store
  ```
- [ ] Hacer scripts ejecutables:
  ```bash
  chmod +x install.sh
  ```
- [ ] Probar que install.sh/ps1 funcionan en ambiente limpio
  - [ ] Copiar repo a carpeta tmp
  - [ ] Ejecutar script
  - [ ] Verificar que se instala correctamente

**Tiempo estimado:** 1.5 horas  
**Riesgo:** Bajo  
**Rollback:** Remover files

---

## 📊 REPORTE FIN DE DÍA (Domingo 17 NOV)

**Checklist Final:**
- [ ] Código modularizado
- [ ] Sin hardcoding de config
- [ ] Clean code sin debug prints
- [ ] Scripts de instalación funcionales
- [ ] `.env.example` documentado

**Validaciones:**
- [ ] Dashboard carga sin errores
- [ ] API funciona correctamente
- [ ] Server inicia sin warnings
- [ ] Install scripts completan sin errores

**Commits esperados:**
```bash
git add .
git commit -m "🧹 Refactorización: modularización JS, cleanup, config"
git push
```

**Status:** ✅ COMPLETADO si:
- Código es leíble
- No hay technical debt visible
- Setup es fácil
- Todo funciona

---

# 📚 LUNES 18 NOV - TESTING + DOCUMENTACIÓN FINAL

## Objetivo: Entregable profesional y documentado

### Bloque 1: 08:00 - 10:00 (2h) - README COMPLETO

**Archivo:** `README.md`

```markdown
# 🚀 AUTOPRÉSTAMOS - Universidad Simón Bolívar

## Descripción
Sistema de control de autopréstamos de equipos para la biblioteca.

## Requisitos
- PHP 7.4+
- MySQL 5.7+
- Composer
- WebSocket support

## Instalación (5 minutos)

### Linux/Mac
\`\`\`bash
bash install.sh
\`\`\`

### Windows
\`\`\`powershell
.\install.ps1
\`\`\`

### Manual
1. Copiar `.env.example` → `.env`
2. Editar `.env` con credenciales
3. Crear BD: `mysql -u root < database/schema.sql`
4. Instalar dependencias: `cd servers && composer install`

## Iniciar Sistema

### Terminal 1: WebSocket Server
\`\`\`bash
php servers/server.php
\`\`\`

### Terminal 2: Web Server
\`\`\`bash
# Si usas XAMPP, solo abrir navegador
http://localhost/dashboard-unisimon/
\`\`\`

## Estructura
- `servers/` - WebSocket server (Ratchet)
- `prueba_equipos/` - API REST
- `dashboard-unisimon/` - Frontend web
- `database/` - Schema y scripts

## API Reference
Ver [API.md](./API.md)

## Troubleshooting
Ver [TROUBLESHOOTING.md](./TROUBLESHOOTING.md)

## Licencia
Uso interno Universidad Simón Bolívar
```

**Checklist:**
- [ ] README explica qué es el sistema
- [ ] Requisitos claros
- [ ] Instalación en <5 min
- [ ] Cómo iniciar
- [ ] Dónde encontrar documentación
- [ ] Screenshot del dashboard

**Tiempo estimado:** 2 horas  
**Riesgo:** Bajo - solo documentación  
**Rollback:** N/A

---

### Bloque 2: 10:00 - 12:00 (2h) - API DOCUMENTATION

**Archivo:** `API.md`

```markdown
# API Documentation

## Base URL
\`http://localhost/prueba_equipos/api.php\`

## Authentication
Todos los requests requieren JWT token.

## Endpoints

### 1. Login (POST)
Request:
\`\`\`json
{
  "username": "usuario@unisimon.edu.co",
  "mac_address": "00:1A:2B:3C:4D:5E",
  "tipo": "control"
}
\`\`\`

Response:
\`\`\`json
{
  "estado": "Abierto",
  "token": "eyJhbGc...",
  "sessionId": "123"
}
\`\`\`

### 2. Status (GET)
\`http://localhost/api.php?username=X&mac_address=Y&tipo=status\`

Response:
\`\`\`json
{
  "estado": "Abierto",
  "tiempoRestante": 1200,
  "equipo": "PC-LAB-01"
}
\`\`\`

...
```

**Checklist:**
- [ ] Todos los endpoints documentados
- [ ] Ejemplos de request/response
- [ ] Códigos de error explicados
- [ ] Notas sobre rate limiting
- [ ] Ejemplos con curl

**Tiempo estimado:** 2 horas  
**Riesgo:** Bajo  
**Rollback:** N/A

---

### Bloque 3: 12:00 - 14:00 (2h) - SCHEMA DE BD

**Archivo:** `database/schema.sql`

**Checklist:**
- [ ] Exportar schema actual:
  ```bash
  mysqldump -u root -p --no-data autoprestamo > database/schema.sql
  ```
- [ ] Documento describe cada tabla
- [ ] Índices documentados
- [ ] Foreign keys claros
- [ ] Ejemplo de inserts de test

**Validación:**
```bash
# Crear BD nueva desde schema
mysql -u root < database/schema.sql
# Verificar que se crea correctamente
mysql -u root -e "USE autoprestamo; SHOW TABLES;"
```

**Tiempo estimado:** 2 horas  
**Riesgo:** Bajo  
**Rollback:** N/A

---

### Bloque 4: 14:00 - 15:30 (1.5h) - TESTS UNITARIOS

**Archivo:** `tests/APITest.php`

**Checklist:**
- [ ] Copiar de BOILERPLATE
- [ ] Crear tests para:
  - [ ] Validación de username
  - [ ] Validación de MAC
  - [ ] JWT generate/verify
  - [ ] Rate limiter
- [ ] Ejecutar tests:
  ```bash
  php tests/APITest.php
  ```
- [ ] Verificar 100% pasan
  - [ ] ✅ testValidUsername
  - [ ] ✅ testValidMacAddress
  - [ ] ✅ testJWTGenerate
  - [ ] ✅ testJWTVerify

**Tiempo estimado:** 1.5 horas  
**Riesgo:** Bajo  
**Rollback:** Remover tests (pero no es necesario)

---

### Bloque 5: 15:30 - 16:30 (1h) - VALIDACIÓN FINAL

**Checklist de Calidad:**

✅ **Seguridad:**
- [ ] SQL injection imposible (prepared statements)
- [ ] XSS imposible (output escaping)
- [ ] CSRF protegido (validar origen)
- [ ] OWASP Top 10 cubiertos

✅ **Performance:**
- [ ] API responde <200ms
- [ ] Dashboard carga <2s
- [ ] WebSocket latency <50ms
- [ ] Memory no crece indefinidamente

✅ **Confiabilidad:**
- [ ] Server aguanta 1 hora sin crash
- [ ] Logs capturan todos los errores
- [ ] Reconexión automática funciona
- [ ] BD está intacta después de crash

✅ **Documentación:**
- [ ] README claro
- [ ] API documentada
- [ ] Schema claro
- [ ] Setup fácil

✅ **Código:**
- [ ] Sin console.log en producción
- [ ] Sin var_dump en API
- [ ] Indentación consistente
- [ ] Comentarios útiles
- [ ] Sin código duplicado

**Validación Manual:**
```bash
# 1. Iniciar sistema
terminal1: php servers/server.php
terminal2: open http://localhost/dashboard-unisimon/

# 2. Conectar cliente
# Via PowerShell, conectar con token

# 3. Monitorear logs
terminal3: tail -f logs/autoprestamo.log

# 4. Probar scenarios:
- [ ] Conectar/desconectar
- [ ] Enviar comandos
- [ ] Ver en dashboard
- [ ] Matar servidor y reconectar
- [ ] Rate limit (101 requests)
- [ ] Validar entrada inválida
```

**Tiempo estimado:** 1 hora  
**Riesgo:** Bajo - es solo verificación  
**Rollback:** N/A

---

## 📊 REPORTE FINAL (Lunes 18 NOV)

**✅ Checklist de Entrega:**

- [ ] Código compilable sin errores
- [ ] Tests al 100%
- [ ] Documentación completa
- [ ] README funciona
- [ ] Install scripts funciona
- [ ] Schema de BD disponible
- [ ] API documentada
- [ ] Logs funcionan
- [ ] Seguridad hardeneada
- [ ] Server confiable
- [ ] Código limpio

**📊 Métricas Finales:**

| Métrica | Before | After | ✅ |
|---------|--------|-------|-----|
| Funcionalidad | 70% | 85% | ✅ |
| Seguridad | 30% | 85% | ✅ |
| Documentación | 5% | 100% | ✅ |
| Testing | 0% | 60% | ✅ |
| Confiabilidad | 40% | 85% | ✅ |
| Mantenibilidad | 35% | 80% | ✅ |

**🎯 Commit Final:**
```bash
git add .
git commit -m "✅ Versión 1.0 - Lista para producción"
git tag -a v1.0 -m "Release estable - 18 NOV 2025"
git push --all
```

**🚀 ENTREGA:**
- Nombre: `autoprestamos-v1.0-18NOV2025.zip`
- Contenido:
  - Código fuente completo
  - README.md
  - DOCUMENTACIÓN.md
  - API.md
  - database/schema.sql
  - install.sh + install.ps1
  - logs de test

---

# 🎁 BONUS - SI TERMINAS ANTES

### Nice to have (si sobra tiempo):

1. **Docker Compose** (30 min)
   - Dockerfile para PHP
   - docker-compose.yml con MySQL + PHP
   - `.dockerignore`

2. **PM2 Configuration** (20 min)
   - `ecosystem.config.js`
   - Auto-restart on crash
   - Log aggregation

3. **Swagger UI** (1 hora)
   - Generar OpenAPI spec
   - Swagger UI en `/swagger`
   - Interfaz visual para API

4. **GitHub Actions CI** (1.5 horas)
   - Tests automáticos on push
   - Linting
   - Code coverage

5. **Monitoring Dashboard** (2 horas)
   - Métricas en tiempo real
   - Alertas de estrés

---

# ⚠️ CONTINGENCIES - SI ALGO FALLA

### Si FOLIO API falla:
- [ ] Usar mock de FOLIO en desarrollo
- [ ] Cachear respuestas de FOLIO
- [ ] Continuar con datos fallback

### Si BD se corrompe:
- [ ] Restaurar desde backup
- [ ] Usar schema.sql para recrear
- [ ] Continue con datos ficticios

### Si WebSocket no conecta:
- [ ] Revisar puerto 8081 en uso
- [ ] Cambiar a puerto alternativo
- [ ] Usar polling como fallback

### Si falta tiempo:
- [ ] SKIP: Docker, CI/CD, Swagger
- [ ] KEEP: Seguridad, Docs, Tests
- [ ] FOCUS: Funcionalidad crítica

---

# 📞 SOPORTE DURANTE DESARROLLO

Si necesitas ayuda:

1. **Errores de PHP:** Ver `/logs/autoprestamo.log`
2. **Errores de JS:** Ver browser DevTools (F12)
3. **Errores de BD:** Ver MySQL error log
4. **WebSocket:** Ver stdout del server

**Preguntas para ChatGPT:**
```
Estoy en AUTOPRÉSTAMOS, día [X de 7].
Tengo problema con [PROBLEMA].
Mensaje de error: [ERROR].
Ya intenté: [QUÉ INTENTASTE].
¿Qué hago?
```

---

**Generated:** 11 NOV 2025  
**Next Review:** Después de cada bloque de 2h  
**Final Delivery:** 18 NOV 2025 23:59 (Lunes)

🚀 **¡ADELANTE, TÚ PUEDES!**

