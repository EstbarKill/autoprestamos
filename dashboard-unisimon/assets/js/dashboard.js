// Variables sistema de notificaciones
const toastContainer = document.getElementById("toast-container");
const logContainer = document.getElementById("logContainer");
let logs = [];
///////////////////////////


let conectado = false;
let conectado_server = false;
let sesiones = [];
const WS_URL =
  (location.protocol === "https:" ? "wss" : "ws") + "://localhost:8081";

// Verificar automáticamente al cargar la página
document.addEventListener("DOMContentLoaded", verificarServidor);
function conectarD() {
  conectarWS();
  conectado = true;
}

async function verificarServidor() {
  try {
    const res = await fetch("../servers/estado_server.php");
    const data = await res.json();

    const btn = document.querySelector("#btnEncenderServidor");
    if (data.status === "corriendo") {
      document.querySelector("#statusDot").style.background = "yellow";
      btn.textContent = "Encendido";
      btn.classList.remove("btn-primary", "btn-warning");
      btn.classList.add("btn-success");
      mostrarToast("🟢 " + data.mensaje);
      conectado_server = true;
    } else if (data.status === "detenido") {
      document.querySelector("#statusDot").style.background = "blue";
      btn.textContent = "Apagado";
      btn.classList.remove("btn-success", "btn-warning");
      btn.classList.add("btn-primary");
      console.log("🔴 " + data.mensaje);
      mostrarToast("⚠️ servidor desconectado");
      conectado_server = false;
    } else {
      // Caso inesperado
      console.warn("Estado servidor desconocido:", data);
      conectado_server = false;
    }

    return data;
  } catch (err) {
    console.error("Error al verificar el servidor:", err);
    mostrarToast("⚠️ No se pudo verificar el estado del servidor");
    return null;
  }
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
  if (
    confirm("⚠️ El servidor WebSocket está en ejecución.\n¿Deseas apagarlo?")
  ) {
    if (ws && ws.readyState === WebSocket.OPEN) {
      ws.close();
      console.log("🔌 Conexión WebSocket cerrada correctamente.");
      conectado = false;
    } else {
      console.warn("⚠️ No hay conexión activa para cerrar.");
    }
  } else {
    mostrarToast("❌ Operación cancelada por el usuario.");
  }
}

// toggleServidor arreglado
function toggleServidor() {
  if (conectado) {
    desconectar(); // desconecta WS
  } else {
    conectarD(); // conecta WS
  }
}

// dashboard.js (resumen funcional)
function mostrarPagina(id) {
  document
    .querySelectorAll(".pagina")
    .forEach((p) => p.classList.remove("visible"));
  document.getElementById("pagina-" + id).classList.add("visible");
}

// Fecha actual dinámica
const fecha = new Date();
document.getElementById("fechaActual").textContent = fecha.toLocaleDateString(
  "es-CO",
  { weekday: "long", year: "numeric", month: "long", day: "numeric" }
);

// Función para actualizar el estado del servidor WebSocket
function actualizarEstadoServidor(status) {
  const estadoElem = document.getElementById("estado-websocket");
  if (status === "corriendo") {
    estadoElem.textContent =
      "Servidor WebSocket en ejecución (ws://localhost:8081)";
  } else {
    estadoElem.textContent = "Servidor WebSocket no está en ejecución";
  }
}

