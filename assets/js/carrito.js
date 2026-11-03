
document.addEventListener('DOMContentLoaded', async () => {
  const usuario = await obtenerSesionActual();

  if (!usuario || usuario.tipo !== 'cliente') {
    sessionStorage.setItem('ambrosia_redirigir_despues_login', window.location.href);
    window.location.href = 'login.html?motivo=carrito';
    return;
  }

  await inicializarHeader();
  await cargarCarrito();

  document.getElementById('boton-confirmar-pedido').addEventListener('click', confirmarPedido);
});

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

function pintarItems(carrito) {
  const lista = document.getElementById('lista-items');
  lista.innerHTML = carrito.items.map((item) => `
    <div class="fila-carrito d-flex align-items-center justify-content-between flex-wrap gap-3" data-id-producto="${item.id_producto}">
      <div>
        <p class="mb-0 fw-bold">${item.nombre_producto || `Producto #${item.id_producto}`}</p>
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
      <div class="estado-vacio__icono"><i class="bi bi-check-lg"></i></div>
      <h2 class="h4 fuente-display">¡Pedido #${respuesta.datos.id_pedido} confirmado!</h2>
      <p class="text-muted">Te avisaremos por notificación en cuanto cambie de estado.</p>
      <a href="catalogo.html" class="btn-ambrosia mt-2">Seguir explorando</a>
      <a href="pago.html?id_pedido=${respuesta.datos.id_pedido}" class="btn-ambrosia mt-2" style="background-color: #d2eecf; border-color: #28a745;">Ir a pagar</a>
    </div>`;
}
