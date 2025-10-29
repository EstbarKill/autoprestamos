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
document.addEventListener("DOMContentLoaded", function() {
  verificarServidor();
  mostrarDesconectado();
  document.getElementById("fechaActual").textContent = new Date().toLocaleString();
});

// 🟢 FUNCIÓN CORREGIDA - Conectar Dashboard
function conectarD() {
  if (typeof conectarWS === 'function') {
    conectarWS();
    conectado = true;
  } else {
    console.error('conectarWS no está definido');
    mostrarToast('❌ Error: WebSocket no disponible');
  }
}

// 🔴 FUNCIÓN CORREGIDA - Desconectar
function desconectar() {
  if (confirm("⚠️ ¿Deseas desconectar del servidor WebSocket?")) {
    if (typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
      ws.close();
      console.log("🔌 Conexión WebSocket cerrada correctamente.");
      conectado = false;
      
      // Actualizar UI
      const btn = document.querySelector("#toggleBtn");
      const dot = document.querySelector("#statusDot");
      btn.textContent = "Conectar";
      btn.classList.remove("btn-success");
      btn.classList.add("btn-outline-danger");
      dot.style.background = "#d00";
    }
  }
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

// 🔄 FUNCIÓN CORREGIDA - Toggle Servidor
function toggleServidor() {
  if (conectado) {
    desconectar();
  } else {
    conectarD();
  }
}

// 📊 FUNCIÓN CORREGIDA - Fetch Estado
async function fetchEstado() {
    try {
        console.log("🌐 Solicitando estado via HTTP...");
        // 1) pedir stats
        const statsRes = await fetch('./dashboard_stats.php');
        const stats = await statsRes.json();
        actualizarStats(stats);

        // 2) pedir sesiones
        const sesionesRes = await fetch('./get_sesiones.php');
        const sesiones = await sesionesRes.json();
        if (Array.isArray(sesiones)) {
            console.log("📥 Sesiones recibidas via HTTP:", sesiones.length);
            actualizarTabla(sesiones);
        } else {
            console.warn("❌ Respuesta de sesiones no es array:", sesiones);
        }
    } catch (err) {
        console.warn('❌ No se pudo cargar estado via HTTP:', err);
        mostrarDesconectado();
        actualizarStats({Abierto:0, Suspendido:0, Bloqueado:0, Finalizado:0});
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
        btn.textContent = "Encendido";
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

// ✅ FUNCIÓN CORREGIDA - actualizarTabla
function actualizarTabla(sesiones) {
    const tbody = document.querySelector("#tablaSesiones tbody");
    if (!tbody) {
        console.error("❌ No se encontró tbody en la tabla");
        return;
    }

    console.log("📊 Actualizando tabla con", sesiones?.length, "sesiones");
    
    // Si no pasan sesiones, intentamos obtener vía HTTP o WS
    if (!sesiones || sesiones.length === 0) {
        console.log("ℹ️ No hay sesiones, intentando obtener...");
        // Si WS está abierto, pedir estado
        if (typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ accion: "getEstado" }));
            return;
        } else {
            // fallback HTTP
            fetchEstado();
            return;
        }
    }

    tbody.innerHTML = "";
    
    sesiones.forEach((s) => {
        const tr = document.createElement("tr");
        // 🟢 AGREGAR DATA ATTRIBUTES PARA DEBUGGING
        tr.setAttribute('data-sesion-id', s.id);
        tr.setAttribute('data-username', s.username || '');
        tr.setAttribute('data-estado', s.nombre_estado || '');
        tr.setAttribute('data-pc', s.nombre_pc || '');
        
        tr.innerHTML = `
            <td class="text-${estadoColor(s.nombre_estado)}">${s.id}</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${s.username ?? s.usuario ?? '-'}</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${s.nombre_pc || 'Desconocido'}</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${s.fecha_inicio || "-"}</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${s.fecha_final_programada || "-"}</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${s.fecha_final_real || "-"}</td>
            <td><span class="badge bg-${estadoColor(s.nombre_estado)}">${s.nombre_estado || '-'}</span></td>
            <td>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" 
                            onclick="console.log('🖱️ Click en acciones para sesión:', ${s.id}, 'usuario:', '${s.username}')">
                        ⚙️
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="verInfo(${s.id})">🔍 Ver Info</a></li>
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
    
    console.log("✅ Tabla actualizada con", sesiones.length, "registros");
}

// 📝 FUNCIÓN NUEVA - Ver Info con Datos Reales
function verInfo(id) {
  // Obtener información detallada del servidor
  fetch("./dashboard_action.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ accion: "info", id: id })
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === "ok" && data.data) {
      const info = data.data;
      
      // Llenar información del usuario
      document.getElementById("usuarioInfo").innerHTML = `
        <li><strong>ID:</strong> ${info.id}</li>
        <li><strong>Usuario:</strong> ${info.username || 'N/A'}</li>
        <li><strong>Estado:</strong> ${info.nombre_estado || 'N/A'}</li>
        <li><strong>Inicio:</strong> ${info.fecha_inicio || 'N/A'}</li>
        <li><strong>Fin Programado:</strong> ${info.fecha_final_programada || 'N/A'}</li>
      `;
      
      // Llenar información del computador (puedes expandir esto)
      document.getElementById("computadorInfo").innerHTML = `
        <li><strong>IP:</strong> Por implementar</li>
        <li><strong>Hostname:</strong> Por implementar</li>
        <li><strong>Sistema:</strong> Por implementar</li>
        <li><strong>Última Actividad:</strong> ${info.fecha_inicio || 'N/A'}</li>
      `;
      
      // Mostrar modal
      var myModal = new bootstrap.Modal(document.getElementById("modalInfo"));
      myModal.show();
    } else {
      mostrarToast("❌ No se pudo obtener información de la sesión", "danger");
    }
  })
  .catch(err => {
    console.error("Error al obtener info:", err);
    mostrarToast("❌ Error al obtener información", "danger");
  });
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
      return "dark";
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

// 🎯 FUNCIÓN MEJORADA - Acción Sesión con Debugging Completo
function accionSesion(id, accion) {
    console.log(`🎯 Iniciando acción: ${accion} para sesión: ${id}`);
    
    // Obtener información de la fila para debugging
    const fila = document.querySelector(`tr[data-sesion-id="${id}"]`);
    const username = fila ? fila.getAttribute('data-username') : 'desconocido';
    const estado = fila ? fila.getAttribute('data-estado') : 'desconocido';
    const nombre_pc = fila ? fila.getAttribute('data-pc') : 'desconocido';
    
    console.log(`📋 Detalles sesión - ID: ${id}, Usuario: ${username}, Estado: ${estado}`);
    
    if (!confirm(`¿Estás seguro de ejecutar '${accion}' en la sesión ${id} del equipo (${nombre_pc})`)) {
        console.log("❌ Usuario canceló la acción");
        return;
    }

    // 🟢 1️⃣ Primero enviar comando via WebSocket (para ejecución inmediata en PowerShell)
    if (typeof ws !== "undefined" && ws && ws.readyState === WebSocket.OPEN) {
        const payload = {
        tipo: "comando",
        accion: accion,       // suspender, bloquear, etc.
        nombre_pc: nombre_pc,         // nombre del equipo o ID
        origen: "dashboard",
        timestamp: new Date().toISOString()
        };
        
        console.log("📡 Enviando comando WebSocket:", payload);
        ws.send(JSON.stringify(payload));
        mostrarToast(`⚡ Comando ${accion} enviado a equipo ${username}`, "success");
        
    } else {
        console.error("❌ WebSocket no disponible para enviar comando");
        mostrarToast("⚠️ WebSocket desconectado - Comando no enviado", "warning");
    }

    // 🟢 2️⃣ Luego actualizar base de datos (para persistencia)
    console.log(`💾 Registrando acción en BD: ${accion} para sesión ${id}`);
    fetch("./dashboard_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ accion, id })
    })
    .then(res => {
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        return res.json();
    })
    .then(data => {
        console.log("✅ Respuesta BD:", data);
        if (data.status === "ok") {
            mostrarToast(`✅ ${data.mensaje}`, "success");
            
            // 🟢 3️⃣ Actualizar interfaz después de 1 segundo
            setTimeout(() => {
                console.log("🔄 Actualizando interfaz...");
                if (typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ accion: "getEstado" }));
                } else {
                    fetchEstado();
                }
            }, 1000);
        } else {
            console.error("❌ Error en BD:", data.mensaje);
            mostrarToast(`❌ Error: ${data.mensaje}`, "danger");
        }
    })
    .catch(err => {
        console.error("❌ Error al registrar acción:", err);
        mostrarToast("❌ Error al registrar acción en BD", "danger");
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