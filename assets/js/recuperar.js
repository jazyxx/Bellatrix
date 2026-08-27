/**
 * ============================================================================
 *  recuperar.js — Lógica exclusiva de recuperar.html (CU010, mitad "olvidé
 *  mi contraseña"): pedir el código por correo, y luego usarlo junto con
 *  una nueva contraseña. Consume las rutas ya existentes:
 *    POST /api/auth/recuperar    { correo }
 *    POST /api/auth/restablecer  { token, nueva_contrasena }
 * ============================================================================
 */

document.addEventListener('DOMContentLoaded', () => {
  const pasoSolicitar   = document.getElementById('paso-solicitar');
  const pasoRestablecer = document.getElementById('paso-restablecer');

  const formSolicitar   = document.getElementById('form-solicitar');
  const botonSolicitar  = document.getElementById('boton-solicitar');
  const errorSolicitar  = document.getElementById('mensaje-error-solicitar');
  const exitoSolicitar  = document.getElementById('mensaje-exito-solicitar');

  const formRestablecer  = document.getElementById('form-restablecer');
  const botonRestablecer = document.getElementById('boton-restablecer');
  const errorRestablecer = document.getElementById('mensaje-error-restablecer');
  const exitoRestablecer = document.getElementById('mensaje-exito-restablecer');

  function mostrarPaso(paso) {
    const esSolicitar = paso === 'solicitar';
    pasoSolicitar.style.display = esSolicitar ? 'block' : 'none';
    pasoRestablecer.style.display = esSolicitar ? 'none' : 'block';
  }

  // Enlaces para saltar manualmente entre los dos pasos.
  document.getElementById('link-ya-tengo-codigo').addEventListener('click', (e) => {
    e.preventDefault();
    mostrarPaso('restablecer');
  });
  document.getElementById('link-volver-paso1').addEventListener('click', (e) => {
    e.preventDefault();
    mostrarPaso('solicitar');
  });

  // ============================================================
  // PASO 1 — Solicitar el código
  // ============================================================
  formSolicitar.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    errorSolicitar.style.display = 'none';
    exitoSolicitar.style.display = 'none';

    const correo = document.getElementById('correo').value.trim();

    botonSolicitar.disabled = true;
    botonSolicitar.textContent = 'Enviando…';

    const respuesta = await apiFetch('api/auth/recuperar', { method: 'POST', body: { correo } });

    botonSolicitar.disabled = false;
    botonSolicitar.textContent = 'Enviar código';

    if (!respuesta.exito) {
      errorSolicitar.textContent = respuesta.mensaje || 'No pudimos procesar tu solicitud.';
      errorSolicitar.style.display = 'block';
      return;
    }

    // Por seguridad el backend siempre responde el mismo mensaje genérico,
    // exista o no ese correo en el sistema (así nadie puede usar este
    // formulario para averiguar qué correos están registrados).
    exitoSolicitar.textContent = respuesta.mensaje || 'Si el correo está registrado, te llegará un código.';
    exitoSolicitar.style.display = 'block';

    setTimeout(() => mostrarPaso('restablecer'), 1200);
  });

  // ============================================================
  // PASO 2 — Restablecer con el código
  // ============================================================
  formRestablecer.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    errorRestablecer.style.display = 'none';
    exitoRestablecer.style.display = 'none';

    const datos = {
      token: document.getElementById('token').value.trim(),
      nueva_contrasena: document.getElementById('nueva-contrasena').value,
    };

    botonRestablecer.disabled = true;
    botonRestablecer.textContent = 'Actualizando…';

    const respuesta = await apiFetch('api/auth/restablecer', { method: 'POST', body: datos });

    if (!respuesta.exito) {
      errorRestablecer.textContent = respuesta.mensaje || 'El código no es válido o ya expiró.';
      errorRestablecer.style.display = 'block';
      botonRestablecer.disabled = false;
      botonRestablecer.textContent = 'Actualizar contraseña';
      return;
    }

    exitoRestablecer.textContent = '¡Contraseña actualizada! Redirigiéndote para iniciar sesión…';
    exitoRestablecer.style.display = 'block';
    formRestablecer.reset();

    setTimeout(() => {
      window.location.href = 'login.html';
    }, 1500);
  });
});
