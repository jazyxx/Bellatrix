/**
 * ============================================================================
 *  landing.js — Lógica exclusiva de index.html
 * ============================================================================
 *  Trae los productos del catálogo público y muestra los primeros 8 como
 *  "Destacados" en la Landing Page.
 * ============================================================================
 */

async function cargarDestacados() {
  const grilla = document.getElementById('grilla-destacados');
  const mensajeCargando = document.getElementById('destacados-cargando');

  const respuesta = await apiFetch('api/catalogo/productos');

  if (!respuesta.exito || respuesta.datos.length === 0) {
    mensajeCargando.textContent = 'Muy pronto vas a ver aquí nuestros productos.';
    return;
  }

  mensajeCargando.remove();

  const destacados = respuesta.datos.slice(0, 8);
  grilla.insertAdjacentHTML('beforeend', destacados.map(tarjetaProductoHTML).join(''));

  activarBotonesAgregarCarrito(grilla);
}

document.addEventListener('DOMContentLoaded', cargarDestacados);
