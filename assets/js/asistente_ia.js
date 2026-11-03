
function inicializarAsistenteIA() {
  const boton   = document.getElementById('ia-widget-boton');
  const panel   = document.getElementById('ia-widget-panel');
  const cerrar  = document.getElementById('ia-widget-cerrar');
  const nuevo   = document.getElementById('ia-widget-nueva-conversacion');
  const lista   = document.getElementById('ia-widget-mensajes');
  const form    = document.getElementById('ia-widget-form');
  const input   = document.getElementById('ia-widget-input');
  const enviar  = document.getElementById('ia-widget-enviar');

  if (!boton || !panel) return; 

  let historialCargado = false;

  function pintarMensaje(rol, texto) {
    const burbuja = document.createElement('div');
    burbuja.className = `ia-mensaje ia-mensaje--${rol}`;
    burbuja.textContent = texto;
    lista.appendChild(burbuja);
    lista.scrollTop = lista.scrollHeight;
    return burbuja;
  }

  function mostrarEscribiendo() {
    const indicador = document.createElement('div');
    indicador.className = 'ia-widget-escribiendo';
    indicador.id = 'ia-widget-escribiendo-actual';
    indicador.innerHTML = '<span></span><span></span><span></span>';
    lista.appendChild(indicador);
    lista.scrollTop = lista.scrollHeight;
  }

  function quitarEscribiendo() {
    document.getElementById('ia-widget-escribiendo-actual')?.remove();
  }

  async function cargarHistorial() {
    lista.innerHTML = '';
    pintarMensaje('sistema', 'Cargando conversación…');

    const respuesta = await apiFetch('api/asistente-ia/historial', { method: 'GET' });
    lista.innerHTML = '';

    if (!respuesta.exito || respuesta.datos.length === 0) {
      pintarMensaje('sistema', '¡Hola! Pregúntame sobre tus ventas, egresos o qué unidad de negocio está rindiendo mejor.');
      return;
    }

    respuesta.datos.forEach((item) => pintarMensaje(item.rol, item.mensaje));
    historialCargado = true;
  }

  async function abrirPanel() {
    panel.classList.add('abierto');
    boton.setAttribute('aria-expanded', 'true');
    if (!historialCargado) {
      await cargarHistorial();
    }
    input.focus();
  }

  function cerrarPanel() {
    panel.classList.remove('abierto');
    boton.setAttribute('aria-expanded', 'false');
  }

  boton.addEventListener('click', () => {
    const yaEstaAbierto = panel.classList.contains('abierto');
    yaEstaAbierto ? cerrarPanel() : abrirPanel();
  });
  cerrar.addEventListener('click', cerrarPanel);

  nuevo.addEventListener('click', async () => {
    if (!confirm('¿Iniciar una nueva conversación? Se borrará el historial guardado.')) return;
    await apiFetch('api/asistente-ia/historial', { method: 'DELETE' });
    lista.innerHTML = '';
    pintarMensaje('sistema', 'Conversación reiniciada. ¿En qué te ayudo?');
  });

  form.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    const texto = input.value.trim();
    if (texto === '') return;

    pintarMensaje('usuario', texto);
    input.value = '';
    input.disabled = true;
    enviar.disabled = true;
    mostrarEscribiendo();

    const respuesta = await apiFetch('api/asistente-ia/mensaje', {
      method: 'POST',
      body: { mensaje: texto },
    });

    quitarEscribiendo();
    input.disabled = false;
    enviar.disabled = false;
    input.focus();

    if (!respuesta.exito) {
      pintarMensaje('sistema', respuesta.mensaje || 'No se pudo obtener respuesta del asistente. Intenta de nuevo.');
      return;
    }

    pintarMensaje('asistente', respuesta.datos.mensaje);
  });
}
