<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard Unisimón</title>
  <link rel="stylesheet" href="./assets/css/dashboard.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet" />
</head>

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
        <button class="btn btn-light w-100" onclick="mostrarPagina('mensajes')">
          <i class="bi bi-chat-dots"></i> Mensajes
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
      </div>
    </aside>

    <!-- === CONTENIDO === -->
    <main id="main" class="flex-grow-1 p-4">
      <div id="pagina-panel" class="pagina visible">
        <h2 class="mb-3">📊 Sesiones Activas</h2>

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

        <div class="tabla-container shadow">
          <table class="table table-hover align-middle text-center" id="tablaSesiones">
            <thead class="table-success text-white">
              <tr>
                <th>ID</th>
                <th>Usuario</th>
                <th>Inicio</th>
                <th>Final Programado</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <div id="pagina-config" class="pagina">
        <h2>⚙️ Configuración</h2>
        <p>Desde aquí podrás ajustar parámetros del sistema:</p>
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
      </div>
    </main>
  </div>
  <script src="/dashboard-unisimon/assets/js/dashboard.js"></script>
  <script src="/dashboard-unisimon/assets/js/websocket.js"></script>
</body>
</html>
