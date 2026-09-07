document.addEventListener('DOMContentLoaded', async () => {
  const usuario = await obtenerSesionActual();
  if (!usuario || usuario.tipo !== 'cliente') {
    window.location.href = 'login.html';
    return;
  }

  await injectDashboardLayout('Cliente', 'cliente_notificaciones');
  cargarNotificaciones();
});

async function cargarNotificaciones() {
  const respuesta = await apiFetch('api/notificaciones');
  const container = document.getElementById('lista-notificaciones');
  if (!container) return;

  if (!respuesta.exito || !respuesta.datos || respuesta.datos.length === 0) {
    container.innerHTML = `<p class="text-muted small text-center my-4">No tienes notificaciones pendientes.</p>`;
    return;
  }

  container.innerHTML = respuesta.datos.map(n => `
    <div class="p-3 border-bottom mb-2 bg-white rounded shadow-sm">
      <div class="d-flex align-items-center justify-content-between mb-1">
        <span class="badge-pastel badge-pastel-primary" style="font-size: 0.75rem;">Notificación</span>
        <span class="text-muted" style="font-size: 0.75rem;">${formatearFecha(n.fecha_creacion || '')}</span>
      </div>
      <p class="mb-0 small text-dark mt-2">${escaparHtml(n.mensaje)}</p>
    </div>
  `).join('');
}

function formatearFecha(fechaStr) {
  if (!fechaStr) return '';
  const f = new Date(fechaStr);
  return f.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
