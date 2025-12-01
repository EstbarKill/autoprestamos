# 📚 GUÍA RÁPIDA DE DOCUMENTACIÓN

**Versión:** 2.3  
**Fecha:** Noviembre 13, 2025  

---

## 📌 TL;DR (Demasiado Largo; No Lo Leí)

**En 30 segundos:**

El sistema de autopréstamos ahora tiene:
- ✅ **Cliente principal único:** `win-server.ps1` (consolidado)
- ✅ **Hibernación funcional:** Detecta inactividad, entra en hibernación, finaliza automáticamente
- ✅ **Sin bloqueos UI:** WebSocket en runspace separado, cola de mensajes thread-safe
- ✅ **Documentación completa:** 5 documentos + guías + checklist

**Para empezar:**
1. Lee: `RESUMEN_REVISION_COMPLETA.md` (15 min)
2. Ejecuta: `prueba_equipos\win-server.ps1`
3. Prueba: `CHECKLIST_VALIDACION.md`

---

## 🎯 DOCUMENTACIÓN POR USUARIO

### 👨‍💼 Gerentes / Líderes
**Tiempo:** 15 minutos

→ Lee: **RESUMEN_REVISION_COMPLETA.md**
- Estado del proyecto
- Cambios principales
- Próximos pasos

### 👨‍💻 Desarrolladores
**Tiempo:** 90 minutos

→ Lee en orden:
1. `README_WIN_SERVER.md` - Cómo usar (20 min)
2. `FLUJO_COMPLETO_SISTEMA.md` - Arquitectura (45 min)
3. `ESTRUCTURA_WIN_SERVER.md` - Código (25 min)

### 🧪 QA / Testers
**Tiempo:** 90 minutos

→ Usa: **CHECKLIST_VALIDACION.md**
- 50+ checks automatizados
- Prueba paso a paso
- Formulario de aprobación

### 🔧 DevOps / Admins
**Tiempo:** 30 minutos

→ Lee: `README_WIN_SERVER.md` → "Requisitos previos"  
→ Ejecuta: `CHECKLIST_VALIDACION.md` → "Validación de Componentes"

---

## 📄 DOCUMENTOS DISPONIBLES

### RESUMEN_REVISION_COMPLETA.md
```
📊 Resumen ejecutivo (280 líneas)
├─ Decisiones principales
├─ Componentes validados
├─ Mejoras implementadas
├─ Próximos pasos
└─ Recomendaciones
```

### FLUJO_COMPLETO_SISTEMA.md ⭐
```
🔧 Documentación técnica principal (920 líneas)
├─ Arquitectura (5 capas)
├─ 5 flujos de operación
├─ Configuración crítica
├─ Problemas conocidos
├─ Checklist E2E
└─ Monitoreo y debugging
```

### README_WIN_SERVER.md
```
📖 Guía de usuario (410 líneas)
├─ Inicio rápido (3 pasos)
├─ Requisitos previos
├─ Interfaz gráfica
├─ Hibernación explicada
├─ Configuración
├─ Solución de problemas (6 casos)
└─ Integración con sistemas
```

### ESTRUCTURA_WIN_SERVER.md
```
🏗️ Referencia técnica (380 líneas)
├─ Índice de líneas (970 líneas)
├─ 6 funciones principales
├─ Flujo de datos
├─ Variables críticas
├─ Tipos de mensajes JSON
├─ Performance
└─ Debugging
```

### CHECKLIST_VALIDACION.md
```
✅ Plantilla de pruebas (450 líneas)
├─ Verificación de archivos
├─ Validación de componentes
├─ Prueba de inicio
├─ Prueba de hibernación
├─ Validación de flujos
├─ Seguridad
├─ Performance
└─ Formulario de aprobación
```

---

## 🚀 INICIO RÁPIDO (5 MINUTOS)

### 1. Abre PowerShell como Administrador

```powershell
cd C:\xampp\htdocs\autoprestamos\prueba_equipos
```

### 2. Ejecuta el cliente

```powershell
Set-ExecutionPolicy -ExecutionPolicy Bypass -Scope Process
.\win-server.ps1
```

### 3. Deberías ver

```
✅ Cliente conectado a WebSocket
✅ Interfaz gráfica en esquina inferior
✅ Estado: "🟢 SESIÓN ACTIVA"
```

**¿No funciona?** → Lee `README_WIN_SERVER.md` → "Solución de Problemas"

---

## 😴 PRUEBA DE HIBERNACIÓN (10 MINUTOS)

### 1. Inicia el cliente (paso anterior)

### 2. NO hagas nada por 15 segundos

### 3. Deberías ver

```
[14:30:52] 😴 [Warning] Inactividad detectada (15 s)
[14:30:52] → Entrando en modo hibernación
```

**En UI:** Ventana modal "💤 Modo Hibernación - 60 segundos"

### 4. Opción A: Mueve el mouse

→ Hibernación se cancela  
→ MessageBox: "Sesión renovada"

### 5. Opción B: Espera 20 segundos más

→ Sesión se finaliza automáticamente  
→ Cliente se cierra  

