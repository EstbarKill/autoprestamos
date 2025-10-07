// ================================
// DASHBOARD UNISIMÓN - CLIENTE UI
// ================================

function mostrarPagina(id) {
  document.querySelectorAll(".pagina").forEach(p => p.classList.remove("visible"));
  document.getElementById("pagina-" + id).classList.add("visible");
}

    // Fecha actual dinámica
    const fecha = new Date();
    document.getElementById("fechaActual").textContent =
      fecha.toLocaleDateString("es-CO", { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

// === Tabla dinámica ===
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
      <td><span class="badge bg-${estadoColor(s.nombre_estado)}">${s.nombre_estado}</span></td>
      <td>
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown">⚙️</button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="#" onclick="accionRemota(${s.id}, 'renovar')">♻️ Renovar</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionRemota(${s.id}, 'finalizar')">⛔ Finalizar</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionRemota(${s.id}, 'bloquear')">🚫 Bloquear</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionRemota(${s.id}, 'suspender')">⏸️ Suspender</a></li>
          </ul>
        </div>
      </td>
    `;
    tbody.appendChild(tr);
  });
}

function estadoColor(e) {
  switch (e) {
    case "Abierto": return "success";
    case "Suspendido": return "warning";
    case "Bloqueado": return "danger";
    case "Finalizado": return "secondary";
    default: return "light";
  }
}

function actualizarStats(stats) {
  document.getElementById("stat-abierto").textContent = stats.Abierto ?? 0;
  document.getElementById("stat-suspendido").textContent = stats.Suspendido ?? 0;
  document.getElementById("stat-bloqueado").textContent = stats.Bloqueado ?? 0;
  document.getElementById("stat-finalizado").textContent = stats.Finalizado ?? 0;
}

// === Acciones WebSocket ===
function accionRemota(id, accion) {
  if (!confirm(`¿Ejecutar '${accion}' en el equipo #${id}?`)) return;
  ws.send(JSON.stringify({
    accion: "comandoCliente",
    destino: id,
    comando: accion,
    mensaje: `Ejecutado por administrador desde dashboard`
  }));
  mostrarToast(`🚀 Comando '${accion}' enviado`);
}

// === Toast visual ===
function mostrarToast(msg) {
  const toast = document.createElement("div");
  toast.className = "toast-message";
  toast.textContent = msg;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}
