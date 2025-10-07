// assets/js/ws-client.js
const ws = new WebSocket("ws://localhost:8080");

ws.onopen = () => console.log("✅ Conectado al servidor WebSocket Unisimón");

ws.onmessage = (event) => {
  const data = JSON.parse(event.data);
  if (data.tipo === "estado") {
    actualizarTabla(data.sesiones);
    actualizarEstadisticas(data.stats);
  } else if (data.tipo === "mensaje") {
    mostrarToast(`💬 Mensaje recibido: ${data.texto}`);
  }
};

function enviarMensajeGlobal() {
  const msg = prompt("Ingrese el mensaje global a enviar:");
  if (!msg) return;
  ws.send(JSON.stringify({ accion: "mensaje", mensaje: msg, destino: "todos" }));
}

function actualizarTabla(sesiones) {
  const tbody = document.getElementById("tbodySesiones");
  tbody.innerHTML = sesiones.map(s => `
    <tr>
      <td>${s.id}</td>
      <td>${s.username}</td>
      <td>${s.fecha_inicio || '-'}</td>
      <td>${s.fecha_final_programada || '-'}</td>
      <td><span class="badge bg-success">${s.nombre_estado}</span></td>
      <td>
        <button class="btn btn-sm btn-outline-info" onclick="verInfo(${s.id})">🔍</button>
      </td>
    </tr>
  `).join('');
}

function actualizarEstadisticas(stats) {
  document.getElementById("stat-abierto").textContent = stats.Abierto;
  document.getElementById("stat-suspendido").textContent = stats.Suspendido;
  document.getElementById("stat-bloqueado").textContent = stats.Bloqueado;
  document.getElementById("stat-finalizado").textContent = stats.Finalizado;
}

function mostrarToast(texto) {
  const container = document.getElementById("toastContainer");
  const toast = document.createElement("div");
  toast.className = "toast align-items-center text-bg-success border-0 show";
  toast.role = "alert";
  toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${texto}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 5000);
}
