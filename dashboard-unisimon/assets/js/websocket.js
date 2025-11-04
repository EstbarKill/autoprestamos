// websocket.js (reemplazar)

/* global conectarWS, ws, mostrarToast, actualizarTabla, actualizarStats, agregarLog, verificarServidor */
let ws = null;
let reintentos = 0;
const MAX_REINTENTOS = 5;
const INTERVALO_REINTENTO = 3000;

window.conectarWS = async function() {
    const btn = document.querySelector("#toggleBtn");
    const dot = document.querySelector("#statusDot");

    if (ws && ws.readyState === WebSocket.OPEN) {
        mostrarToast("🟢 Ya conectado al servidor WebSocket");
        return;
    }

    const servidorActivo = await verificarServidor();
    if (!servidorActivo) {
        mostrarToast("⚠️ Servidor WebSocket apagado");
        return;
    }

    try {
        ws = new WebSocket("ws://localhost:8081");

        ws.onopen = () => {
            reintentos = 0;
            btn.textContent = "Desconectar";
            btn.classList.remove("btn-outline-danger", "btn-warning");
            btn.classList.add("btn-success");
            if (dot) dot.style.background = "green";
            mostrarToast("🟢 Conectado al servidor WebSocket");

            // registrar dashboard y pedir estado
            ws.send(JSON.stringify({
                tipo: "registro",
                nombre_equipo: "Admin_USB_" + Date.now(),
                origen: "dashboard"    
            }));
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
                        mostrarToast("💬 " + (data.texto || data.mensaje), "info");
                        agregarLog("💬 " + (data.texto || data.mensaje), "info");
                        break;
                    case "log":
                        agregarLog(data.mensaje, "success");
                        break;
                    case "comando":
                        mostrarToast(`⚙️ Comando '${data.accion}' ejecutado en ${data.nombre_pc}`, "info");
                        agregarLog(`⚙️ Comando '${data.accion}' ejecutado en ${data.nombre_pc}`, "info");
                        break;
                    case "equipo_desconectado":
                        mostrarToast(`🔌 Equipo desconectado: ${data.nombre_pc}`, "warning");
                        agregarLog(`🔌 Equipo desconectado: ${data.nombre_pc}`, "warning");
                        break;
                    case "confirmacion":
                        mostrarToast(`✅ ${data.id}: ${data.accion} => ${data.resultado}`, "success");
                        agregarLog(`Confirmación: ${data.id} ${data.accion} ${data.resultado}`, "success");
                        // refrescar estado si es necesario
                        setTimeout(()=> ws.send(JSON.stringify({
                            tipo: "actualizar",
                            origen: "dashboard"
                        })));
                        break;
                    case "error":
                        mostrarToast("❌ " + data.mensaje, "danger");
                        agregarLog("❌ " + data.mensaje, "error");
                        break;
                    case "equipos_conectados":
                        mostrarToast(`🖥️ Equipos conectados: ${data.cantidad}`, "info");
                        agregarLog(`🖥️ Equipos conectados: ${data.cantidad}`, "info");
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
        };

        ws.onclose = async () => {
            mostrarToast("🔴 Conexión WebSocket cerrada", "warning");
            if (reintentos < MAX_REINTENTOS) {
                reintentos++;
                mostrarToast(`🔄 Reconectando... (${reintentos}/${MAX_REINTENTOS})`, "warning");
                setTimeout(window.conectarWS, INTERVALO_REINTENTO);
            } else {
                mostrarToast("❌ No se pudo reconectar al servidor", "danger");
            }
        };

    } catch (error) {
        console.error("❌ Error al conectar WebSocket:", error);
        mostrarToast("❌ Error de conexión WebSocket", "danger");
    }
};

// Heartbeat: pedir estado cada 15s
setInterval(() => {
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({
            tipo: "actualizar",
            origen: "dashboard"
        }));
    }
}, 6500);
