// websocket.js
let ws;

function conectarWS() {
  ws = new WebSocket("ws://localhost:8080");

  ws.onopen = () => {
    console.log("✅ Conectado al servidor WebSocket");
    // pedir estado
    ws.send(JSON.stringify({ accion: "getEstado" }));
  };

  ws.onmessage = event => {
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
      }
    } catch (err) {
      console.error("Error parseando mensaje WS:", err, event.data);
    }
  };

  ws.onclose = () => {
    console.warn("⚠️ Desconectado del WebSocket, reconectando en 2s...");
    setTimeout(conectarWS, 2000);
  };
}

conectarWS();

// refrescar cada 5s
setInterval(() => {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ accion: "getEstado" }));
  }
}, 5000);

// mandar comando a un equipo (a través del ws)
function enviarComandoWS(comando, destino = "todos") {
  if (!ws || ws.readyState !== WebSocket.OPEN) return mostrarToast("⚠️ No conectado al WS");
  ws.send(JSON.stringify({ accion: "comando", comando, destino }));
  mostrarToast(`📨 Comando ${comando} enviado a ${destino}`);
}
