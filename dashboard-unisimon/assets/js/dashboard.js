// Variables sistema de notificaciones
const toastContainer = document.getElementById("toast-container");
const logContainer = document.getElementById("logContainer");
let logs = [];
///////////////////////////

let conectado = false;
let conectado_server = false;
let sesiones = [];
let sedeSeleccionada = null; // Almacenar sede seleccionada
let estadoFiltroSeleccionado = null; // Almacenar filtro de estado seleccionado
let cambioSedeEnProgreso = false;
// Sistema de toasts en cola FIFO
window.toastQueue = [];
window.MAX_TOASTS_VISIBLE = 5;
window.TOAST_DURATION = 4000; // ms
const WS_URL =
  (location.protocol === "https:" ? "wss" : "ws") + "://localhost:8081";

// Verificar automáticamente al cargar la página
document.addEventListener("DOMContentLoaded", function () {
  verificarServidor();
  mostrarDesconectado();
  document.getElementById("fechaActual").textContent =
    new Date().toLocaleString();
  
  // Restaurar sede seleccionada desde localStorage
  const sedeGuardada = localStorage.getItem("sede_seleccionada");
  if (sedeGuardada) {
    document.getElementById("selectSede").value = sedeGuardada;
    sedeSeleccionada = sedeGuardada;
  }
  
  // Restaurar filtro de estado desde localStorage
  const estadoGuardado = localStorage.getItem("estado_filtro_seleccionado");
  if (estadoGuardado) {
    document.getElementById("filtroEstado").value = estadoGuardado;
    estadoFiltroSeleccionado = estadoGuardado;
  }
  
  // Restaurar conexión si estaba conectada antes del reload
  const estabaConectado = localStorage.getItem("dashboard_conectado");
if (estabaConectado === "true") {
  console.log("🔄 Reestableciendo conexión automática...");

  if (sedeSeleccionada) {
    setTimeout(async () => {
      await ensureToken();
      await conectarD();
    }, 800);
  } else {
    mostrarToast('⚠️ Selecciona una sede antes de reconectar automáticamente');
  }
}

});



// 🟢 FUNCIÓN CORREGIDA - Conectar Dashboard
async function conectarD() {
  if (!sedeSeleccionada) {
    mostrarToast('⚠️ Debes seleccionar una sede antes de conectar');
    return;
  }

  try {
    // PRIMERO: obtener token obligatorio
    await ensureToken();

    // SEGUNDO: conectar WebSocket
    const ok = await conectarWS();

    if (ok) {
      await aplicarFiltroSede();
      conectado = true;
      localStorage.setItem("dashboard_conectado", "true");
    } else {
      conectado = false;
      localStorage.setItem("dashboard_conectado", "false");
      mostrarToast("❌ No se pudo conectar al servidor WebSocket");
    }
  } catch (err) {
    console.error("Error al conectar:", err);
    mostrarToast("❌ Error general al conectar");
  }
}