function actualizarStats(stats) {
  document.getElementById("stat-abierto").textContent = stats.Abierto ?? 0;
  document.getElementById("stat-suspendido").textContent =
    stats.Suspendido ?? 0;
  document.getElementById("stat-bloqueado").textContent = stats.Bloqueado ?? 0;
  document.getElementById("stat-finalizado").textContent =
    stats.Finalizado ?? 0;
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
async function manejoServidor() {
  if (conectado_server) {
    detenerServidor();
  } else {
    iniciarServidor();
  }
}

// 🟢 Iniciar o apagar servidor según estado actual
function iniciarServidor() {
  const btn = document.querySelector("#btnEncenderServidor");
  btn.textContent = "Iniciando...";
  btn.classList.remove("btn-success");
  btn.classList.add("btn-warning");

  // Si está detenido, proceder a iniciar
  fetch("../servers/iniciar_server.php")
    .then((res) => res.text())
    .then((data) => {
      console.log("Respuesta cruda al iniciar:", data);
      const responseData = JSON.parse(data);
      if (responseData.status === "iniciado") {
        mostrarToast("🚀 " + responseData.mensaje);
        btn.textContent = "Encendid2o";
        btn.classList.remove("btn-warning");
        btn.classList.add("btn-success");
        conectado_server = true;
      } else if (responseData.status === "ya_corriendo") {
        mostrarToast("🟢 " + responseData.mensaje);
        btn.textContent = "Encendido";
        btn.classList.remove("btn-warning");
        btn.classList.add("btn-success");
        conectado_server = true;
      } else {
        mostrarToast("⚠️ " + responseData.mensaje);
        btn.textContent = "Apagado";
        btn.classList.remove("btn-warning", "btn-success");
        btn.classList.add("btn-primary");
        conectado_server = false;
      }
    })
    .catch((err) => {
      console.error("❌ Error al iniciar servidor:", err);
      mostrarToast("❌ Error al iniciar el servidor");
      btn.textContent = "Apagado";
      btn.classList.remove("btn-warning", "btn-success");
      btn.classList.add("btn-primary");
      conectado_server = false;
    });
}

// 🔴 Detener servidor
function detenerServidor() {
  const btn = document.querySelector("#btnEncenderServidor");

  btn.textContent = "Deteniendo...";
  btn.classList.remove("btn-success");
  btn.classList.add("btn-warning");

  fetch("../servers/detener_server.php")
    .then((res) => res.text())
    .then((data) => {
      console.log("Respuesta cruda al detener:", data);
      const responseData = JSON.parse(data);

      if (responseData.status === "detenido") {
        mostrarToast("🔴 " + responseData.mensaje);
        btn.textContent = "Apagado";
        btn.classList.remove("btn-warning", "btn-success");
        btn.classList.add("btn-primary");
        conectado_server = false;
      } else {
        mostrarToast("⚠️ " + responseData.mensaje);
        btn.textContent = "Apagado";
        btn.classList.remove("btn-warning", "btn-success");
        btn.classList.add("btn-primary");
      }
    })
    .catch((err) => {
      console.error("❌ Error al detener servidor:", err);
      mostrarToast("❌ Error al detener el servidor");
      btn.textContent = "Apagado";
      btn.classList.remove("btn-warning", "btn-success");
      btn.classList.add("btn-primary");
    });
}

// llamada segura para actualizar tabla desde otras funciones
function actualizarTabla(sesiones) {
  const tbody = document.querySelector("#tablaSesiones tbody");
  if (!tbody) return;
  // si no pasan sesiones, intentamos obtener vía HTTP o WS
  if (!sesiones) {
    // si WS está abierto, pedir estado
    if (typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({ accion: "getEstado" }));
      return;
    } else {
      // fallback HTTP
      fetchEstado();
      return;
    }
  }
// Fetch inicial de estado / sesiones desde backend (si WS no está disponible)
async function fetchEstado() {
  try {
    // 1) pedir stats
    const statsRes = await fetch('./dashboard_stats.php');
    const stats = await statsRes.json();
    actualizarStats(stats);

    // 2) pedir sesiones (crea get_sesiones.php si no existe - abajo muestro ejemplo)
    const sesionesRes = await fetch('./get_sesiones.php');
    const sesiones = await sesionesRes.json();
    if (Array.isArray(sesiones)) {
      actualizarTabla(sesiones);
    }
  } catch (err) {
    console.warn('No se pudo cargar estado via HTTP:', err);
    // si falla, mostrar desconectado visual
    mostrarDesconectado();
    actualizarStats({Abierto:0,Suspendido:0,Bloqueado:0,Finalizado:0});
  }
}
  tbody.innerHTML = "";
  sesiones.forEach((s) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${s.id}</td>
      <td>${s.username ?? s.usuario ?? '-'}</td>
      <td>${s.fecha_inicio || "-"}</td>
      <td>${s.fecha_final_programada || "-"}</td>
      <td><span class="badge bg-${estadoColor(s.nombre_estado)}">${s.nombre_estado || '-'}</span></td>
      <td>
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown">⚙️</button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="#" onclick="verInfo('${s.username}',${s.id})">🔍 Ver Info</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'mensaje')">📜 Mensaje</a></li>
            <li><a class="dropdown-item" href="#" onclick="accionSesion(${s.id}, 'suspender')">⏸ Suspender</a></li>
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
function verInfo(username, id) {
  username = username;
  id = id;
  // Llamar al backend para obtener la información detallada
  // Mostrar el modal (esto dependerá de tu implementación de Bootstrap)
  var myModal = new bootstrap.Modal(document.getElementById("modalInfo"), {
    keyboard: false,
  });
  myModal.show();
  mostrarToast(username + " id " + id);
}

function estadoColor(e) {
  switch (e) {
    case "Abierto":
      return "success";
    case "Suspendido":
      return "warning";
    case "Bloqueado":
      return "danger";
    case "Finalizado":
      return "secondary";
    default:
      return "light";
  }
}

function filtrarTabla() {
  const filtro = document.getElementById("filtroEstado").value.toLowerCase();
  document.querySelectorAll("#tablaSesiones tbody tr").forEach((tr) => {
    const estado = tr.cells[4].textContent.toLowerCase();
    tr.style.display = !filtro || estado.includes(filtro) ? "" : "none";
  });
}

// 🔄 accionSesion: envía comando al backend y al WebSocket
function accionSesion(id, accion) {
  if (!confirm(`¿Estás seguro de ejecutar la acción '${accion}' sobre la sesión ${id}?`)) return;

  // Petición HTTP al backend (para registro en BD, logs, etc.)
  fetch("./dashboard_action.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ accion, id })
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === "ok") {
      mostrarToast(data.mensaje || "Operación correcta", "success");

      // ✅ Enviar al WebSocket para ejecución en el cliente PowerShell
      if (typeof ws !== "undefined" && ws.readyState === WebSocket.OPEN) {
        const payload = {
          tipo: "control",
          id: id,
          accion: accion,
          timestamp: new Date().toISOString()
        };
        ws.send(JSON.stringify(payload));
        console.log("📡 Acción enviada al PowerShell:", payload);
      } else {
        console.warn("⚠️ WebSocket no conectado. Solo se ejecutó en el backend.");
      }

      // 🔁 Refrescar estado general
      if (typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ accion: "getEstado" }));
      } else {
        fetchEstado();
      }

    } else {
      mostrarToast(`Error: ${data.mensaje}`, "danger");
    }
  })
  .catch(err => {
    console.error(err);
    mostrarToast("Ocurrió un error al ejecutar la acción.", "danger");
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
    ws.send(
      JSON.stringify({ accion: "mensaje", mensaje: texto, destino: "todos" })
    );
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
// Función que obtiene los logs del servidor WebSocket
function obtenerLogsServidor() {
  fetch("../../servers/server.log")
    .then((response) => response.text()) // Obtener el archivo de logs como texto
    .then((data) => {
      // Mostramos los logs en un div específico de "Circulación"
      document.getElementById("logsCirculacion").innerText = data;
    })
    .catch((error) => {
      console.error("Error al obtener los logs del servidor:", error);
    });
}

// === Sistema de notificaciones ===
function mostrarToast(mensaje, tipo = "info") {
  const toast = document.createElement("div");
  toast.className = `toast align-items-center text-bg-${tipo} border-0 show`;
  toast.role = "alert";
  toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${mensaje}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  `;
  toastContainer.appendChild(toast);

  setTimeout(() => toast.remove(), 5000); // Desaparece en 5 segundos
}

function agregarLog(mensaje, tipo = "info") {
  const timestamp = new Date().toLocaleTimeString();
  logs.push({ mensaje, tipo, timestamp });

  // Crear entrada visual
  const entry = document.createElement("div");
  entry.className = `border-bottom py-1 text-${tipo === "error" ? "danger" : tipo === "success" ? "success" : "secondary"}`;
  entry.textContent = `[${timestamp}] ${mensaje}`;

  // Agregar al contenedor
  if (logContainer.querySelector("p")) logContainer.innerHTML = "";
  logContainer.prepend(entry);

  // Limitar registros antiguos (cada 30 minutos = limpieza)
  limpiarLogsViejos();
}

function limpiarLogsViejos() {
  const ahora = Date.now();
  logs = logs.filter(log => {
    const tiempo = new Date(`1970-01-01T${log.timestamp}Z`).getTime();
    return (ahora - tiempo) < 30 * 60 * 1000; // 30 minutos
  });
  // Si quedan pocos logs, mantenemos el contenedor limpio
  if (logs.length === 0) {
    logContainer.innerHTML = "<p class='text-muted'>Sin registros recientes...</p>";
  }
}



// al cargar la página: mostrar desconectado y cargar estado HTTP como fallback
document.addEventListener("DOMContentLoaded", () => {
  mostrarDesconectado();
  document.getElementById("fechaActual").textContent = new Date().toLocaleString();
});