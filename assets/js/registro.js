/**
 * ============================================================================
 *  registro.js — Lógica exclusiva de registro.html (CU010)
 * ============================================================================
 */

document.addEventListener('DOMContentLoaded', () => {
  const formulario = document.getElementById('form-registro');
  const boton = document.getElementById('boton-registro');
  const mensajeError = document.getElementById('mensaje-error');
  const mensajeExito = document.getElementById('mensaje-exito');

  formulario.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    mensajeError.style.display = 'none';
    mensajeExito.style.display = 'none';

    const datos = {
      nombre: document.getElementById('nombre').value.trim(),
      correo: document.getElementById('correo').value.trim(),
      contrasena: document.getElementById('contrasena').value,
      telefono: document.getElementById('telefono').value.trim() || null,
      direccion_entrega: document.getElementById('direccion').value.trim() || null,
    };

    boton.disabled = true;
    boton.textContent = 'Creando cuenta…';

    const respuesta = await apiFetch('api/auth/registro', { method: 'POST', body: datos });

    if (!respuesta.exito) {
      mensajeError.textContent = respuesta.mensaje || 'No pudimos crear tu cuenta.';
      mensajeError.style.display = 'block';
      boton.disabled = false;
      boton.textContent = 'Crear mi cuenta';
      return;
    }

    mensajeExito.textContent = '¡Cuenta creada! Redirigiéndote para iniciar sesión…';
    mensajeExito.style.display = 'block';
    formulario.reset();

    setTimeout(() => {
      window.location.href = 'login.html';
    }, 1500);
  });
});
