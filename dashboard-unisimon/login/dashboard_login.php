<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Login FOLIO - Dashboard Unisimon</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
  <div class="card shadow p-4" style="width: 400px;">
    <h4 class="text-center mb-3">🔐 Acceso FOLIO Staff</h4>
    <div class="mb-3">
      <input id="username" class="form-control" placeholder="Usuario FOLIO">
    </div>
    <div class="mb-3">
      <input id="password" type="password" class="form-control" placeholder="Contraseña">
    </div>
    <button id="btnLogin" class="btn btn-success w-100">Ingresar</button>
    <div id="msg" class="text-center mt-3 text-danger"></div>
  </div>

  <script>
  document.getElementById('btnLogin').addEventListener('click', async () => {
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value.trim();
    const msg = document.getElementById('msg');
    msg.textContent = "⏳ Validando en FOLIO...";
    try {
      const resp = await fetch('login_folio.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({username, password})
      });
      const data = await resp.json();
      if (data.status === 'ok') {
        msg.textContent = "✅ Acceso concedido, redirigiendo...";
        setTimeout(() => window.location.href = "index.html", 1200);
      } else {
        msg.textContent = "❌ " + data.message;
      }
    } catch (e) {
      msg.textContent = "⚠️ Error de conexión: " + e.message;
    }
  });
  </script>
</body>
</html>
