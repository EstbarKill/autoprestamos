<?php
// dashboard.php — Panel principal Universidad Simón Bolívar
include 'db.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>📊 Dashboard | Universidad Simón Bolívar</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="icon" href="assets/img/logo.png" type="image/png">
  <style>
    body {
      background: url('assets/img/background.jpg') no-repeat center center fixed;
      background-size: cover;
    }
  </style>
</head>
<body>
  <!-- Barra superior -->
  <nav class="navbar navbar-expand-lg navbar-dark px-4" style="background-color: var(--unisimon-green);">
    <a class="navbar-brand d-flex align-items-center" href="#">
      <img src="assets/img/logo.png" alt="Logo" height="40" class="me-2">
      <span>Dashboard Unisimón</span>
    </a>
    <div class="ms-auto">
      <button class="btn btn-light btn-sm" onclick="enviarMensajeGlobal()">💬 Enviar mensaje global</button>
    </div>
  </nav>

  <div class="container-fluid mt-3">
    <div class="row">
      <!-- Panel lateral de estadísticas -->
      <aside class="col-md-3 col-lg-2 sidebar">
        <h4>📈 Estadísticas</h4>
        <ul class="list-unstyled" id="estadisticas">
          <li>🟢 Abiertas: <span id="stat-abierto">0</span></li>
          <li>⏸ Suspendidas: <span id="stat-suspendido">0</span></li>
          <li>🚫 Bloqueadas: <span id="stat-bloqueado">0</span></li>
          <li>⚙️ Finalizadas: <span id="stat-finalizado">0</span></li>
        </ul>
      </aside>

      <!-- Contenido principal -->
      <main class="col-md-9 col-lg-10">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h1 class="fw-bold text-success">Gestión de Sesiones</h1>
          <div>
            <select id="filtroEstado" class="form-select d-inline-block w-auto">
              <option value="">Todos los estados</option>
              <option value="Abierto">Abierto</option>
              <option value="Suspendido">Suspendido</option>
              <option value="Bloqueado">Bloqueado</option>
              <option value="Finalizado">Finalizado</option>
            </select>
          </div>
        </div>

        <!-- Tabla -->
        <div class="table-container p-3 rounded bg-white shadow">
          <table class="table table-hover align-middle text-center" id="tablaSesiones">
            <thead class="table-dark">
              <tr>
                <th>ID</th>
                <th>Usuario</th>
                <th>Inicio</th>
                <th>Fin Programado</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody id="tbodySesiones">
              <tr><td colspan="6">Cargando datos...</td></tr>
            </tbody>
          </table>
        </div>
      </main>
    </div>
  </div>

  <!-- Modal para detalles -->
  <div class="modal fade" id="modalInfo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title">Detalles de Sesión</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="detalleContenido">Cargando...</div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast de notificación -->
  <div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100;"></div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/dashboard.js" defer></script>
  <script src="assets/js/ws-client.js" defer></script>
</body>
</html>
