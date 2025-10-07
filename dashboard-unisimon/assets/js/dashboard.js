function actualizarTabla(sesiones) {
  const tbody = document.querySelector("#tablaSesiones tbody");
  tbody.innerHTML = "";

  sesiones.forEach(s => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${s.id}</td>
      <td>${s.username}</td>
      <td>${s.fecha_inicio}</td>
      <td>${s.fecha_final_programada}</td>
      <td><span class="badge bg-${colorEstado(s.nombre_estado)}">${s.nombre_estado}</span></td>
      <td>
        <button class="btn btn-sm btn-outline-success" onclick="enviarAccion('renovar', ${s.id_equipo_fk})">♻️</button>
        <button class="btn btn-sm btn-outline-warning" onclick="enviarAccion('suspender', ${s.id_equipo_fk})">⏸️</button>
        <button class="btn btn-sm btn-outline-danger" onclick="enviarAccion('bloquear', ${s.id_equipo_fk})">🚫</button>
      </td>`;
    tbody.appendChild(tr);
  });
}

function colorEstado(estado) {
  switch (estado) {
    case "Abierto": return "success";
    case "Suspendido": return "warning";
    case "Bloqueado": return "danger";
    default: return "secondary";
  }
}

function actualizarStats(stats) {
  document.getElementById("stat-abierto").textContent = stats.Abierto;
  document.getElementById("stat-suspendido").textContent = stats.Suspendido;
  document.getElementById("stat-bloqueado").textContent = stats.Bloqueado;
  document.getElementById("stat-finalizado").textContent = stats.Finalizado;
}

function enviarAccion(accion, id_equipo) {
  ws.send(JSON.stringify({ accion, id_equipo }));
}

function enviarMensaje() {
  const texto = document.getElementById("mensajeTexto").value;
  const destino = document.getElementById("mensajeDestino").value || "todos";
  ws.send(JSON.stringify({ accion: "mensaje", mensaje: texto, destino }));
  alert(`📤 Mensaje enviado a ${destino}`);
}

function enviarMensajeATodos() {
  const texto = document.getElementById("mensajeTexto").value;
  ws.send(JSON.stringify({ accion: "mensaje", mensaje: texto, destino: "todos" }));
  alert("🌍 Mensaje enviado a todos los equipos");
}

function actualizarDatos() {
  ws.send(JSON.stringify({ accion: "getEstado" }));
}

function filtrarTabla() {
  const filtro = document.getElementById("filtroEstado").value;
  ws.send(JSON.stringify({ accion: "getEstado", filtro }));
}
