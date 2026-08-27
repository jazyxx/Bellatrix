/**
 * assets/js/admin_pos.js
 * Lógica del Punto de Venta (POS) para Administradores y Cajeros.
 */

let todosLosProductos = [];
let carritoLocal = [];
let categoriaActiva = 'todos';

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión
  const usuario = await obtenerSesionActual();
  if (!usuario || (usuario.rol !== 'Administrador' && usuario.rol !== 'Cajero')) {
    window.location.href = 'login_empleado.html';
    return;
  }

  // 2. Inyectar Layout del Dashboard
  const activeTabId = usuario.rol === 'Administrador' ? 'admin_pos' : 'cajero_pos';
  await injectDashboardLayout(usuario.rol, activeTabId);

  // 3. Inicializar Listeners
  inicializarListeners();

  // 4. Cargar catálogo de productos del POS
  await cargarProductosPOS();
});

function inicializarListeners() {
  // Búsqueda en tiempo real
  const buscarInput = document.getElementById('pos-buscar-input');
  if (buscarInput) {
    buscarInput.addEventListener('input', filtrarProductos);
  }

  // Filtrado por categoría
  const catBtns = document.querySelectorAll('.pos-category-btn');
  catBtns.forEach(btn => {
    btn.addEventListener('click', (e) => {
      catBtns.forEach(b => b.classList.remove('active'));
      e.target.classList.add('active');
      categoriaActiva = e.target.dataset.categoria;
      filtrarProductos();
    });
  });

  // Cálculo de cambio de dinero en tiempo real
  const recibidoInput = document.getElementById('pos-recibido');
  if (recibidoInput) {
    recibidoInput.addEventListener('input', calcularCambio);
  }

  // Registrar y finalizar venta
  const btnFinalizar = document.getElementById('pos-btn-finalizar');
  if (btnFinalizar) {
    btnFinalizar.addEventListener('click', finalizarVentaPOS);
  }
}

async function cargarProductosPOS() {
  const respuesta = await apiFetch('api/inventario/productos');
  const grid = document.getElementById('pos-product-grid');
  if (!grid) return;

  if (!respuesta.exito || !respuesta.datos) {
    grid.innerHTML = `<div class="col-12 text-center text-muted py-5">No se pudieron cargar los productos: ${respuesta.mensaje}</div>`;
    return;
  }

  todosLosProductos = respuesta.datos;
  renderizarProductos(todosLosProductos);
}