// 🔴 FUNCIÓN CORREGIDA - Desconectar
function desconectar() {
  // Sólo preguntar confirmación si hay conexión abierta
  if (typeof ws === "undefined" || !ws || ws.readyState !== WebSocket.OPEN) {
    mostrarToast("⚠️ No hay conexión WebSocket activa");
    return;
  }
  if ($cambioSedeEnProgreso) {
    console.log("🔄 Cambio de sede: desconectando sin confirmación...")
        try {
      // Marcar como desconexión manual para evitar reintentos
      try { window.manualDisconnect = true; window.reconnecting = false; } catch(e){}

      ws.close();
      console.log("🔌 Reconectando...");
      conectado = false;
      // Limpiar flag de conexión en localStorage
      localStorage.setItem("dashboard_conectado", "false");

      // Limpiar caché de sesiones y mostrar vista desconectada
      try {
        sesiones = [];
      } catch (e) {
        console.warn('No se pudo vaciar sesiones:', e);
      }
      // Mostrar la vista de servidor desconectado y limpiar la tabla
      try {
        mostrarDesconectado();
      } catch (e) {
        console.warn('mostrarDesconectado no disponible:', e);
      }

      // Actualizar UI
      const btn = document.querySelector("#toggleBtn");
      const dot = document.querySelector("#statusDot");
      btn.textContent = "Conectar";
      btn.classList.remove("btn-success");
      btn.classList.add("btn-outline-danger");
      if (dot) dot.style.background = "#d00";
    } catch (err) {
      console.error("Error al cerrar WebSocket:", err);
      mostrarToast("❌ Error al desconectar WebSocket");
    }
  }else {
  if (confirm("⚠️ ¿Deseas desconectar del servidor WebSocket?")) {
    try {
      // Marcar como desconexión manual para evitar reintentos
      try { window.manualDisconnect = true; window.reconnecting = false; } catch(e){}

      ws.close();
      console.log("🔌 Conexión WebSocket cerrada correctamente.");
      conectado = false;
      // Limpiar flag de conexión en localStorage
      localStorage.setItem("dashboard_conectado", "false");

      // Limpiar caché de sesiones y mostrar vista desconectada
      try {
        sesiones = [];
      } catch (e) {
        console.warn('No se pudo vaciar sesiones:', e);
      }
      // Mostrar la vista de servidor desconectado y limpiar la tabla
      try {
        mostrarDesconectado();
      } catch (e) {
        console.warn('mostrarDesconectado no disponible:', e);
      }

      // Actualizar UI
      const btn = document.querySelector("#toggleBtn");
      const dot = document.querySelector("#statusDot");
      btn.textContent = "Conectar";
      btn.classList.remove("btn-success");
      btn.classList.add("btn-outline-danger");
      if (dot) dot.style.background = "#d00";
    } catch (err) {
      console.error("Error al cerrar WebSocket:", err);
      mostrarToast("❌ Error al desconectar WebSocket");
    }
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
    // Registrar en log en lugar de toast informativo para evitar ruido
    try {
      agregarLog("🟢 " + data.mensaje, 'info'); } catch(e) { console.log(data.mensaje); }
    conectado_server = true;
    } else if (data.status === "detenido") {
      mostrarDesconectado();
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
  celda.colSpan = 8;
  celda.style.textAlign = "center";
  celda.style.padding = "10px";
  celda.innerHTML = `
    <td colspan="8" style="text-align:center; padding:10px;">
    <img src="./assets/img/images.png" alt="Servidor desconectado" style="width:850px; opacity:0.4;">
    <p style="color:green; font-size:3.8em; margin-top:10px;">Servidor desconectado</p>
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
    // 1) pedir stats (aplicar filtro por sede sólo si estamos conectados por WS)
    let statsUrl = "./dashboard_stats.php";
    if (sedeSeleccionada && typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
      statsUrl += `?id_p_servicio=${sedeSeleccionada}`;
    }
    const statsRes = await fetch(statsUrl);
    const stats = await statsRes.json();
    actualizarStats(stats);
    
    // 2) pedir sesiones (aplicar filtro por sede sólo si estamos conectados por WS)
    let sedesUrl = "./get_sesiones.php";
    if (sedeSeleccionada && typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
      sedesUrl += `?id_p_servicio=${sedeSeleccionada}`;
    }
    const sesionesRes = await fetch(sedesUrl);
    const sesionesData = await sesionesRes.json();
    if (Array.isArray(sesionesData)) {
      console.log("📥 Sesiones recibidas via HTTP:", sesionesData.length);
      sesiones = sesionesData; // Actualizar caché global
      actualizarTabla(sesionesData);
    } else {
      console.warn("❌ Respuesta de sesiones no es array:", sesionesData);
    }
  } catch (err) {
    console.warn("❌ No se pudo cargar estado via HTTP:", err);
    mostrarDesconectado();
    actualizarStats({ Abierto: 0, Suspendido: 0, Bloqueado: 0, Hibernado: 0, Finalizado: 0 });
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
  document.getElementById("stat-Hibernado").textContent = stats.Hibernado ?? 0;
  document.getElementById("stat-finalizado").textContent =
    stats.Finalizado ?? 0;
}

// === Actualizar datos manualmente ===
function actualizarDatos() {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(
      JSON.stringify({
        tipo: "actualizar",
        origen: "dashboard",
      })
    );
    mostrarToast("🔄 Datos actualizados manualmente (filtros de sede y estado mantienen vigencia)");
  } else {
    mostrarToast("⚠️ WebSocket no conectado");
  }
}

async function manejoServidor() {
  try {
    // Esperar la respuesta real
    const server = await verificarServidor();

    console.log("Respuesta servidor:", server);

    // Validar que sí haya respuesta
    if (!server || !server.status) {
      console.warn("⚠ No se obtuvo respuesta válida del servidor.");
      return;
    }

    const estado = server.status;
    console.log("Estado servidor:", estado);

    // Si el servidor NO está corriendo -> iniciarlo
    if (estado === "detenido" || estado === "error" || estado === "apagado") {
      await iniciarServidor();
    }

    // Si está activo -> permitir detenerlo
    if (estado === "ya_corriendo" || estado === "iniciado" || estado === "corriendo") {
      await detenerServidor();
    }

    console.log("Manejo servidor ejecutado");
  } catch (e) {
    console.error("❌ Error en manejoServidor():", e);
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
async function actualizarTabla(sesiones) {
  const tbody = document.querySelector("#tablaSesiones tbody");
  if (!tbody) {
    console.error("❌ No se encontró tbody en la tabla");
    return;
  }
  // Si no pasan sesiones, intentamos obtener vía HTTP o WS
  if (!sesiones || sesiones.length === 0) {
    console.log("ℹ️ No hay sesiones, intentando obtener...");
    // Si WS está abierto, el heartbeat se encargará de la actualización
    if (typeof ws !== "undefined" && ws && ws.readyState === WebSocket.OPEN) {
      // El heartbeat cada 8s se encargará de actualizar
      return;
    } else {
      // fallback HTTP - solo si WS no está disponible
      fetchEstado(sedeSeleccionada);
    }
  }

  // Si hay una sede seleccionada y estamos conectados por WS, mantener ese filtro
  if (sedeSeleccionada && typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
    // Si los objetos de sesión contienen el id del punto de servicio, filtramos localmente
    const hasServiceField = Array.isArray(sesiones) && sesiones.some(s => s.id_p_servicio !== undefined || s.id_p_servicio_fk !== undefined);
    if (hasServiceField) {
      sesiones = sesiones.filter(s => String(s.id_p_servicio || s.id_p_servicio_fk) === String(sedeSeleccionada));
    } else {
      // Si los objetos no traen id de servicio (por ejemplo mensajes WS antiguos), pedir via HTTP filtrado
      try {
        const res = await fetch(`./get_sesiones.php?id_p_servicio=${sedeSeleccionada}`);
        const data = await res.json();
        if (Array.isArray(data)) {
          sesiones = data;
          // Actualizar caché global
          window.sesiones = data;
        }
      } catch (err) {
        console.warn('❌ Error obteniendo sesiones filtradas por sede via HTTP:', err);
      }
    }
  }

  // Aplicar filtro de estado si está seleccionado
  let sesionesFiltradasPorEstado = sesiones;
  if (estadoFiltroSeleccionado) {
    sesionesFiltradasPorEstado = sesiones.filter(s => {
      const estado = String(s.nombre_estado || "").toLowerCase();
      return estado.includes(String(estadoFiltroSeleccionado).toLowerCase());
    });
  }

  tbody.innerHTML = "";

  sesionesFiltradasPorEstado.forEach((s) => {
    const tr = document.createElement("tr");
    // 🟢 AGREGAR DATA ATTRIBUTES PARA DEBUGGING
    tr.setAttribute("data-sesion-id", s.id);
    tr.setAttribute("data-username", s.username || "");
    tr.setAttribute("data-estado", s.nombre_estado || "");
    tr.setAttribute("data-pc", s.nombre_pc || "");

    tr.innerHTML = `
            <td class="text-${estadoColor(s.nombre_estado)}">${s.id}</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${
      s.username ?? s.usuario ?? "-"
    }</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${
      s.nombre_pc || "Desconocido"
    }</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${
      s.fecha_inicio || "-"
    }</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${
      s.fecha_final_programada || "-"
    }</td>
            <td class="text-${estadoColor(s.nombre_estado)}">${
      s.fecha_final_real || "-"
    }</td>
            <td><span class="badge bg-${estadoColor(s.nombre_estado)}">${
      s.nombre_estado || "-"
    }</span></td>
            <td>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown" 
                            onclick="console.log('🖱️ Click en acciones para sesión:', ${
                              s.id
                            }, 'usuario:', '${s.username}')">
                        ⚙️
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="verInfo(${
                          s.id
                        })">🔍 Ver Info</a></li>
                        <li><a class="dropdown-item" href="#" onclick="accionSesion(${
                          s.id
                        }, 'suspender')">⏸ Suspender</a></li>
                        <li><a class="dropdown-item" href="#" onclick="accionSesion(${
                          s.id
                        }, 'renovar')">♻️ Renovar</a></li>
                        <li><a class="dropdown-item" href="#" onclick="accionSesion(${
                          s.id
                        }, 'finalizar')">⛔ Finalizar</a></li>
                        <li><a class="dropdown-item" href="#" onclick="accionSesion(${
                          s.id
                        }, 'bloquear')">🚫 Bloquear</a></li>
                    </ul>
                </div>
            </td>
        `;
    tbody.appendChild(tr);
  });
}

// 📝 FUNCIÓN NUEVA - Ver Info con Datos Reales
function verInfo(id) {
  // Obtener información detallada del servidor
  fetch("./dashboard_action.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ accion: "info", id: id }),
  })
    .then((res) => res.json())
    .then((data) => {
      if (data.status === "ok" && data.data) {
        const info = data.data;

        // Llenar información del usuario
        document.getElementById("usuarioInfo").innerHTML = `
        <li><strong>ID:</strong> ${info.id}</li>
        <li><strong>Usuario:</strong> ${info.username || "N/A"}</li>
        <li><strong>Estado:</strong> ${info.nombre_estado || "N/A"}</li>
        <li><strong>Inicio:</strong> ${info.fecha_inicio || "N/A"}</li>
        <li><strong>Fin Programado:</strong> ${
          info.fecha_final_programada || "N/A"
        }</li>
      `;

        // Llenar información del computador (puedes expandir esto)
        document.getElementById("computadorInfo").innerHTML = `
        <li><strong>IP:</strong> Por implementar</li>
        <li><strong>Hostname:</strong> Por implementar</li>
        <li><strong>Sistema:</strong> Por implementar</li>
        <li><strong>Última Actividad:</strong> ${
          info.fecha_inicio || "N/A"
        }</li>
      `;

        // Mostrar modal
        var myModal = new bootstrap.Modal(document.getElementById("modalInfo"));
        myModal.show();
      } else {
        mostrarToast(
          "❌ No se pudo obtener información de la sesión",
          "danger"
        );
      }
    })
    .catch((err) => {
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
    case "Hibernado":
      return "info";
    case "Finalizado":
      return "dark";
    default:
      return "light";
  }
}

function filtrarTabla() {
  const filtro = document.getElementById("filtroEstado").value.toLowerCase();
  // Guardar el filtro en localStorage para que persista en actualizaciones
  if (filtro) {
    localStorage.setItem("estado_filtro_seleccionado", filtro);
    estadoFiltroSeleccionado = filtro;
  } else {
    localStorage.removeItem("estado_filtro_seleccionado");
    estadoFiltroSeleccionado = null;
  }
  document.querySelectorAll("#tablaSesiones tbody tr").forEach((tr) => {
    // El estado está en la celda 6 (contando desde 0: ID, Usuario, Equipo, Inicio, FinalProgramado, FinalReal, Estado)
    const estadoCell = tr.querySelector(".badge");
    const estado = estadoCell ? estadoCell.textContent.toLowerCase() : "";
    tr.style.display = !filtro || estado.includes(filtro) ? "" : "none";
  });
}

// 🏢 CAMBIAR SEDE Y FILTRAR SESIONES
function cambiarSede() {
  // Al cambiar la sede desde el selector, únicamente guardamos la selección
  // No se realiza filtrado ni fetch hasta que haya una conexión activa
  const sedeSelect = document.getElementById("selectSede");
  if (!sedeSelect) return;

  const sedeId = sedeSelect.value;

  if (!sedeId) {
    // Limpiar selección
    sedeSeleccionada = null;
    localStorage.removeItem("sede_seleccionada");
    mostrarToast("Selección de sede cancelada");
    return;
  }

  // Guardamos la selección localmente y mostramos un aviso. El filtrado
  // real se aplicará solo cuando se establezca la conexión (botón "Conectar").
  sedeSeleccionada = sedeId;
  localStorage.setItem("sede_seleccionada", sedeId);
  // Si ya estamos conectados via WebSocket, aplicar el filtro al instante
  try {
    if (typeof ws !== 'undefined' && ws && ws.readyState === WebSocket.OPEN) {
      console.log('WebSocket abierto: aplicando filtro de sede inmediatamente al cambiar sede y reconectando...');
      desconectar($cambioSedeEnProgreso = true); // Desconectar para reiniciar con nueva sede
      conectarD(); // Reconectar con la nueva sede
    }
  } catch (err) {
    console.warn('⚠️ Error comprobando estado de WebSocket al cambiar sede:', err);
  }
}

// Aplica el filtro de sede: fetch y actualización de tabla. Esta función se
// debe llamar únicamente cuando la conexión WebSocket esté establecida.
async function aplicarFiltroSede() {
  if (!sedeSeleccionada) return;

  // Si WS no está abierto, no intentamos aplicar el filtro aquí
  if (typeof ws === 'undefined' || !ws || ws.readyState !== WebSocket.OPEN) {
    console.warn('⚠️ aplicarFiltroSede: WebSocket no conectado, no se aplica filtro');
    return;
  }

  const sedeId = sedeSeleccionada;
  try {
    const res = await fetch(`./get_sesiones.php?id_p_servicio=${sedeId}`);
    const data = await res.json();
    if (Array.isArray(data)) {
      console.log(`📥 Sesiones cargadas para sede ${sedeId}:`, data.length);
      sesiones = data; // Actualizar caché global;
      mostrarToast(`Sesiones filtradas por sede: ${sedeId}`);
    } else {
      console.warn('Respuesta no es array:', data);
      mostrarToast('Error al cargar sesiones');
    }
  } catch (err) {
    console.error('❌ Error cargando sesiones por sede:', err);
    mostrarToast('Error al cargar sesiones');
  }
}

// 🎯 FUNCIÓN MEJORADA - Acción Sesión con Debugging Completo
function accionSesion(id, accion) {
  console.log(`🎯 Iniciando acción: ${accion} para sesión: ${id}`);

  // Obtener información de la fila para debugging
  const fila = document.querySelector(`tr[data-sesion-id="${id}"]`);
  const username = fila ? fila.getAttribute("data-username") : "desconocido";
  const estado = fila ? fila.getAttribute("data-estado") : "desconocido";
  const nombre_pc = fila ? fila.getAttribute("data-pc") : "desconocido";

  console.log(
    `📋 Detalles sesión - ID: ${id}, Usuario: ${username}, Estado: ${estado}`
  );

  if (
    !confirm(
      `¿Estás seguro de ejecutar '${accion}' en la sesión ${id} del equipo (${nombre_pc})`
    )
  ) {
    console.log("❌ Usuario canceló la acción");
    return;
  }

  // 🟢 1️⃣ Primero enviar comando via WebSocket (para ejecución inmediata en PowerShell)
  if (typeof ws !== "undefined" && ws && ws.readyState === WebSocket.OPEN) {
    const payload = {
      tipo: "comando",
      accion: accion, // suspender, bloquear, etc.
      nombre_eq: nombre_pc,
      id_eq: id, // nombre del equipo o ID
      origen: "dashboard",
      destino: "server",
      corr: Date.now(),
      id_p_servicio: sedeSeleccionada ? parseInt(sedeSeleccionada) : null,
      timestamp: new Date().toISOString(),
    };

    console.log("📡 Enviando comando WebSocket:", payload);
    ws.send(JSON.stringify(payload));
    mostrarToast(
      `⚡ Comando ${accion} enviado a equipo ${username} (Sede: ${sedeSeleccionada || 'N/A'})`,
      "success"
    );
  } else {
    console.error("❌ WebSocket no disponible para enviar comando");
    mostrarToast("⚠️ WebSocket desconectado - Comando no enviado", "warning");
  }
}

function guardarConfig() {
  const tiempo = document.getElementById("config-tiempo").value;
  const clave = document.getElementById("config-clave").value;
  localStorage.setItem("config-tiempo", tiempo);
  localStorage.setItem("config-clave", clave);
  mostrarToast("💾 Configuración guardada localmente");
}

async function enviarMensaje() {
  const texto = document.getElementById("mensajeTexto").value.trim();
  const destinoInput =
    document.getElementById("mensajeDestino").value || "todos";
  const destinoId = String(destinoInput).trim();

  if (!texto) return mostrarToast("⚠️ Escribe un mensaje primero");

  // 🧠 Si el destino es "todos", enviamos broadcast
  if (destinoId.toLowerCase() === "todos") {
    enviarMensajeATodos(texto);
    return;
  }

  // 🔍 Verificar que haya sesiones activas
  if (!Array.isArray(sesiones)) {
    sesiones = [];
  }

  // 🔎 Buscar la sesión seleccionada por ID en la caché
  let sesion = sesiones.find((s) => String(s.id) === destinoId);

  // Si no está en caché, pedir al servidor la info puntual
  if (!sesion) {
    try {
      mostrarToast(`🔎 Buscando información de la sesión ${destinoId} en la base de datos...`);
      const res = await fetch("./dashboard_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ accion: "info", id: destinoId }),
      });
      const data = await res.json();
      if (data && data.status === "ok" && data.data) {
        sesion = data.data;
        // guardar en caché para futuras operaciones
        sesiones.push(sesion);
        console.log("🔁 Sesión añadida a caché:", sesion);
      } else {
        console.warn("❌ No se encontró la sesión en BD:", data);
        mostrarToast(`⚠️ Sesión ${destinoId} no encontrada en BD`);
        return;
      }
    } catch (err) {
      console.error("❌ Error consultando dashboard_action.php:", err);
      mostrarToast("❌ Error consultando la sesión en el servidor");
      return;
    }
  }

  // 🖥️ Extraer nombre del PC (que es el ID de cliente en el servidor)
  const nombrePC = sesion.nombre_pc || sesion.nombre_equipo || null;

  if (!nombrePC) {
    console.warn("⚠️ La sesión no contiene nombre de equipo válido:", sesion);
    mostrarToast("⚠️ No se pudo determinar el nombre del equipo destino.");
    return;
  }

  // 🚀 Enviar comando al servidor usando el nombre del PC como destino
  if (typeof ws !== "undefined" && ws && ws.readyState === WebSocket.OPEN) {
    ws.send(
      JSON.stringify({
        tipo: "comando",
        origen: "dashboard",
        destino: "server",
        accion: "mensaje",
        mensaje: texto,
        destino: nombrePC,
        id_p_servicio: sedeSeleccionada ? parseInt(sedeSeleccionada) : null,
      })
    );

    mostrarToast(`📨 Mensaje enviado a ${nombrePC} (Sede: ${sedeSeleccionada || 'N/A'}) (Texto: ${texto})`);
    document.getElementById("mensajeTexto").value = "";
  } else {
    mostrarToast("⚠️ No conectado al WS");
  }
}

function enviarMensajeATodos() {
  const texto = document.getElementById("mensajeTexto").value.trim();
  if (!texto) return mostrarToast("⚠️ Escribe un mensaje primero");
  if (typeof ws !== "undefined" && ws.readyState === WebSocket.OPEN) {
    ws.send(
      JSON.stringify({
        tipo: "comando",
        origen: "dashboard",
        destino: "server",
        accion: "mensaje",
        mensaje: texto,
        destino: "todos",
        id_p_servicio: sedeSeleccionada ? parseInt(sedeSeleccionada) : null,
      })
    );
    mostrarToast(`Mensaje enviado a todos en Sede: ${sedeSeleccionada || 'N/A'}`);
    document.getElementById("mensajeTexto").value = "";
  } else mostrarToast("⚠️ No conectado al WS");
}

function mostrarToast(msg, tipo = "info") {
  // Crear elemento toast
  const toast = document.createElement("div");
  toast.className = `toast-message toast-${tipo}`;
  
  // Contenido con icono según tipo
  const iconos = {
    "success": "✅ ",
    "warning": "⚠️ ",
    "danger": "❌ ",
    "error": "❌ ",
    "info": "ℹ️ "
  };
  
  toast.textContent = (iconos[tipo] || "") + msg;
  
  // Almacenar en cola
  window.toastQueue.push(toast);
  
  // Ajustar posición y limitar visibles
  actualizarPosicionesToasts();
  
  // Auto-remover tras duración
  setTimeout(() => {
    removerToast(toast);
  }, window.TOAST_DURATION);
}

function actualizarPosicionesToasts() {
  // Limitar cantidad de toasts visibles
  const visibles = window.toastQueue.slice(-window.MAX_TOASTS_VISIBLE);
  
  // Si hay más toasts que el máximo, remover los antiguos
  if (window.toastQueue.length > window.MAX_TOASTS_VISIBLE) {
    const paraRemover = window.toastQueue.slice(0, window.toastQueue.length - window.MAX_TOASTS_VISIBLE);
    paraRemover.forEach(t => {
      try { if (t.parentNode) t.parentNode.removeChild(t); } catch (e) {}
    });
    window.toastQueue = visibles;
  }
  
  // Agregar toasts nuevos al DOM si no están ya
  visibles.forEach((toast, idx) => {
    if (!toast.parentNode) {
      document.body.appendChild(toast);
    }
    // Actualizar posición en relación a otros toasts
    const bottomOffset = 10 + (idx * 80); // 20px margen + 80px por cada toast (altura + gap)
    toast.style.bottom = bottomOffset + "px";
  });
}

function removerToast(toast) {
  // Remover de la cola
  const idx = window.toastQueue.indexOf(toast);
  if (idx > -1) {
    window.toastQueue.splice(idx, 1);
  }
  
  // Agregar clase de salida si existe
  try {
    toast.classList.add("toast-exit");
    // Esperar animación de salida antes de remover del DOM
    setTimeout(() => {
      try { if (toast.parentNode) toast.parentNode.removeChild(toast); } catch (e) {}
    }, 300);
  } catch (e) {
    // Si no hay soporte de classList, solo remover
    try { if (toast.parentNode) toast.parentNode.removeChild(toast); } catch (err) {}
  }
  
  // Reajustar posiciones de los restantes
  actualizarPosicionesToasts();
}

function agregarLog(mensaje, tipo = "info") {
  // Usaremos una cola FIFO con timestamps numéricos para asegurar
  // que los mensajes se muestren uno debajo del otro (append) y se
  // eliminen en orden de llegada.
  const ts = Date.now();

  // Crear entrada visual
  const entry = document.createElement("div");
  entry.className = `log-entry log-${
    tipo === "error" ? "danger" : tipo === "success" ? "success" : tipo === "warning" ? "warning" : "info"
  }`;

  const timeSpan = document.createElement("span");
  timeSpan.className = "log-time";
  timeSpan.textContent = new Date(ts).toLocaleTimeString();

  const msgSpan = document.createElement("span");
  msgSpan.className = "log-message";
  msgSpan.textContent = " " + mensaje;

  entry.appendChild(timeSpan);
  entry.appendChild(msgSpan);

  // Si existe el placeholder (p), limpiarlo
  if (logContainer.querySelector("p")) logContainer.innerHTML = "";

  // Añadimos al final para que el orden visual coincida con FIFO
  logContainer.appendChild(entry);

  // Guardar en la cola
  logs.push({ mensaje, tipo, ts, node: entry });

  // Ejecutar limpieza inmediata por si hay exceso
  limpiarLogsViejos();
}

function limpiarLogsViejos() {
  const ahora = Date.now();
  const TTL = 90 * 1000; // tiempo de vida de cada log en ms (90s)
  const MAX_LOGS = 200; // mantener límite razonable

  // Eliminar entradas expiradas por orden FIFO
  while (logs.length > 0 && ahora - logs[0].ts >= TTL) {
    const old = logs.shift();
    try {
      if (old.node && old.node.parentNode === logContainer) {
        logContainer.removeChild(old.node);
      }
    } catch (e) {
      console.warn('Error removiendo log antiguo:', e);
    }
  }

  // Si hay demasiados logs, eliminar los más antiguos hasta el límite
  while (logs.length > MAX_LOGS) {
    const old = logs.shift();
    try {
      if (old.node && old.node.parentNode === logContainer) {
        logContainer.removeChild(old.node);
      }
    } catch (e) {
      console.warn('Error removiendo log por exceso:', e);
    }
  }

  // Si no quedan logs, mostrar placeholder
  if (logs.length === 0) {
    logContainer.innerHTML = "<p class='text-muted'>Sin registros recientes...</p>";
  }
}

// al cargar dashboard: pedir token si no existe
async function ensureToken() {

    const sedeSeleccionada = localStorage.getItem("sede_seleccionada");
    let token = localStorage.getItem("autoprestamos_jwt_token");

    // Si ya existe token, salir
    if (token && token !== "null" && token.trim() !== "") {
        return token;
    }

    // Validar sede
    if (!sedeSeleccionada) {
        console.error("❌ No hay sede seleccionada para generar token");
        mostrarToast("⚠️ Selecciona una sede antes de conectar", "warning");
        return null;
    }

    try {
const tokenResponse = await fetch("../servers/token.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "sede=" + encodeURIComponent(localStorage.getItem("sede_seleccionada"))
});

        // Validar respuesta vacía
        const text = await tokenResponse.text();
        if (!text || text.trim() === "") {
            console.error("❌ token.php devolvió vacío");
            mostrarToast("❌ No se pudo obtener token", "danger");
            return null;
        }

        let data;
        try {
            data = JSON.parse(text);
        } catch (err) {
            console.error("❌ token.php devolvió algo que NO es JSON:", text);
            mostrarToast("❌ Error interpretando token", "danger");
            return null;
        }

        if (data.token) {
            localStorage.setItem("autoprestamos_jwt_token", data.token);
            console.log("🔐 Token generado correctamente:", data.token);
            return data.token;
        } else {
            console.error("❌ token.php dice error:", data);
            mostrarToast("❌ Error generando token", "danger");
            return null;
        }

    } catch (err) {
        console.error("❌ Error solicitando token:", err);
        mostrarToast("❌ No se pudo solicitar token", "danger");
        return null;
    }
}

// Ejecutar limpieza periódica para garantizar FIFO y expiración
setInterval(() => {
  try {
    limpiarLogsViejos();
  } catch (e) {
    console.error('Error en limpieza periódica de logs:', e);
  }
}, 20000);

// Función pública para limpiar manualmente todos los logs (invocada desde UI)
function limpiarLogs() {
  try {
    logs.forEach(l => {
      try { if (l.node && l.node.parentNode === logContainer) logContainer.removeChild(l.node); } catch(e){}
    });
    logs = [];
    logContainer.innerHTML = "<p class='text-muted'>Sin registros recientes...</p>";
    mostrarToast('🧹 Logs limpiados');
  } catch (err) {
    console.error('Error limpiando logs:', err);
    mostrarToast('❌ Error limpiando logs');
  }
}

// Helper debugging function placeholder
function debugWebSocket(){
  agregarLog('Debug WS: estado actual de ws: ' + (typeof ws !== 'undefined' ? (ws.readyState || 'sin readyState') : 'no definido'), 'info');
}

// 📡 FUNCIÓN DE ENVÍO DE ESTADO - Procesamiento silencioso de datos
function enviarEstado(data) {
  if (!data) return;
  
  try {
    // Procesar datos con el mismo patrón que onmessage
    if (data.tipo === "estado") {
      actualizarTabla(data.sesiones || []);
      if (data.stats) actualizarStats(data.stats);
      console.log("📊 Estado actualizado silenciosamente");
    } else if (data.tipo === "confirmacion" || data.tipo === "respuesta") {
      // Procesar confirmación silenciosamente (sin toast)
      console.log("✅ Respuesta procesada:", data);
      // Actualizar estado si es necesario
      if (typeof ws !== "undefined" && ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({
          tipo: "actualizar",
          origen: "dashboard",
        }));
      }
    } else if (data.tipo === "error") {
      console.warn("⚠️ Error:", data.mensaje);
    }
  } catch (err) {
    console.error("❌ Error procesando enviarEstado:", err);
  }
}

// 📡 FUNCIÓN DE ENVÍO DE ESTADO A TODOS - Procesamiento silencioso
function enviarEstadoTodos(data) {
  if (!data) return;
  
  try {
    // Aplicar el mismo patrón de validación y procesamiento
    if (Array.isArray(data)) {
      // Si es un array de sesiones, actualizar tabla
      actualizarTabla(data);
      console.log("📊 Estado de todos actualizado silenciosamente");
    } else if (typeof data === "object" && data.sesiones) {
      // Si es un objeto con sesiones y stats
      actualizarTabla(data.sesiones || []);
      if (data.stats) actualizarStats(data.stats);
      console.log("📊 Estado global actualizado silenciosamente");
    } else {
      console.warn("⚠️ Formato de datos inesperado en enviarEstadoTodos:", data);
    }
  } catch (err) {
    console.error("❌ Error procesando enviarEstadoTodos:", err);
  }
}

// al cargar la página: mostrar desconectado y cargar estado HTTP como fallback
document.addEventListener("DOMContentLoaded", () => {
  mostrarDesconectado();
  document.getElementById("fechaActual").textContent =
    new Date().toLocaleString();
});