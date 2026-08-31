/**
 * ============================================================================
 *  login.js — Lógica exclusiva de login.html
 * ============================================================================
 *  Envía el login como Cliente (tipo: 'cliente') al backend. Si venía de
 *  un intento de agregar al carrito sin sesión (ver componentes.js ->
 *  activarBotonesAgregarCarrito), muestra un aviso y, al iniciar sesión
 *  con éxito, regresa automáticamente a la página de origen.
 * ============================================================================
 */

function mostrarError(mensaje) {
  const caja = document.getElementById('mensaje-error');
  caja.textContent = mensaje;
  caja.style.display = 'block';
}

function ocultarError() {
  document.getElementById('mensaje-error').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
  // Si llegamos aquí porque intentaron comprar sin sesión, se lo explicamos.
  const parametros = new URLSearchParams(window.location.search);
  if (parametros.get('motivo') === 'carrito') {
    document.getElementById('aviso-carrito').style.display = 'block';
  }

  const formulario = document.getElementById('form-login');
  const boton = document.getElementById('boton-login');

  formulario.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    ocultarError();

    const correo = document.getElementById('correo').value.trim();
    const contrasena = document.getElementById('contrasena').value;

    boton.disabled = true;
    boton.textContent = 'Ingresando…';

    const respuesta = await apiFetch('api/auth/login', {
      method: 'POST',
      body: { tipo: 'cliente', correo, contrasena },
    });

    if (!respuesta.exito) {
      mostrarError(respuesta.mensaje || 'No pudimos iniciar tu sesión.');
      boton.disabled = false;
      boton.textContent = 'Iniciar sesión';
      return;
    }

    // Si veníamos de un intento de compra, volvemos a esa página.
    const destino = sessionStorage.getItem('ambrosia_redirigir_despues_login');
    sessionStorage.removeItem('ambrosia_redirigir_despues_login');
    window.location.href = destino || 'index.html';
  });
});
