let ws;

function conectarWS() {
  ws = new WebSocket("ws://localhost:8080");

  ws.onopen = () => {
    console.log("✅ Conectado al servidor WebSocket");
    ws.send(JSON.stringify({ accion: "getEstado" }));
  };

  ws.onmessage = event => {
    const data = JSON.parse(event.data);
    switch (data.tipo) {
      case "estado":
        actualizarTabla(data.sesiones);
        actualizarStats(data.stats);
        break;

case "monitor":
  console.log("📡 Monitoreo recibido:", data.equipos);
  actualizarMonitor(data.equipos);
  break;

      case "mensaje":
        mostrarToast("💬 " + data.texto);
        break;
    }
  };

  ws.onclose = () => {
    console.warn("⚠️ Desconectado del WebSocket, intentando reconectar...");
    setTimeout(conectarWS, 2000);
  };
}

conectarWS();

// === AUTOREFRESH CADA 5 SEGUNDOS ===
setInterval(() => {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ accion: "getEstado" }));
  }
}, 5000);

// === TOAST VISUAL ===
function mostrarToast(msg) {
  const toast = document.createElement("div");
  toast.className = "toast-message";
  toast.textContent = msg;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}

function actualizarMonitor(equipos) {
  const contenedor = document.getElementById("monitorEquipos");
  if (!contenedor) return;

  contenedor.innerHTML = equipos.map(eq => {
    const online = (Date.now() - new Date(eq.ultimo_ping).getTime()) < 20000;
    return `
      <div class="monitor-item">
        <span>${eq.id}</span>
        <span class="monitor-status ${online ? 'online' : 'offline'}">
          ${online ? '🟢 Activo' : '🔴 Desconectado'}
        </span>
      </div>
    `;
  }).join('');
}
