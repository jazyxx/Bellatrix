
async function apiFetch(ruta, opciones = {}) {
  const config = {
    method: opciones.method || 'GET',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin', 
  };

  if (opciones.body) {
    config.body = JSON.stringify(opciones.body);
  }

  let respuesta;
  try {
    respuesta = await fetch(ruta, config);
  } catch (errorDeRed) {
    return { exito: false, mensaje: 'No se pudo conectar con el servidor. Intenta de nuevo.' };
  }

  let cuerpo;
  try {
    cuerpo = await respuesta.json();
  } catch (errorDeFormato) {
    return { exito: false, mensaje: 'El servidor respondió en un formato inesperado.' };
  }

  return cuerpo; 
}

async function obtenerSesionActual() {
  const respuesta = await apiFetch('api/auth/me');
  return respuesta.exito ? respuesta.datos : null;
}

function formatearPrecioCOP(numero) {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(numero);
}
