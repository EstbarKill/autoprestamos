let ws = new WebSocket("ws://localhost:8080");

ws.onopen = () => {
  console.log("✅ Conectado al servidor WebSocket");
  ws.send(JSON.stringify({ tipo: "dashboard" }));
};

ws.onmessage = event => {
  const data = JSON.parse(event.data);

  switch (data.tipo) {
    case "estado":
      actualizarTabla(data.sesiones);
      actualizarStats(data.stats);
      break;

    case "mensaje":
      alert("💬 " + data.texto);
      break;
  }
};

ws.onclose = () => {
  console.warn("⚠️ Desconectado del WebSocket, intentando reconectar...");
  setTimeout(() => (ws = new WebSocket("ws://localhost:8080")), 2000);
};
