let conectado = false;
let sesiones = [];
const WS_URL = (location.protocol === 'https:' ? 'wss' : 'ws') + '://localhost:8080';
function conectar() {
  conectado = true;
conectarWS();
}
function mostrarDesconectado() {
  const tbody = document.querySelector("#tablaSesiones tbody");
  if (!tbody) return;

  // Limpia filas actuales
  tbody.innerHTML = "";

  // Inserta imagen centrada
  const fila = document.createElement("tr");
  const celda = document.createElement("td");
  celda.colSpan = 6;
  celda.style.textAlign = "center";
  celda.style.padding = "40px";
  celda.innerHTML = `
    <td colspan="6" style="text-align:center; padding:30px;">
    <img src="./assets/img/images.png" alt="Servidor desconectado" style="width:650px; opacity:0.3;">
    <p style="color:green; font-size:3.5em; margin-top:10px;">Servidor desconectado</p>
    </td>
  `;
  fila.appendChild(celda);
  tbody.appendChild(fila);
}
function desconectar() {
  if (ws) ws.close();
  ws = null;
  conectado = false;
    // Limpia tabla y estadísticas
  mostrarDesconectado();
  actualizarStats({ Abierto: 0, Suspendido: 0, Bloqueado: 0, Finalizado: 0 });
}

function toggleServidor() {
  if (conectado) desconectar();
  else conectar();
}
// dashboard.js (resumen funcional)
function mostrarPagina(id) {
  document.querySelectorAll(".pagina").forEach(p => p.classList.remove("visible"));
  document.getElementById("pagina-" + id).classList.add("visible");
}

    // Fecha actual dinámica
    const fecha = new Date();
    document.getElementById("fechaActual").textContent =
      fecha.toLocaleDateString("es-CO", { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

// Función para actualizar el estado del servidor WebSocket
function actualizarEstadoServidor(status) {
  const estadoElem = document.getElementById('estado-websocket');
  if (status === 'ok') {
    estadoElem.textContent = 'Servidor WebSocket en ejecución (ws://localhost:8080)';
  } else {
    estadoElem.textContent = 'Servidor WebSocket no está en ejecución';
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
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'info')">🔍 Ver Info</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionSesion('${s.username}','suspender')">⏸ Suspender</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'renovar')">♻️ Renovar</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'finalizar')">⛔ Finalizar</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'bloquear')">🚫 Bloquear</a></li>
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

function accionSesion(id, accion) {
    // Mostrar el mensaje de confirmación antes de realizar la acción
    if (!confirm(`¿Estás seguro de ejecutar la acción '${accion}' sobre la sesión ${id}?`)) return;

    // Llamar al backend para ejecutar la acción
    fetch('././dashboard_action.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            accion: accion,
            id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'ok') {
            // Mostrar un mensaje de éxito en la interfaz
            alert(data.mensaje);
            // Actualizar la tabla para reflejar los cambios
            obtenerSesiones();  // Supongamos que tienes esta función para cargar los datos actualizados
        } else {
            alert(`Error: ${data.mensaje}`);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ocurrió un error al ejecutar la acción.');
    });
}
// Ejemplo de cómo actualizar la tabla después de realizar una acción
function obtenerSesiones() {
    fetch('get_sessions.php') // Asegúrate de tener un endpoint que devuelva las sesiones actualizadas
        .then(response => response.json())
        .then(sesiones => {
            actualizarTabla(sesiones);  // Actualizar la tabla en la UI
        })
        .catch(error => {
            console.error('Error:', error);
        });
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

// iniciar fecha actual y auto-conectar
document.addEventListener('DOMContentLoaded', () => {
  mostrarDesconectado();
  document.getElementById('fechaActual').textContent = new Date().toLocaleString();
});
