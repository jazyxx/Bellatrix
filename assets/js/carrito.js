/**
 * ============================================================================
 *  carrito.js — Lógica exclusiva de carrito.html (CU012 + CU013)
 * ============================================================================
 *  ESTE ARCHIVO ES DONDE SE APLICA, DE FORMA MÁS ESTRICTA, LA REGLA DE
 *  "el checkout exige autenticación": apenas carga la página, se verifica
 *  la sesión ANTES de mostrar nada del carrito. Si no hay sesión de
 *  Cliente, se redirige de inmediato a login.html — a diferencia del
 *  catálogo (donde se puede mirar libremente y solo se exige sesión al
 *  intentar agregar un producto), aquí ni siquiera se muestra el carrito.
 * ============================================================================
 */

document.addEventListener('DOMContentLoaded', async () => {
  const usuario = await obtenerSesionActual();

  if (!usuario || usuario.tipo !== 'cliente') {
    sessionStorage.setItem('ambrosia_redirigir_despues_login', window.location.href);
    window.location.href = 'login.html?motivo=carrito';
    return;
  }

  // Ya sabemos que hay sesión: ahora sí pintamos el header normalmente.
  await inicializarHeader();
  await cargarCarrito();

  document.getElementById('boton-confirmar-pedido').addEventListener('click', confirmarPedido);
});

/**
 * cargarCarrito()
 * Pide el carrito actual (GET /api/carrito) y pinta cada línea.
 */
async function cargarCarrito() {
  const respuesta = await apiFetch('api/carrito');
  document.getElementById('carrito-cargando').style.display = 'none';

  if (!respuesta.exito) {
    document.getElementById('carrito-vacio').style.display = 'block';
    return;
  }

  const carrito = respuesta.datos;

  if (carrito.items.length === 0) {
    document.getElementById('carrito-vacio').style.display = 'block';
    return;
  }

  document.getElementById('carrito-contenido').style.display = 'flex';
  pintarItems(carrito);
}

/**
 * pintarItems(carrito)
 * Genera el HTML de cada línea del carrito con sus controles de
 * cantidad (+/-) y el botón de eliminar.
 */
function pintarItems(carrito) {
  const lista = document.getElementById('lista-items');
  lista.innerHTML = carrito.items.map((item) => `
    <div class="fila-carrito d-flex align-items-center justify-content-between flex-wrap gap-3" data-id-producto="${item.id_producto}">
      <div>
        <p class="mb-0 fw-bold">Producto #${item.id_producto}</p>
        <p class="mb-0 small text-muted">${formatearPrecioCOP(item.precio_unitario)} c/u</p>
      </div>
      <div class="d-flex align-items-center gap-3">
        <div class="control-cantidad">
          <button type="button" class="boton-restar" aria-label="Disminuir cantidad">−</button>
          <span>${item.cantidad}</span>
          <button type="button" class="boton-sumar" aria-label="Aumentar cantidad">+</button>
        </div>
        <strong>${formatearPrecioCOP(item.subtotal)}</strong>
        <button type="button" class="btn btn-sm btn-link text-danger boton-eliminar" aria-label="Eliminar producto">Eliminar</button>
      </div>
    </div>
  `).join('');

  document.getElementById('texto-subtotal').textContent = formatearPrecioCOP(carrito.subtotal);

  activarControlesDeLinea();
}

/**
 * activarControlesDeLinea()
 * Conecta los botones +/- y "Eliminar" de cada fila con la API.
 * Después de cualquier cambio, se vuelve a cargar todo el carrito
 * para mantener el subtotal siempre exacto (viene calculado por el
 * backend, nunca lo calculamos nosotros en el navegador).
 */
function activarControlesDeLinea() {
  document.querySelectorAll('.fila-carrito').forEach((fila) => {
    const idProducto = fila.dataset.idProducto;
    const cantidadActual = () => Number(fila.querySelector('.control-cantidad span').textContent);

    fila.querySelector('.boton-sumar').addEventListener('click', () =>
      cambiarCantidad(idProducto, cantidadActual() + 1));

    fila.querySelector('.boton-restar').addEventListener('click', () =>
      cambiarCantidad(idProducto, cantidadActual() - 1));

    fila.querySelector('.boton-eliminar').addEventListener('click', () => eliminarProducto(idProducto));
  });
}

async function cambiarCantidad(idProducto, nuevaCantidad) {
  if (nuevaCantidad < 1) {
    await eliminarProducto(idProducto);
    return;
  }

  const respuesta = await apiFetch(`api/carrito/productos/${idProducto}`, {
    method: 'PUT',
    body: { cantidad: nuevaCantidad },
  });

  if (respuesta.exito) {
    pintarItems(respuesta.datos);
    await actualizarContadorCarrito();
  } else {
    alert(respuesta.mensaje || 'No se pudo actualizar la cantidad.');
  }
}

async function eliminarProducto(idProducto) {
  const respuesta = await apiFetch(`api/carrito/productos/${idProducto}`, { method: 'DELETE' });

  if (!respuesta.exito) {
    alert(respuesta.mensaje || 'No se pudo eliminar el producto.');
    return;
  }

  if (respuesta.datos.items.length === 0) {
    document.getElementById('carrito-contenido').style.display = 'none';
    document.getElementById('carrito-vacio').style.display = 'block';
  } else {
    pintarItems(respuesta.datos);
  }

  await actualizarContadorCarrito();
}

/**
 * confirmarPedido()
 * ------------------------------------------------------------
 * Implementa el CU013: confirma el carrito como Pedido formal.
 * Requiere una dirección de entrega. El pago (CU014) se hace en un
 * paso posterior, una vez el pedido ya quedó registrado — por ahora
 * solo mostramos la confirmación del pedido y su número.
 */
async function confirmarPedido() {
  const direccion = document.getElementById('direccion-entrega').value.trim();
  const mensaje = document.getElementById('mensaje-pedido');
  const boton = document.getElementById('boton-confirmar-pedido');

  mensaje.style.display = 'none';

  if (!direccion) {
    mensaje.textContent = 'Ingresa una dirección de entrega para continuar.';
    mensaje.style.display = 'block';
    return;
  }

  boton.disabled = true;
  boton.textContent = 'Confirmando…';

  const respuesta = await apiFetch('api/pedidos', {
    method: 'POST',
    body: { direccion_entrega: direccion },
  });

  if (!respuesta.exito) {
    mensaje.textContent = respuesta.mensaje || 'No pudimos confirmar tu pedido.';
    mensaje.style.display = 'block';
    boton.disabled = false;
    boton.textContent = 'Confirmar pedido';
    return;
  }

  await actualizarContadorCarrito();

  document.getElementById('carrito-contenido').innerHTML = `
    <div class="col-12 estado-vacio">
      <div class="estado-vacio__icono">✓</div>
      <h2 class="h4 fuente-display">¡Pedido #${respuesta.datos.id_pedido} confirmado!</h2>
      <p class="text-muted">Te avisaremos por notificación en cuanto cambie de estado.</p>
      <a href="catalogo.html" class="btn-ambrosia mt-2">Seguir explorando</a>
    </div>`;
}
