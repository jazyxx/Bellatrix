/**
 * ============================================================================
 *  catalogo.js — Lógica exclusiva de catalogo.html
 * ============================================================================
 *  Implementa el CU011 en su parte interactiva: lee filtros de la URL
 *  (?unidad=Pastelería), permite cambiar de categoría con los "chips",
 *  buscar por texto, y vuelve a pedir el catálogo a la API cada vez que
 *  algo cambia — todo sin recargar la página (Fetch API).
 * ============================================================================
 */

// Estado actual de los filtros (se sincroniza con la URL para que el
// enlace se pueda compartir, ej: catalogo.html?unidad=Heladería).
const parametrosUrl = new URLSearchParams(window.location.search);
let filtroUnidad = parametrosUrl.get('unidad') || '';
let filtroBusqueda = '';

async function cargarCatalogo() {
  const grilla = document.getElementById('grilla-catalogo');
  const mensajeCargando = document.getElementById('catalogo-cargando');

  grilla.querySelectorAll('.col-6, .col-md-4, .col-lg-3').forEach((el) => el.remove());
  mensajeCargando.style.display = 'block';
  mensajeCargando.textContent = 'Cargando catálogo…';

  const parametros = new URLSearchParams();
  if (filtroBusqueda) parametros.set('buscar', filtroBusqueda);
  else if (filtroUnidad) parametros.set('unidad', filtroUnidad);

  const respuesta = await apiFetch(`api/catalogo/productos?${parametros.toString()}`);

  if (!respuesta.exito) {
    mensajeCargando.textContent = 'No pudimos cargar el catálogo. Intenta de nuevo en un momento.';
    return;
  }

  if (respuesta.datos.length === 0) {
    mensajeCargando.innerHTML = `
      <div class="estado-vacio">
        <div class="estado-vacio__icono">◌</div>
        <p class="mb-0">No encontramos productos con ese filtro. Prueba con otra búsqueda.</p>
      </div>`;
    return;
  }

  mensajeCargando.style.display = 'none';
  grilla.insertAdjacentHTML('beforeend', respuesta.datos.map(tarjetaProductoHTML).join(''));
  activarBotonesAgregarCarrito(grilla);
}

function activarChipsDeUnidad() {
  const chips = document.querySelectorAll('#chips-unidad .chip-filtro[data-unidad]');
  chips.forEach((chip) => {
    // Marca visualmente el chip que coincide con el filtro inicial de la URL.
    if (chip.dataset.unidad === filtroUnidad) {
      chips.forEach((c) => c.classList.remove('activo'));
      chip.classList.add('activo');
    }

    chip.addEventListener('click', () => {
      chips.forEach((c) => c.classList.remove('activo'));
      chip.classList.add('activo');

      filtroUnidad = chip.dataset.unidad;
      filtroBusqueda = '';
      document.getElementById('campo-busqueda').value = '';

      // Refleja el filtro en la URL, sin recargar la página.
      const url = new URL(window.location);
      if (filtroUnidad) url.searchParams.set('unidad', filtroUnidad);
      else url.searchParams.delete('unidad');
      window.history.replaceState({}, '', url);

      cargarCatalogo();
    });
  });
}

function activarBusqueda() {
  const formulario = document.getElementById('form-busqueda');
  formulario.addEventListener('submit', (evento) => {
    evento.preventDefault();
    filtroBusqueda = document.getElementById('campo-busqueda').value.trim();

    if (filtroBusqueda) {
      document.querySelectorAll('#chips-unidad .chip-filtro').forEach((c) => c.classList.remove('activo'));
      document.querySelector('#chips-unidad .chip-filtro[data-unidad=""]').classList.add('activo');
      filtroUnidad = '';
    }

    cargarCatalogo();
  });
}

function activarPedidoTortaPersonalizada() {
  const btnWhatsApp = document.getElementById('btn-enviar-whatsapp');

  if (btnWhatsApp) {
    btnWhatsApp.addEventListener('click', () => {
      const fecha = document.getElementById('torta-fecha').value;
      
      if (!fecha) {
        alert('Por favor, selecciona la fecha en la que necesitas la torta.');
        document.getElementById('torta-fecha').focus();
        return;
      }

      // Capturar Checkboxes de Tamaño (Pisos) y Sabores/Masas
      const pisos = Array.from(document.querySelectorAll('.chk-piso:checked')).map(cb => cb.value);
      const masas = Array.from(document.querySelectorAll('.chk-masa:checked')).map(cb => cb.value);

      // Capturar Forma y Especificaciones
      const formaEl = document.querySelector('input[name="tortaForma"]:checked');
      const forma = formaEl ? formaEl.value : 'No especificada';
      const especificaciones = document.getElementById('torta-especificaciones').value.trim();

      // Armar el mensaje
      let mensaje = `*¡Hola Ambrosía! Quisiera cotizar una Torta Personalizada*\n\n`;
      mensaje += `*Día de entrega:* ${fecha}\n`;
      mensaje += `*Forma:* ${forma}\n`;
      mensaje += `*Tamaño:* ${pisos.length > 0 ? pisos.join(', ') : 'Por definir'}\n`;
      mensaje += `*Sabores/Masas:* ${masas.length > 0 ? masas.join(', ') : 'Por definir'}\n`;
      
      if (especificaciones) {
        mensaje += `\n*Especificaciones:* ${especificaciones}\n`;
      }
      mensaje += `\n¿Me podrían confirmar el presupuesto y los detalles del diseño? Quedo atent@.`;

      const telefonoAmbrosia = '573205274821'; // Número de WhatsApp de Ambrosía
      const urlWA = `https://wa.me/${telefonoAmbrosia}?text=${encodeURIComponent(mensaje)}`;
      
      // Cerrar el modal y abrir WhatsApp
      const modalEl = document.getElementById('modalTortaPersonalizada');
      if (modalEl) {
        // En caso de que Bootstrap ya haya inicializado el modal
        const modalInstancia = bootstrap.Modal.getInstance(modalEl);
        if (modalInstancia) modalInstancia.hide();
      }

      window.open(urlWA, '_blank');
    });
  }
}

document.addEventListener('DOMContentLoaded', () => {
  activarChipsDeUnidad();
  activarBusqueda();
  activarPedidoTortaPersonalizada();
  cargarCatalogo();
});
