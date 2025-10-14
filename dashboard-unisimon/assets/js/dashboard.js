// dashboard.js (resumen funcional)
function mostrarPagina(id) {
  document.querySelectorAll(".pagina").forEach(p => p.classList.remove("visible"));
  document.getElementById("pagina-" + id).classList.add("visible");
}

    // Fecha actual dinámica
    const fecha = new Date();
    document.getElementById("fechaActual").textContent =
      fecha.toLocaleDateString("es-CO", { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

// === INICIAR SERVIDOR DESDE EL DASHBOARD ===
async function iniciarServidor() {
  if (!confirm("¿Deseas iniciar el servidor WebSocket (Ratchet)?")) return;

  try {
    const resp = await fetch("../../../servers/server.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ accion: "server.php" })
    });

    const data = await resp.json();
    if (data.status === "ok") {
      mostrarToast("✅ Servidor iniciado correctamente");
    } else {
      mostrarToast("⚠️ " + (data.mensaje || "No se pudo iniciar el servidor"));
    }
  } catch (err) {
    mostrarToast("❌ Error: " + err.message);
  }
}

function actualizarStats(stats) {
  document.getElementById("stat-abierto").textContent = stats.Abierto ?? 0;
  document.getElementById("stat-suspendido").textContent = stats.Suspendido ?? 0;
  document.getElementById("stat-bloqueado").textContent = stats.Bloqueado ?? 0;
  document.getElementById("stat-finalizado").textContent = stats.Finalizado ?? 0;
}

// === Actualizar datos manualmente ===
function actualizarDatos() {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ accion: "getEstado" }));
    mostrarToast("🔄 Datos actualizados manualmente");
  } else {
    mostrarToast("⚠️ WebSocket no conectado");
  }
}

function actualizarTabla(sesiones) {
  const tbody = document.querySelector("#tablaSesiones tbody");
  tbody.innerHTML = "";
  sesiones.forEach(s => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${s.id}</td>
      <td>${s.username}</td>
      <td>${s.fecha_inicio || '-'}</td>
      <td>${s.fecha_final_programada || '-'}</td>
      <td><span class="badge bg-${estadoColor(s.nombre_estado)}">${s.nombre_estado}</span></td>
      <td>
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown">⚙️</button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'renovar')">♻️ Renovar</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'finalizar')">⛔ Finalizar</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'bloquear')">🚫 Bloquear</a></li>
            <li><a class="dropdown-item" href="#" onclick="enviarComandoWS('suspender','${s.username}')">⏸ Suspender</a></li>
            <li><a class="dropdown-item" href="#" onclick="enviarComandoWS('renovar','${s.username}')">🔁 Renovar (WS)</a></li>
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

function filtrarTabla() {
  const filtro = document.getElementById("filtroEstado").value.toLowerCase();
  document.querySelectorAll("#tablaSesiones tbody tr").forEach(tr => {
    const estado = tr.cells[4].textContent.toLowerCase();
    tr.style.display = (!filtro || estado.includes(filtro)) ? "" : "none";
  });
}

async function accionSesion(id, accion) {
  if (!confirm(`¿Seguro que deseas ${accion} la sesión #${id}?`)) return;
  try {
    const resp = await fetch("/dashboard-unisimon/dashboard_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, accion })
    });
    const data = await resp.json();
    if (data.status === "ok") {
      mostrarToast("✅ " + data.mensaje);
      // desencadenar actualización via WS
      if (typeof ws !== "undefined" && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ accion: "actualizar" }));
      }
    } else {
      mostrarToast("❌ " + data.mensaje);
    }
  } catch (err) {
    mostrarToast("⚠️ Error: " + err.message);
  }
}

function guardarConfig() {
  const tiempo = document.getElementById("config-tiempo").value;
  const clave = document.getElementById("config-clave").value;
  localStorage.setItem("config-tiempo", tiempo);
  localStorage.setItem("config-clave", clave);
  mostrarToast("💾 Configuración guardada localmente");
}

function enviarMensaje() {
  const texto = document.getElementById("mensajeTexto").value.trim();
  const destino = document.getElementById("mensajeDestino").value || "todos";
  if (!texto) return mostrarToast("⚠️ Escribe un mensaje primero");
  if (typeof ws !== "undefined" && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ accion: "mensaje", mensaje: texto, destino }));
    mostrarToast("📨 Mensaje enviado");
    document.getElementById("mensajeTexto").value = "";
  } else mostrarToast("⚠️ No conectado al WS");
}

function enviarMensajeATodos() {
  const texto = document.getElementById("mensajeTexto").value.trim();
  if (!texto) return mostrarToast("⚠️ Escribe un mensaje primero");
  if (typeof ws !== "undefined" && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ accion: "mensaje", mensaje: texto, destino: "todos" }));
    mostrarToast("🌍 Mensaje enviado a todos");
    document.getElementById("mensajeTexto").value = "";
  } else mostrarToast("⚠️ No conectado al WS");
}

function mostrarToast(msg) {
  const toast = document.createElement("div");
  toast.className = "toast-message";
  toast.textContent = msg;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 4000);
}
