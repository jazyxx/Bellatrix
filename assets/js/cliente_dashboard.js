document.addEventListener('DOMContentLoaded', async () => {
  const usuario = await obtenerSesionActual();
  if (!usuario || usuario.tipo !== 'cliente') {
    window.location.href = 'login.html';
    return;
  }

  // Asegúrate de definir el ID correcto para que se ilumine en la barra lateral
  await injectDashboardLayout('Cliente', 'cliente_dashboard');

  document.getElementById('perfil-nombre').textContent = usuario.nombre || 'Cliente';
  document.getElementById('perfil-correo').textContent = usuario.identificador || '';
  const elTel = document.getElementById('perfil-telefono');
  if (elTel) elTel.textContent = usuario.telefono || '-';

  cargarNotificaciones();
  inicializarEditarPerfil();
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

function inicializarEditarPerfil() {
  const modalEditar = document.getElementById('modalEditarPerfil');
  if (modalEditar) {
    modalEditar.addEventListener('show.bs.modal', () => {
      document.getElementById('input-perfil-nombre').value = document.getElementById('perfil-nombre').textContent.trim();
      document.getElementById('input-perfil-correo').value = document.getElementById('perfil-correo').textContent.trim();
      
      const telElem = document.getElementById('perfil-telefono');
      const telActual = telElem ? telElem.textContent.trim() : '';
      document.getElementById('input-perfil-telefono').value = (telActual === '-') ? '' : telActual;
    });
  }

  const formPerfil = document.getElementById('form-editar-perfil');
  if (formPerfil) {
    formPerfil.addEventListener('submit', async (e) => {
      e.preventDefault();

      const nuevoNombre = document.getElementById('input-perfil-nombre').value.trim();
      const nuevoTelefono = document.getElementById('input-perfil-telefono').value.trim();

      const respuesta = await apiFetch('api/actualizar-perfil', {
        method: 'POST',
        body: {
          nombre: nuevoNombre,
          telefono: nuevoTelefono
        }
      });

      if (respuesta && respuesta.exito) {
        document.getElementById('perfil-nombre').textContent = nuevoNombre;
        const elTel = document.getElementById('perfil-telefono');
        if (elTel) elTel.textContent = nuevoTelefono || '-';

        const modalEl = document.getElementById('modalEditarPerfil');
        const bsModal = bootstrap.Modal.getInstance(modalEl);
        if (bsModal) bsModal.hide();

        alert('Tus datos se han actualizado correctamente.');
      } else {
        alert((respuesta && respuesta.mensaje) || 'Error al actualizar los datos.');
      }
    });
  }
}

function formatearFecha(fechaStr) {
  if (!fechaStr) return '';
  const f = new Date(fechaStr);
  return f.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}