function renderizarProductos(productos) {
  const grid = document.getElementById('pos-product-grid');
  if (!grid) return;

  if (productos.length === 0) {
    grid.innerHTML = `<div class="col-12 text-center text-muted py-5">No se encontraron productos coincidentes.</div>`;
    return;
  }

  grid.innerHTML = productos.map(p => {
    const agotado = p.stock <= 0 || !p.disponible;
    const desc = p.descripcion ? p.descripcion : '';
    const badgeClass = p.unidad_negocio === 'Pastelería' ? 'badge-pastel-danger' : 'badge-pastel-success';

    return `
      <div class="col-sm-4 col-6">
        <div class="pos-item-card db-card p-2" onclick="agregarAlCarritoPOS(${p.id_producto})" style="${agotado ? 'opacity: 0.6; cursor: not-allowed;' : ''}">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="badge-pastel ${badgeClass}" style="font-size:0.65rem;">${p.unidad_negocio}</span>
              <span class="pos-item-stock small text-muted">${p.stock} disp.</span>
            </div>
            <h4 class="pos-item-title mb-1">${escaparHtml(p.nombre)}</h4>
            <p class="small text-muted mb-2 text-truncate">${escaparHtml(desc)}</p>
          </div>
          <div class="d-flex align-items-center justify-content-between mt-2">
            <span class="pos-item-price text-success">${formatearPrecioCOP(p.precio)}</span>
            <button class="btn btn-sm btn-db-primary py-0 px-2 font-weight-bold" ${agotado ? 'disabled' : ''}>+</button>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

function filtrarProductos() {
  const query = document.getElementById('pos-buscar-input').value.toLowerCase().trim();
  let filtrados = todosLosProductos;

  // Filtrado por categoría
  if (categoriaActiva !== 'todos') {
    filtrados = filtrados.filter(p => p.tipo === categoriaActiva);
  }

  // Filtrado por texto de búsqueda
  if (query) {
    filtrados = filtrados.filter(p => p.nombre.toLowerCase().includes(query) || (p.tipo && p.tipo.toLowerCase().includes(query)));
  }

  renderizarProductos(filtrados);
}

function agregarAlCarritoPOS(idProducto) {
  const prod = todosLosProductos.find(p => p.id_producto === idProducto);
  if (!prod) return;

  if (prod.stock <= 0 || !prod.disponible) {
    alert('Este producto no tiene stock disponible.');
    return;
  }

  const enCarrito = carritoLocal.find(item => item.id_producto === idProducto);
  if (enCarrito) {
    if (enCarrito.cantidad >= prod.stock) {
      alert(`No puedes agregar más. Solo quedan ${prod.stock} unidades disponibles.`);
      return;
    }
    enCarrito.cantidad++;
  } else {
    carritoLocal.push({
      id_producto: prod.id_producto,
      nombre: prod.nombre,
      precio: prod.precio,
      cantidad: 1,
      stockMax: prod.stock
    });
  }

  actualizarCarritoDOM();
}

function modificarCantidadPOS(idProducto, delta) {
  const item = carritoLocal.find(i => i.id_producto === idProducto);
  if (!item) return;

  item.cantidad += delta;
  if (item.cantidad <= 0) {
    carritoLocal = carritoLocal.filter(i => i.id_producto !== idProducto);
  } else if (item.cantidad > item.stockMax) {
    alert(`Solo hay ${item.stockMax} unidades disponibles.`);
    item.cantidad = item.stockMax;
  }

  actualizarCarritoDOM();
}

function eliminarDelCarritoPOS(idProducto) {
  carritoLocal = carritoLocal.filter(i => i.id_producto !== idProducto);
  actualizarCarritoDOM();
}

function actualizarCarritoDOM() {
  const container = document.getElementById('pos-cart-items');
  const countBadge = document.getElementById('cart-count-badge');
  if (!container) return;

  const totalItems = carritoLocal.reduce((s, i) => s + i.cantidad, 0);
  countBadge.textContent = `${totalItems} items`;

  if (carritoLocal.length === 0) {
    container.innerHTML = `
      <div class="text-center text-muted my-5 py-4">
        <div class="pos-icono-vacio"><i class="bi bi-basket3"></i></div>
        El carrito está vacío.<br>Haz clic en los productos para agregarlos.
      </div>
    `;
    document.getElementById('cart-subtotal').textContent = '$ 0';
    document.getElementById('cart-tax').textContent = '$ 0';
    document.getElementById('cart-total').textContent = '$ 0';
    document.getElementById('pos-btn-finalizar').disabled = true;
    calcularCambio();
    return;
  }

  container.innerHTML = carritoLocal.map(item => `
    <div class="cart-item">
      <div style="max-width: 60%;">
        <span class="fw-bold text-dark d-block text-truncate" style="font-size:0.9rem;">${escaparHtml(item.nombre)}</span>
        <span class="text-muted small">${formatearPrecioCOP(item.precio)} c/u</span>
      </div>
      <div class="d-flex align-items-center gap-1">
        <button class="btn btn-sm btn-outline-secondary py-0 px-1 font-weight-bold" onclick="modificarCantidadPOS(${item.id_producto}, -1)">-</button>
        <span class="mx-2 fw-bold" style="font-size: 0.9rem;">${item.cantidad}</span>
        <button class="btn btn-sm btn-outline-secondary py-0 px-1 font-weight-bold" onclick="modificarCantidadPOS(${item.id_producto}, 1)">+</button>
        <button class="btn btn-sm btn-light text-danger py-0 px-2 ms-2" onclick="eliminarDelCarritoPOS(${item.id_producto})"><i class="bi bi-trash"></i></button>
      </div>
    </div>
  `).join('');

  // Totales
  const subtotal = carritoLocal.reduce((s, i) => s + (i.precio * i.cantidad), 0);
  const tax = Math.round(subtotal * 0.19); // simulando el IVA ya incluido

  document.getElementById('cart-subtotal').textContent = formatearPrecioCOP(subtotal);
  document.getElementById('cart-tax').textContent = formatearPrecioCOP(tax);
  document.getElementById('cart-total').textContent = formatearPrecioCOP(subtotal);
  document.getElementById('pos-btn-finalizar').disabled = false;

  calcularCambio();
}

function calcularCambio() {
  const totalStr = document.getElementById('cart-total').textContent.replace(/[^\d]/g, '');
  const total = Number(totalStr) || 0;
  const recibido = Number(document.getElementById('pos-recibido').value) || 0;
  
  const cambio = recibido > total ? recibido - total : 0;
  document.getElementById('pos-cambio').textContent = formatearPrecioCOP(cambio);
}

async function finalizarVentaPOS() {
  if (carritoLocal.length === 0) return;

  const btn = document.getElementById('pos-btn-finalizar');
  const unidadNegocio = document.getElementById('pos-unidad-vender').value;

  if (confirm(`¿Finalizar venta registrada para la unidad de ${unidadNegocio}?`)) {
    btn.disabled = true;
    btn.textContent = 'Procesando venta…';

    try {
      // 1. Abrir Venta
      const respVenta = await apiFetch('api/ventas', {
        method: 'POST',
        body: { canal: 'Presencial', unidad_negocio: unidadNegocio }
      });

      if (!respVenta.exito) {
        showDashboardAlert(respVenta.mensaje || 'Error al abrir la venta en la caja.', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cash-coin me-1"></i>Registrar y Finalizar Venta';
        return;
      }

      const idVenta = respVenta.datos.id_venta;

      // 2. Agregar cada producto de forma secuencial (para evitar colisiones de stock)
      for (const prod of carritoLocal) {
        const respItem = await apiFetch(`api/ventas/${idVenta}/productos`, {
          method: 'POST',
          body: { id_producto: prod.id_producto, cantidad: prod.cantidad }
        });

        if (!respItem.exito) {
          showDashboardAlert(`Error al agregar ${prod.nombre}: ${respItem.mensaje}`, 'danger');
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-cash-coin me-1"></i>Registrar y Finalizar Venta';
          return;
        }
      }

      // 3. Finalizar Venta (esto descuenta inventario, materia prima y mete saldo a la caja)
      const respFinalizar = await apiFetch(`api/ventas/${idVenta}/finalizar`, {
        method: 'POST'
      });

      if (!respFinalizar.exito) {
        showDashboardAlert(respFinalizar.mensaje || 'Error al finalizar la transacción.', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cash-coin me-1"></i>Registrar y Finalizar Venta';
        return;
      }

      // Éxito completo
      showDashboardAlert(`¡Venta #${idVenta} finalizada con éxito! Total: ${document.getElementById('cart-total').textContent}`, 'success');
      
      // Reset POS
      carritoLocal = [];
      document.getElementById('pos-recibido').value = '';
      actualizarCarritoDOM();
      
      // Recargar catálogo para ver el stock actualizado
      await cargarProductosPOS();

    } catch (e) {
      showDashboardAlert('Ocurrió un error inesperado al registrar la venta.', 'danger');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-cash-coin me-1"></i>Registrar y Finalizar Venta';
    }
  }
}
