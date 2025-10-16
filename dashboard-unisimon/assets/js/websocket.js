// websocket.js
let ws =null ;

function conectarWS() {
   if (ws && ws.readyState === WebSocket.OPEN) {
    mostrarToast("⚠️ Ya estás conectado");
    return;
  }
  ws = new WebSocket("ws://localhost:8080");

  ws.onopen = () => {
  document.querySelector("#statusDot").style.background = "#0d0";
  document.querySelector("#toggleBtn").textContent = "Conectado";
  document.querySelector("#toggleBtn").classList.remove("btn-outline-success");
  document.querySelector("#toggleBtn").classList.add("btn-outline-success");
  localStorage.setItem("seccion", true);;
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
  document.querySelector("#statusDot").style.background = "rgba(255, 0, 0, 1)";
  document.querySelector("#toggleBtn").textContent = "Desconectado";
  document.querySelector("#toggleBtn").classList.remove("btn-outline-danger");
  document.querySelector("#toggleBtn").classList.add("btn-outline-danger");
  localStorage.setItem("seccion", false);
    limpiarTabla(); // limpiar tabla al desconectarse
    console.warn("⚠️ Desconectado del WebSocket, reconectando en 2s...");
    setTimeout(conectarWS, 2000);
  };
}


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
