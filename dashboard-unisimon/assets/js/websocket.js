// websocket.js (versión corregida)
let ws = null;

function conectarWS() {
  const btn = document.querySelector("#toggleBtn");
  const dot = document.querySelector("#statusDot");

  // evita múltiples instancias
  if (ws && ws.readyState === WebSocket.OPEN) {
    mostrarToast("🟢 Ya conectado");
    return;
  }

  ws = new WebSocket("ws://localhost:8081");

  ws.onopen = () => {
    conectado = true;
    btn.textContent = "Desconectar";
    btn.classList.remove("btn-outline-danger","btn-warning");
    btn.classList.add("btn-success");
    dot.style.background = "green";
    localStorage.setItem("seccion", "true");
    mostrarToast("🟢 Conectado al servidor WebSocket");
    setTimeout(() => {
      if (ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ accion: "getEstado" }));
      }
    }, 300);
  };

  ws.onmessage = (event) => {
    try {
      const data = JSON.parse(event.data);
      switch (data.tipo) {
        case "estado":
          if (data.sesiones) actualizarTabla(data.sesiones);
          if (data.stats) actualizarStats(data.stats);
          break;
        case "mensaje":
          mostrarToast("💬 " + (data.texto || data.mensaje), "info");
          agregarLog("💬 " + (data.texto || data.mensaje), "info");
          break;
        case "log":
        case "info":
          mostrarToast(data.mensaje ?? "Evento recibido", "success");
          agregarLog(data.mensaje ?? "Evento recibido", "success");
          break;
        case "comando":
          mostrarToast("⚙️ Comando ejecutado: " + data.comando, "warning");
          agregarLog("⚙️ Comando ejecutado: " + data.comando, "warning");
          break;
        case "error":
          mostrarToast("❌ " + (data.mensaje ?? "Error desconocido"), "danger");
          agregarLog("❌ " + (data.mensaje ?? "Error desconocido"), "danger");
          break;
        default:
          console.log("📡 Mensaje desconocido:", data);
          agregarLog("📡 Mensaje desconocido: " + JSON.stringify(data), "secondary");
      }
    } catch (err) {
      console.error("❌ Error parseando mensaje WS:", err, event.data);
      mostrarToast("❌ Error al interpretar mensaje del servidor.", "danger");
      agregarLog("Error WS: " + err.message, "danger");
    }
  };

  ws.onerror = (err) => {
    console.error("⚠️ Error WebSocket:", err);
    btn.textContent = "Error";
    btn.classList.remove("btn-success");
    btn.classList.add("btn-warning");
    mostrarToast("❌ No se pudo conectar al servidor WebSocket");
  };

  ws.onclose = () => {
    conectado = false;
    btn.textContent = "Conectar";
    btn.classList.remove("btn-success", "btn-warning");
    btn.classList.add("btn-outline-danger");
    localStorage.setItem("seccion", "false");
    mostrarToast("🔴 Desconectado del WebSocket");
    mostrarDesconectado();
    actualizarStats({ Abierto: 0, Suspendido: 0, Bloqueado: 0, Finalizado: 0 });
    const dot = document.querySelector("#statusDot");
    if (dot) dot.style.background = "#d00";
  };
}

function desconectar() {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.close();
    mostrarToast("🛑 Desconectando del WebSocket...");
  } else {
    mostrarToast("⚠️ No hay conexión activa para cerrar");
  }
}

// keepalive / pedir estado cada 5s solo si está conectado
setInterval(() => {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ accion: "getEstado" }));
  }
}, 5000);
