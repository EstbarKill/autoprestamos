// websocket.js
let ws = null;

function conectarWS() {

  const btn = document.querySelector("#toggleBtn");
  const dot = document.querySelector("#statusDot");

  ws = new WebSocket("ws://localhost:8081");

  ws.onopen = () => {
    btn.textContent = "Desconectar";
    btn.classList.remove("btn-warning", "btn-outline-danger");
    btn.classList.add("btn-success");
    btn.style.hidden = "black";
    localStorage.setItem("seccion", "true");
    mostrarToast("🟢 Conectado al servidor WebSocket");
    // Espera breve antes de solicitar estado
    setTimeout(() => {
      if (ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ accion: "getEstado" }));
      }
    }, 500);
  };

  ws.onmessage = (event) => {
    try {
      const data = JSON.parse(event.data);
      switch (data.tipo) {
        case "estado":
          actualizarTabla(data.sesiones);
          actualizarStats(data.stats);
          break;
        case "mensaje":
          mostrarToast("💬 " + data.texto);
          break;
        default:
          console.log("📡 Mensaje desconocido:", data);
      }
    } catch (err) {
      console.error("❌ Error parseando mensaje WS:", err, event.data);
    }
  };

  ws.onerror = (err) => {
    console.error("⚠️ Error WebSocket:", err);
    btn.textContent = "Error";
    btn.classList.remove("btn-outline-success");
    btn.classList.add("btn-outline-warning");
    mostrarToast("❌ No se pudo conectar al servidor WebSocket");
  };

  ws.onclose = () => {
    btn.textContent = "Conectar";
    btn.classList.remove("btn-outline-success", "btn-warning");
    btn.classList.add("btn-outline-danger");
    localStorage.setItem("seccion", "false");
    mostrarToast("🔴 Desconectado del WebSocket");
        // Limpia tabla y estadísticas
  mostrarDesconectado();
  actualizarStats({ Abierto: 0, Suspendido: 0, Bloqueado: 0, Finalizado: 0 });
    
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

// Refrescar cada 5s
setInterval(() => {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ accion: "getEstado" }));
  }
}, 5000);

function enviarComandoWS(comando, destino = "todos") {
  if (!ws || ws.readyState !== WebSocket.OPEN)
    return mostrarToast("⚠️ No conectado al WS");
  ws.send(JSON.stringify({ accion: "comando", comando, destino }));
  mostrarToast(`📨 Comando ${comando} enviado a ${destino}`);
}