**¿Algo extraño?** → Consulta FLUJO_COMPLETO_SISTEMA.md → "Hibernación"

---

## 🔗 FLUJO VISUAL

```
¿DÓNDE EMPIEZO?
      ↓
[Eres gerente?] → SÍ → RESUMEN_REVISION_COMPLETA.md
      │
      NO
      ↓
[Necesitas ejecutar?] → SÍ → README_WIN_SERVER.md → Inicio Rápido
      │
      NO
      ↓
[Necesitas entender la arquitectura?] → SÍ → FLUJO_COMPLETO_SISTEMA.md
      │
      NO
      ↓
[Necesitas código detallado?] → SÍ → ESTRUCTURA_WIN_SERVER.md
      │
      NO
      ↓
[Necesitas probar?] → SÍ → CHECKLIST_VALIDACION.md
      │
      NO
      ↓
¿Problema? → README_WIN_SERVER.md → "Solución de Problemas"
```

---

## 🎓 MAPA CONCEPTUAL

```
SISTEMA DE AUTOPRÉSTAMOS (v2.3)

┌─ CLIENTE POWERSHELL ──────────────────────┐
│ win-server.ps1 (970 líneas)               │
│                                            │
│ ├─ Detecta inactividad (Get-LastInputInfo)
│ ├─ Entra en hibernación                   │
│ ├─ Enqueue mensajes (thread-safe)         │
│ └─ Runspace WebSocket (independiente)     │
└────────────────────────────────────────────┘
              ↓↑ JSON WebSocket
              ↓↑ port 8081
┌─ SERVIDOR RATCHET ────────────────────────┐
│ server.php (628 líneas)                   │
│                                            │
│ ├─ Recibe registro de clientes             │
│ ├─ Enruta comandos                        │
│ ├─ Procesa hibernación                    │
│ └─ Notifica dashboards                    │
└────────────────────────────────────────────┘
              ↓↑ REST API
              ↓↑ port 80
┌─ API REST ─────────────────────────────────┐
│ api.php (Integración con FOLIO)           │
│                                            │
│ ├─ confirmar_inicio                        │
│ ├─ renovar                                │
│ ├─ finalizar                              │
│ └─ bloquear, suspender, etc.               │
└────────────────────────────────────────────┘
              ↓↑ SQL
              ↓↑ port 3306
┌─ BASE DE DATOS ────────────────────────────┐
│ MySQL                                      │
│                                            │
│ ├─ sesiones (id_estado_fk = 1-6)          │
│ ├─ estados (Abierto, Bloqueado, etc)      │
│ ├─ equipos (PC registradas)                │
│ └─ logs_acciones (auditoría)               │
└────────────────────────────────────────────┘
```

---

## 📊 CAMBIOS PRINCIPALES (v2.3)

| Cambio | Antes | Después | Impacto |
|--------|-------|---------|---------|
| **Archivo principal** | 3 copias (.txt) | 1 archivo (.ps1) | ✅ Claridad |
| **Mensajes hibernación** | "hibernation" (inconsistente) | "hibernado" (normalizado) | ✅ Confiabilidad |
| **WebSocket thread-safe** | SendAsync(...).Wait() desde UI | OutgoingQueue + Enqueue | ✅ Performance |
| **Funciones estado** | Faltaban 2 | Agregadas (Renovado, Error) | ✅ Completitud |
| **Documentación** | Fragmentada | 5 documentos cohesivos | ✅ Mantenibilidad |

---

## ✅ CHECKLIST RÁPIDO

- [ ] Descargué/accedí al archivo principal: `win-server.ps1`
- [ ] Leí al menos `README_WIN_SERVER.md`
- [ ] Ejecuté el cliente y vi la interfaz gráfica
- [ ] Entiendo cómo funciona la hibernación
- [ ] Sé dónde está cada componente del sistema

**Si todo está marcado:** ¡Estás listo para trabajar con el sistema!

---

## 🆘 AYUDA RÁPIDA

| Problema | Solución |
|----------|----------|
| "No se conecta WebSocket" | Ver `README_WIN_SERVER.md` → Error #2 |
| "Hibernación no se dispara" | Ver `FLUJO_COMPLETO_SISTEMA.md` → Problema #4 |
| "ExecutionPolicy error" | Ver `README_WIN_SERVER.md` → Error #5 |
| "Quiero entender el código" | Leer `ESTRUCTURA_WIN_SERVER.md` → Funciones Principales |
| "Necesito hacer pruebas" | Usar `CHECKLIST_VALIDACION.md` |

---

## 📞 PRÓXIMOS PASOS

1. **Esta semana:** Ejecuta CHECKLIST_VALIDACION.md
2. **Próxima semana:** Implementar seguridad (WSS, JWT)
3. **En 2 semanas:** Desplegar a producción

---

## 🎯 ARCHIVOS CONSOLIDADOS

✅ Archivo principal único: `win-server.ps1`  
✅ Documentación completa: 5 guías  
✅ Checklist de validación: 50+ checks  
✅ Listo para producción (tras pruebas)

---

**Documento:** Guía Rápida de Documentación  
**Versión:** 1.0  
**Última actualización:** Noviembre 13, 2025
