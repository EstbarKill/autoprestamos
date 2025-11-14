// websocket.js

/* global conectarWS, ws, mostrarToast, actualizarTabla, actualizarStats, agregarLog, verificarServidor */
let ws = null;
// Reintentos y flags de reconexión
window.manualDisconnect = false; // true si el admin pidió desconectar
window.reconnectAttempts = 0;
window.MAX_RECONNECT = 5;
window.RECONNECT_BASE_DELAY = 2000; // ms
window.reconnecting = false;

const HEARTBEAT_INTERVAL = 10000; // 8 segundos - intervalo único de actualización

window.conectarWS = async function () {
  const btn = document.querySelector("#toggleBtn");
  const dot = document.querySelector("#statusDot");
  if (ws && ws.readyState === WebSocket.OPEN) {
    return true;
  }

  const servidorActivo = await verificarServidor();
  if (!servidorActivo) {
    mostrarToast("⚠️ Servidor WebSocket apagado");
    return false;
  }

  try {
    // Obtener sede seleccionada desde localStorage
    const sedeGuardada = localStorage.getItem("sede_seleccionada");
    if (!sedeGuardada) {
      mostrarToast('⚠️ Debes seleccionar un punto de servicio en el desplegable antes de conectar');
      return false;
    }

    // Mapear ID de sede a nombre
    const sedeNombres = {
      "1": "Biblioteca Central José Martí Sede 1",      
      "2": "Hemeroteca Ana Bolivar de Consuegra",
      "3": "Biblioteca de Posgrado (Barranquilla)"
    };
    const sedeNombre = sedeNombres[sedeGuardada] || `Sede ${sedeGuardada}`;

    return await new Promise((resolve) => {
      ws = new WebSocket("ws://localhost:8081");

      ws.onopen = async () => {
        // Reseteamos flags de reconexión al abrir correctamente
        try {
          window.reconnectAttempts = 0;
          window.reconnecting = false;
          window.manualDisconnect = false;
        } catch (e) {}
        // Verificar nuevamente el estado del servidor al abrir el socket
        try {
          const estado = await verificarServidor();
          if (!estado || (estado.status && estado.status !== 'corriendo')) {
            console.warn('Servidor WS no activo en onopen, cerrando socket. Estado:', estado);
            mostrarToast('⚠️ El servidor WebSocket no parece estar activo tras abrir conexión', 'warning');
            try { ws.close(); } catch (e) {}
            resolve(false);
            return;
          }
        } catch (e) {
          console.error('Error verificando servidor en onopen:', e);
          mostrarToast('⚠️ No se pudo verificar el estado del servidor tras abrir conexión', 'warning');
          try { ws.close(); } catch (err) {}
          resolve(false);
          return;
        }

        // Registrar dashboard usando la sede seleccionada del desplegable
        btn.textContent = "Desconectar";
        btn.classList.remove("btn-outline-danger", "btn-warning");
        btn.classList.add("btn-success");
        if (dot) dot.style.background = "green";
        mostrarToast("🟢 Conectado al servidor WebSocket");

        ws.send(
          JSON.stringify({
            tipo: "registro",
            origen: "dashboard",
            nombre_equipo: "Admin_" + sedeNombre,
            id_p_servicio: parseInt(sedeGuardada),
            nombre_p_servicio: sedeNombre,
          })
        );

        resolve(true);
      };

      ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);
          switch (data.tipo) {
            case "estado":
              actualizarTabla(data.sesiones || []);
              if (data.stats) actualizarStats(data.stats);
              break;
            case "mensaje":
              // Mensajes del sistema: registrar en log, evitar toasts para reducir ruido
              agregarLog("💬 " + (data.texto || data.mensaje), "info");
              break;
            case "log":
              agregarLog(data.mensaje, "success");
              break;
            case "comando":
              // Registrar ejecución de comandos en el log; evitar toast por cada comando
              agregarLog(`⚙️ Comando '${data.accion}' ejecutado en ${data.nombre_pc}`, "info");
              break;
            case "equipo_desconectado":
              // Registrar en log; si es necesario, dashboard puede mostrar resumen
              agregarLog(`🔌 Equipo desconectado: ${data.nombre_pc}`, "warning");
              break;
            case "confirmacion":
              nombre_eq = data.nombre_eq;
              accionSesion = data.accion;
              resultadoSesion = data.resultado;
              origen = data.origen;
              if (origen == "server") {
                // Confirmaciones desde el servidor: mostrar toast para acciones críticas
                if (data.accion === 'finalizar' || data.accion === 'bloquear') {
                  mostrarToast(`✅ ${data.nombre_eq}: ${data.accion} => ${data.resultado}`, "success");
                }
                agregarLog(`Confirmación: ${data.nombre_eq} ${data.accion} ${data.resultado}`, "success");
                console.log("✅ Confirmación recibida:", data);
                // refrescar estado
                ws.send(JSON.stringify({ tipo: "actualizar", origen: "dashboard" }));
              } else if (origen == "equipo") {
                // Confirmaciones desde equipo: loguear y refrescar, sin toast
                agregarLog(`Confirmación desde equipo: ${data.nombre_eq} ${data.accion} ${data.resultado}`, "success");
                console.log("✅ Confirmación desde equipo recibida:", data);
                ws.send(JSON.stringify({ tipo: "actualizar", origen: "dashboard" }));
              }
              break;
            case "error":
              mostrarToast("❌ " + data.mensaje, "danger");
              agregarLog("❌ " + data.mensaje, "error");
              break;
            case "equipos_conectados":
              // Información informativa, registrar en log
              agregarLog(`🖥️ Equipos conectados: ${data.cantidad}`, "info");
              break;
            case "confirmacion_registro":
              // Registrar en log; no mostrar toast para registro automático
              agregarLog(`✅ Registro exitoso: ${data.nombre_eq}`, "success");
              break;
            case "cambio_estado":
              // Notificación de cambio de estado de sesión (ej. hibernación)
              const estadoNuevo = data.estado_nuevo || "Desconocido";
              const nombreEquipo = data.nombre_equipo || "Equipo desconocido";
              const razon = data.razon ? ` (${data.razon})` : "";
              
              // Mostrar toast según el nuevo estado
              let tipoToast = "info";
              let icono = "ℹ️";
              if (estadoNuevo === "Hibernado") {
                tipoToast = "warning";
                icono = "😴";
              } else if (estadoNuevo === "Finalizado") {
                tipoToast = "danger";
                icono = "⛔";
              }
              
              // Cambio de estado visible: toast y log
              mostrarToast(`${icono} ${nombreEquipo} → ${estadoNuevo}${razon}`, tipoToast);
              agregarLog(`${icono} ${nombreEquipo} cambió a estado: ${estadoNuevo}${razon}`, tipoToast === 'danger' ? 'error' : 'warning');
              
              // Refrescar tabla de sesiones
              ws.send(
                JSON.stringify({
                  tipo: "actualizar",
                  origen: "dashboard",
                })
              );
              break;
            default:
              console.log("📡 Mensaje no manejado:", data);
          }
        } catch (err) {
          console.error("❌ Error parseando mensaje:", err, event.data);
        }
      };

      ws.onerror = (err) => {
        console.error("⚠️ Error WebSocket:", err);
        mostrarToast("❌ Error de conexión WebSocket", "danger");
        // Si hay un error antes de open, resolver como fallo
        resolve(false);
      };

      ws.onclose = async (event) => {
        // Notificar cierre una sola vez
        mostrarToast("🔴 Conexión WebSocket cerrada", "warning");
        // Limpiar flag de conexión cuando se cierre
        localStorage.setItem("dashboard_conectado", "false");

        // Si la desconexión fue solicitada manualmente por el admin, no reconectar
        if (window.manualDisconnect) {
          console.log("ℹ️ Conexión WebSocket cerrada por solicitud manual. No se reconecta.");
          window.reconnecting = false;
          return;
        }

        // Inicio de reintentos automáticos cuando el cierre fue inesperado
        if (!window.reconnecting) window.reconnecting = true;

        // Intentar reconectar hasta MAX_RECONNECT veces
        const attemptReconnect = async () => {
          window.reconnectAttempts = (window.reconnectAttempts || 0) + 1;
          const attempt = window.reconnectAttempts;
          if (attempt > window.MAX_RECONNECT) {
            console.warn(`🔴 No fue posible reconectar después de ${window.MAX_RECONNECT} intentos.`);
            mostrarToast(`🔴 No se pudo reconectar al servidor después de ${window.MAX_RECONNECT} intentos`, 'danger');
            window.reconnecting = false;
            try { if (typeof mostrarDesconectado === 'function') mostrarDesconectado(); } catch(e){}
            return;
          }

          const delay = window.RECONNECT_BASE_DELAY * attempt; // backoff lineal
          // Registrar intento en log en lugar de mostrar toast cada vez
          console.log(`🔁 Intento de reconexión ${attempt}/${window.MAX_RECONNECT} en ${delay/1000}s...`);
          agregarLog(`🔁 Intento de reconexión ${attempt}/${window.MAX_RECONNECT}`, 'warning');

          setTimeout(async () => {
            try {
              const ok = await conectarWS();
              if (ok) {
                mostrarToast(`🟢 Reconectado correctamente en el intento ${attempt}`,'success');
                agregarLog(`🟢 Reconectado en intento ${attempt}`,'success');
                window.reconnecting = false;
                window.reconnectAttempts = 0;
                return;
              } else {
                console.warn(`Intento ${attempt} fallido`);
                // programar siguiente intento
                attemptReconnect();
              }
            } catch (err) {
              console.error('Error en intento de reconexión:', err);
              attemptReconnect();
            }
          }, delay);
        };

        // Iniciar primer intento
        attemptReconnect();
      };
    });
  } catch (error) {
    console.error("❌ Error al conectar WebSocket:", error);
    mostrarToast("❌ Error de conexión WebSocket", "danger");
    return false;
  }
};

// Heartbeat: pedir estado cada HEARTBEAT_INTERVAL
setInterval(() => {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(
      JSON.stringify({
        tipo: "actualizar",
        origen: "dashboard",
      })
    );
  }
}, HEARTBEAT_INTERVAL);