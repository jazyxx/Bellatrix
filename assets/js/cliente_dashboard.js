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

  inicializarEditarPerfil();
});

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