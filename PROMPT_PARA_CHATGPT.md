# 🎯 PROMPT GUÍA PARA CHATGPT - PLAN DE CULMINACIÓN AUTOPRÉSTAMOS

## INSTRUCCIONES PARA CHATGPT

Copia y pega el siguiente prompt en ChatGPT para obtener un plan detallado de trabajo:

---

## 📋 CONTEXTO DEL PROYECTO

Soy desarrollador de un **sistema de autopréstamos de equipos** para la **Universidad Simón Bolívar**. 

El proyecto está construido con:
- **Backend**: PHP con WebSocket (Ratchet) y MySQL
- **Frontend**: Vanilla JavaScript + Bootstrap 5
- **Arquitectura**: API REST + Comunicación en tiempo real
- **Status**: 70% funcional pero con deuda técnica

**PLAZO CRÍTICO**: 18 de Noviembre de 2025 (7 días desde hoy 11 de Noviembre)

---

## 🔴 PROBLEMAS CRÍTICOS A RESOLVER

1. **SEGURIDAD**:
   - API sin validación de entrada consistente
   - WebSocket sin autenticación
   - Sin rate limiting
   - Posible SQL injection

2. **CONFIABILIDAD**:
   - Memory leaks en servidor WebSocket
   - Sin manejo de errores robusto
   - Sin retry logic en conexiones
   - Pérdida de estado al reiniciar

3. **MANTENIBILIDAD**:
   - JavaScript con 868 líneas sin modularización
   - Código duplicado
   - Sin documentación
   - Sin tests

4. **OPERACIONALIDAD**:
   - Sin variables de entorno (.env)
   - Servidor manual en port 8081
   - Sin logs auditables
   - Sin script de setup

---

## 📊 RECURSOS DISPONIBLES

- **Equipo**: Solo yo (1 dev full-stack)
- **Tecnologías permitidas**: PHP, JS, MySQL, Docker (opcional)
- **Infraestructura**: XAMPP local + posible servidor Linux
- **Tiempo**: 7 días (168 horas - realista: ~56 horas útiles)

---

## ✅ OBJETIVOS PARA EL 18 DE NOVIEMBRE

1. **Seguridad**: Sistema hardened contra inyecciones y accesos no autorizados
2. **Estabilidad**: Servidor puede reiniciar sin perder control
3. **Documentación**: Cualquiera puede hacer onboarding en <1 hora
4. **Testing**: Suite de pruebas que validen funcionalidad crítica
5. **Deployment**: Scripts one-click para instalar y ejecutar

---

## 🎯 SOLICITUD

Por favor, dame un **plan de acción detallado paso a paso** que incluya:

### 1. **ANÁLISIS PRIORIZACIÓN** (5 min)
   - Ordena los problemas por impacto vs. esfuerzo
   - Identifica bloquers vs. nice-to-have
   - Sugiere qué NO hacer

### 2. **DESGLOSE POR DÍA** (4 días = viernes a lunes)
   - Para cada día, dame:
     - Objetivos específicos (SMART)
     - Archivos a modificar
     - Funciones/componentes clave
     - Checklist de validación
     - Riesgos potenciales

### 3. **ESPECIFICACIÓN TÉCNICA**
   - Código boilerplate para patrones comunes
   - Ejemplos de validación
   - Scripts de test
   - Estructura de logging

### 4. **ESTRATEGIA DE TESTING**
   - Qué es crítico testear
   - Escenarios de prueba manual
   - Criterios de aceptación

### 5. **PLAN B** (contingencia)
   - Si hay problemas en BD
   - Si hay problemas con FOLIO API
   - Si necesito reducir scope

### 6. **CHECKLIST FINAL**
   - Validación antes de entregar
   - Pruebas de regresión
   - Performance baseline

---

## 📝 INFORMACIÓN ADICIONALs DEL PROYECTO

   **Estructura:**
   ```
   autoprestamos/
   ├── servers/               # Servidor WebSocket
   │   ├── server.php
   │   ├── composer.json
   │   └── vendor/
   ├── prueba_equipos/       # API backend
   │   ├── api.php           # Endpoint principal
   │   ├── auth.php          # Autenticación
   │   ├── db.php            # Conexión BD
   │   ├── utils.php         # Helpers
   │   └── status.php        # Obtencion de estados
   │   └── folio.php         # Integración FOLIO
   │   └── tokenByron.php    # Token de acceso a FOLIO
   │   └── win-server.txt    # shell que ejecuta el cliente al iniciar secion
   └── dashboard-unisimon/   # Frontend web
      ├── dashboard_action.php   # Manejo de acciones en el dashboard
      ├── dashboard_stats.php    # Obtencion de los estados al dashboard
      ├── dashboard.php          # Dashboard principal
      ├── assets/
      │   ├── css/dashboard.css  # Codigo de css
      │   └── js/
      │       ├── dashboard.js  (868 líneas!) # Manejo del js del dashboard
      │       └── websocket.js                # Conexion yhacia el servidor
      ├── db.php
      └── get_sesiones.php       # Obtencion de las sesiones a mostrar al dashboard
   ```

**Dependencias:**
- cboden/ratchet: ^0.4.4 (WebSocket)

**BD:**
- Servidor: localhost
- Usuario: root (sin contraseña)
- BD: autoprestamo

**Endpoints principales:**
- GET `api.php?username=X&mac_address=Y&tipo=control`
- WebSocket: `ws://localhost:8081`

**Flujo:**
1. Equipo se conecta vía WebSocket
2. Usuario inicia sesión en equipoando en API
3. Dashboard monitorea sesiones en tiempo real
4. Servidor envía comandos a equipos conectados

---

## 🎁 BONUS: PREGUNTAS A RESOLVER

Si tienes tiempo, dame respuestas a:
1. ¿Cuál es el mejor patrón para manejar reconexión automática en WebSocket?
2. ¿Cómo implementar logs centralizados sin sobrecargar la BD?
3. ¿Cuál es la mínima suite de tests que cubre 80% de casos?
4. ¿Cómo hacer debugging remoto si falla en producción?
5. ¿Cuál es el mejor lugar para agregar autenticación JWT?

---

## 🎯 RESULTADO ESPERADO

Un **documento ejecutivo de ~2,000 palabras** con:
- [ ] Diagrama de prioridades
- [ ] Desglose diario (hora x hora si es posible)
- [ ] Código ready-to-use para patrones
- [ ] Tests específicos a ejecutar
- [ ] Métricas de éxito
- [ ] Lista de "no hacer" para no perder tiempo

---

## ⏱️ FORMATO ESPERADO

Estructura tu respuesta así:

```
## 📊 PRIORIZACIÓN (Matriz Impacto vs Esfuerzo)
[Tabla o gráfico]

## 📅 PLAN DÍA POR DÍA
### Día 1 (Viernes): [Objetivo]
- Bloque 1 (2h): ...
- Bloque 2 (2h): ...
- Validación: ...

### Día 2 (Sábado): ...
...

## 🔧 BOILERPLATE DE CÓDIGO
[Snippets listos para copiar-pegar]

## ✅ CHECKLIST FINAL
[Lista de validación]
```

---

**¿Listo? Adelante con el plan! 🚀**

