/**
 * ============================================================================
 *  api.js — Envoltorio compartido para hablar con el backend
 * ============================================================================
 *  TODAS las páginas del sitio público (Landing, Catálogo, Login, Registro,
 *  Carrito) usan las funciones de este archivo para comunicarse con la API
 *  de Bellatrix, en vez de escribir fetch() suelto en cada página.
 *
 *  ¿POR QUÉ RUTAS RELATIVAS SIN "/" AL INICIO? (ej. 'api/auth/login' y NO
 *  '/api/auth/login' ni 'http://localhost/bellatrix/api/auth/login')
 *  Porque así el navegador arma la URL automáticamente a partir de la
 *  página actual. Si esta página vive en http://localhost/bellatrix/, la
 *  petición va a http://localhost/bellatrix/api/auth/login SIN que
 *  tengamos que escribir "bellatrix" a mano en ningún lado. Esto hace que
 *  el sitio funcione sin cambios sin importar el nombre de la carpeta o
 *  el dominio donde lo instales.
 * ============================================================================
 */

/**
 * apiFetch(ruta, opciones)
 * ------------------------------------------------------------
 * Envuelve fetch() para:
 *   1. Mandar y recibir SIEMPRE JSON.
 *   2. Incluir la cookie de sesión (credentials) en cada petición,
 *      necesaria para que el backend sepa quién eres.
 *   3. Devolver siempre el mismo formato de respuesta del backend
 *      ({ exito, mensaje, datos }), sin importar si fue error o éxito,
 *      para que cada página maneje el resultado de forma uniforme.
 *
 * @param {string} ruta   Ej: 'api/catalogo/productos'
 * @param {object} opciones  { method, body } — body puede ser un objeto,
 *                            se convierte a JSON automáticamente.
 */
async function apiFetch(ruta, opciones = {}) {
  const config = {
    method: opciones.method || 'GET',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin', // Envía/recibe la cookie de sesión de PHP.
  };

  if (opciones.body) {
    config.body = JSON.stringify(opciones.body);
  }

  let respuesta;
  try {
    respuesta = await fetch(ruta, config);
  } catch (errorDeRed) {
    // El servidor no respondió en absoluto (apagado, sin conexión, etc.)
    return { exito: false, mensaje: 'No se pudo conectar con el servidor. Intenta de nuevo.' };
  }

  let cuerpo;
  try {
    cuerpo = await respuesta.json();
  } catch (errorDeFormato) {
    return { exito: false, mensaje: 'El servidor respondió en un formato inesperado.' };
  }

  return cuerpo; // Ya viene como { exito, mensaje, datos }
}

/**
 * obtenerSesionActual()
 * ------------------------------------------------------------
 * Pregunta al backend "¿hay alguien logueado ahora mismo?" usando
 * GET /api/auth/me. Devuelve el objeto de usuario si hay sesión
 * activa, o null si no la hay. Todas las páginas la usan para saber
 * si deben mostrar "Iniciar sesión" o "Mi cuenta" en el header.
 */
async function obtenerSesionActual() {
  const respuesta = await apiFetch('api/auth/me');
  return respuesta.exito ? respuesta.datos : null;
}

/**
 * formatearPrecioCOP(numero)
 * Da formato de pesos colombianos a un número, ej: 45000 -> "$ 45.000"
 */
function formatearPrecioCOP(numero) {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(numero);
}
