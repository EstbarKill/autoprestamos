<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard Unisimón</title>
  <link rel="stylesheet" href="./assets/css/dashboard.css" />
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet" />
</head>
<!-- 🔝 NAV SUPERIOR -->
<nav id="topbar" class="d-flex justify-content-between align-items-center px-4 py-2 shadow-sm">
  <div class="d-flex align-items-center gap-2">
    <img src="./assets/img/logo.png" alt="Unisimón" height="40" />
    <h5 class="m-0 fw-bold text-success">Universidad Simón Bolívar</h5>
  </div>
  <div class="d-flex align-items-center gap-3">
    <span id="fechaActual" class="fw-semibold"></span>
    <span id="statusDot" style="width:15px;height:15px;border-radius:50%;display:inline-block;background:#d00;"></span>
    <span id="statusText" class="fw-bold text-success"></span>
    
    <!-- 🏢 SELECT PUNTO DE SERVICIO -->
    <select id="selectSede" class="form-select w-auto" onchange="cambiarSede()">
      <option value="">-- Seleccionar / General --</option>
      <option value="1">📚 Biblioteca Central José Martí</option>
      <option value="2">📰 Hemeroteca Ana Bolivar de Consuegra</option>
      <option value="3">🏛️ Biblioteca de Posgrado</option>
    </select>
    
    <button id="toggleBtn" class="btn btn-outline-danger md-1" onclick="toggleServidor()">Conectar</button>
  </div>
</nav>

<body>
  <div id="app" class="d-flex">
    <!-- === SIDEBAR === -->
    <aside id="sidebar" class="bg-success text-white p-3">
      <div class="text-center mb-4">
        <img src="./assets/img/logo.png" class="logo" alt="Unisimón" />
        <h5 class="mt-2 fw-bold">Dashboard Unisimón</h5>
      </div>

      <div class="menu">
        <button class="btn btn-light w-100 mb-2" onclick="mostrarPagina('panel')">
          <i class="bi bi-speedometer2"></i> Panel General
        </button>
        <button class="btn btn-light w-100 mb-2" onclick="mostrarPagina('config')">
          <i class="bi bi-gear"></i> Configuración
        </button>
        <!-- Registros movidos a la barra lateral (siempre visibles cuando WS activo) -->
        <button class="btn btn-light w-100 mb-2" onclick="mostrarPagina('mensajes')">
          <i class="bi bi-chat-dots"></i> Mensajes
        </button>
        <button class="bi bi-play btn btn-light w-100 mb-2" id="btnEncenderServidor" onclick="manejoServidor()">
            Apagado
        </button> 
      </div>
      <hr class="text-white" />
      <div id="stats" class="mt-auto">
        <h6 class="text-uppercase fw-bold mb-2">Estadísticas</h6>
        <ul class="list-unstyled" id="stats-list">
          <li>🟢 Abiertos: <span id="stat-abierto">0</span></li>
          <li>🟡 Suspendidos: <span id="stat-suspendido">0</span></li>
          <li>🔴 Bloqueados: <span id="stat-bloqueado">0</span></li>
          <li>⚫ Finalizados: <span id="stat-finalizado">0</span></li>
        </ul>
              
      <!-- Logs en tiempo real: siempre visibles en la barra lateral cuando el dashboard está conectado -->
      <div id="sidebar-logs" class="mt-3">
        <div class="card" style="background:rgba(255, 255, 255, 0.92); border:none;">
          <div class="card-header d-flex justify-content-between align-items-center bg-transparent border-0 p-2">
            <strong class="m-0">📋 Registros</strong>
            <button class="btn btn-sm btn-light" onclick="limpiarLogs()">🗑️</button>
          </div>
          <div class="card-body p-2" style="max-height:260px; overflow:auto; font-family: 'Courier New', monospace; font-size:12px;">
            <div id="logContainer">
              <p class="text-white-50 small mb-0">Esperando registros...</p>
            </div>
          </div>
        </div>
      </div>
      </div>
  </aside>
<!-- NOTE: El panel de registros fue movido a la barra lateral. -->
    <!-- === CONTENIDO === -->
    <main id="main" class="flex-grow-1 p-4">
      <div id="pagina-panel" class="pagina visible">
        <h2 class="mb-3">📊 Monitoreo de Equipos  </h2>

        <div class="filtros mb-3">
          <select id="filtroEstado" class="form-select w-auto d-inline" onchange="filtrarTabla()">
            <option value="">Todos los estados</option>
            <option value="Abierto">Abierto</option>
            <option value="Suspendido">Suspendido</option>
            <option value="Bloqueado">Bloqueado</option>
            <option value="Finalizado">Finalizado</option>
          </select>
          <button class="btn btn-outline-success ms-2" onclick="actualizarDatos()">🔄 Actualizar</button>
        </div>
<!-- Modal de Información -->
<div class="modal" tabindex="-1" id="modalInfo" aria-labelledby="modalInfoLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalInfoLabel">Información de la Sesión</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row">
          <!-- Información del Usuario (izquierda) -->
          <div class="col-md-6">
            <h4>Información del Usuario</h4>
            <ul id="usuarioInfo" class="list-unstyled">
              <!-- Aquí se llenarán los datos del usuario dinámicamente -->
            </ul>
          </div>
          
          <!-- Información del Computador (derecha) -->
          <div class="col-md-6">
            <h4>Información del Computador</h4>
            <ul id="computadorInfo" class="list-unstyled">
              <!-- Aquí se llenarán los datos del computador dinámicamente -->
            </ul>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
        <div class="tabla-container shadow">
          
          <table class="table table-hover align-middle text-center" id="tablaSesiones">
            <thead class="table-success text-white">
              <tr>
                <th>ID</th>
                <th>Usuario</th>
                <th>Equipo</th>
                <th>Inicio</th>
                <th>Final Programado</th>
                <th>Hora de Finalizacion</th>
                <th>Estado</th>
                <th>Bloqueado hasta</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

<!-- === Configuración === -->

 <div id="pagina-config" class="pagina">
  <h2>⚙️ Configuración</h2>
  <p>Parámetros del sistema:</p>
  <div class="config-box">
    <label>Tiempo de sesión (segundos):</label>
    <input type="number" id="config-tiempo" class="form-control mb-2" value="30" />
    <label>Clave de administrador:</label>
    <input type="password" id="config-clave" class="form-control mb-3" value="S1m0n_2025" />
    <button class="btn btn-success" onclick="guardarConfig()">💾 Guardar configuración</button>
    </div>
   
  </div>



      <div id="pagina-mensajes" class="pagina">
        <h2>💬 Mensajes a Equipos</h2>
        <textarea id="mensajeTexto" class="form-control mb-3" placeholder="Escribe un mensaje para enviar..."></textarea>
        <div class="d-flex gap-2">
          <input type="number" id="mensajeDestino" class="form-control w-auto" placeholder="ID equipo (opcional)" />
          <button class="btn btn-success" onclick="enviarMensaje()">📤 Enviar</button>
          <button class="btn btn-warning" onclick="enviarMensajeATodos()">🌍 Enviar a todos</button>
        </div>
        <div id="solicitudesContainer" class="solicitudes-box">
    <h3>Solicitudes de Renovación</h3>
    <table id="tablaSolicitudes">
        <thead>
            <tr>
                <th id="sesionId">PC</th>
                <th>Hora</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="3" style="text-align:center; color:#777">Sin solicitudes</td></tr>
        </tbody>
    </table>
</div>

      </div>
    </main>
    <script src="./assets/js/websocket.js"></script>
  <script src="./assets/js/dashboard.js"></script>
</body>

</html>
