/**
 * assets/js/login_empleado.js
 * Lógica exclusiva de login_empleado.html para ingreso de Administradores/Cajeros.
 */

function mostrarError(mensaje) {
  const caja = document.getElementById('mensaje-error');
  caja.textContent = mensaje;
  caja.style.display = 'block';
}

function ocultarError() {
  const caja = document.getElementById('mensaje-error');
  if (caja) caja.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
  const formulario = document.getElementById('form-login-empleado');
  const boton = document.getElementById('boton-login');

  formulario.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    ocultarError();

    const usuario = document.getElementById('usuario').value.trim();
    const contrasena = document.getElementById('contrasena').value;

    if (!usuario || !contrasena) {
      mostrarError('Por favor ingresa todos los campos.');
      return;
    }

    boton.disabled = true;
    boton.textContent = 'Autenticando…';

    const respuesta = await apiFetch('api/auth/login', {
      method: 'POST',
      body: { tipo: 'empleado', usuario, contrasena },
    });

    if (!respuesta.exito) {
      mostrarError(respuesta.mensaje || 'Usuario o contraseña incorrectos.');
      boton.disabled = false;
      boton.textContent = 'Ingresar al Panel';
      return;
    }

    // Redirige al POS, el cual gestionará el ruteo interno de acuerdo al rol (Admin/Cajero)
    window.location.href = 'admin_pos.html';
  });
});
