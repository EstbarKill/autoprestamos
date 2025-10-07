// dashboard.js - control de eventos principales
document.addEventListener("DOMContentLoaded", () => {
  console.log("✅ Dashboard cargado correctamente.");

  // Botones de acción
  document.querySelectorAll("[data-accion]").forEach(btn => {
    btn.addEventListener("click", async () => {
      const id = btn.dataset.id;
      const accion = btn.dataset.accion;
      if (!confirm(`¿Deseas ejecutar '${accion}' en la sesión #${id}?`)) return;

      try {
        const resp = await fetch("dashboard_action.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id, accion })
        });
        const data = await resp.json();
        alert(data.mensaje);
      } catch (err) {
        alert("⚠️ Error al comunicar con el servidor: " + err.message);
      }
    });
  });
});
