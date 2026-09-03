document.addEventListener('DOMContentLoaded', async () => {
  const usuario = await obtenerSesionActual();
  if (!usuario || usuario.tipo !== 'cliente') {
    window.location.href = 'login.html';
    return;
  }

  await injectDashboardLayout('Cliente', 'cliente_pedidos');
  cargarPedidos();
});

async function cargarPedidos() {
  const respuesta = await apiFetch('api/pedidos');
  const tbody = document.getElementById('tabla-pedidos');
  if (!tbody) return;

  if (!respuesta.exito || !respuesta.datos || respuesta.datos.length === 0) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">No tienes pedidos registrados.</td></tr>`;
    return;
  }

  tbody.innerHTML = respuesta.datos.map(p => {
    let badgeClass = 'badge-pastel-warning';
    if (p.estado === 'Entregado') badgeClass = 'badge-pastel-success';
    if (p.estado === 'Confirmado' || p.estado === 'En preparación' || p.estado === 'Listo para recoger') badgeClass = 'badge-pastel-primary';
    if (p.estado === 'Cancelado') badgeClass = 'badge-pastel-danger';

    // Construimos los botones dinámicamente según el estado
    let botonesAccion = '';

    if (p.estado === 'Cancelado') {
      // Si está cancelado, mostramos un texto tenue en lugar de botones
      botonesAccion = `<span class="text-muted small">Cancelado</span>`;
    } else {
      // Botón de Pago
      let botonPago = '';
      if (p.estado === 'Pendiente de pago') {
        botonPago = `<a href="pago.html?id_pedido=${p.id_pedido}" class="btn btn-sm btn-success py-1 mb-1 ms-1" title="Pagar ahora">
                      <i class="bi bi-credit-card-fill me-1"></i>Pagar
                     </a>`;
      }

      // Botón de Cancelar
      let botonCancelar = '';
      if (p.estado === 'Pendiente de pago' || p.estado === 'Confirmado') {
        botonCancelar = `<button class="btn btn-sm btn-outline-danger py-1 px-2 mb-1 ms-1" onclick="verCancelar(${p.id_pedido})" title="Cancelar Pedido">
                          <i class="bi bi-trash3-fill"></i>
                         </button>`;
      }

      // Ensamblamos los botones normales (Rastrear y Recibo siempre van si no está cancelado)
      botonesAccion = `
        <button class="btn btn-sm btn-db-primary py-1 mb-1" onclick="verTracking(${p.id_pedido})"><i class="bi bi-eye-fill me-1"></i>Rastrear</button>
        ${botonPago}
        <button class="btn btn-sm btn-outline-secondary py-1 px-2 mb-1 ms-1" onclick="abrirReciboVirtual(${p.id_pedido})" title="Imprimir Recibo"><i class="bi bi-receipt"></i></button>
        ${botonCancelar}
      `;
    }

    return `
      <tr>
        <td class="align-middle"><strong>#${p.id_pedido}</strong></td>
        <td class="align-middle">${formatearFecha(p.fecha_creacion || '')}</td>
        <td class="align-middle"><strong class="text-success">${formatearPrecioCOP(p.total)}</strong></td>
        <td class="align-middle"><span class="badge-pastel ${badgeClass}">${p.estado}</span></td>
        <td class="text-center">
          <div class="d-flex align-items-center justify-content-center flex-wrap">
            ${botonesAccion}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

async function verTracking(idPedido) {
  const respuesta = await apiFetch(`api/pedidos/${idPedido}`);
  if (!respuesta.exito) {
    alert(respuesta.mensaje || 'No se pudo obtener la información de este pedido.');
    return;
  }

  const p = respuesta.datos;
  const card = document.getElementById('tracking-card');
  card.style.display = 'block';
  
  document.getElementById('tracking-titulo').textContent = `Seguimiento de Pedido #${p.id_pedido}`;
  document.getElementById('tracking-direccion').textContent = p.direccion_entrega || 'Recoge en Tienda';
  document.getElementById('tracking-total').textContent = formatearPrecioCOP(p.total);

  const itemsContainer = document.getElementById('tracking-items');
  if (p.productos && p.productos.length > 0) {
    itemsContainer.innerHTML = p.productos.map(item => `
      <li class="d-flex justify-content-between py-1 border-bottom small text-muted">
        <span>${item.cantidad}x ${escaparHtml(item.nombre || 'Producto')}</span>
        <span>${formatearPrecioCOP(item.subtotal)}</span>
      </li>
    `).join('');
  } else {
    itemsContainer.innerHTML = '<li class="small text-muted">No hay items listados.</li>';
  }

  const steps = [
    { id: 'step-pendiente', state: 'Pendiente de pago' },
    { id: 'step-confirmado', state: 'Confirmado' },
    { id: 'step-preparacion', state: 'En preparación' },
    { id: 'step-recoger', state: 'Listo para recoger' },
    { id: 'step-entregado', state: 'Entregado' }
  ];

  let currentStepIdx = steps.findIndex(s => s.state === p.estado);
  if (p.estado === 'Cancelado') currentStepIdx = -1;

  steps.forEach((step, idx) => {
    const el = document.getElementById(step.id);
    if (!el) return;
    el.classList.remove('active', 'completed');
    if (idx < currentStepIdx) {
      el.classList.add('completed');
    } else if (idx === currentStepIdx || (p.estado === 'Pendiente de pago' && idx === 0)) {
      el.classList.add('active');
    }
  });

  card.scrollIntoView({ behavior: 'smooth' });
}

function cerrarTracking() {
  document.getElementById('tracking-card').style.display = 'none';
}

function formatearFecha(fechaStr) {
  if (!fechaStr) return '';
  const f = new Date(fechaStr);
  return f.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function abrirReciboVirtual(idPedido) {
  window.open(`recibo_pedido.html?id=${idPedido}`, '_blank', 'width=800,height=700');
}

// =========================================================
// NUEVAS FUNCIONES PARA CANCELAR PEDIDO
// =========================================================

function verCancelar(idPedido) {
  cerrarTracking(); // Ocultamos el tracking si estaba abierto
  const card = document.getElementById('cancelar-card');
  
  document.getElementById('cancelar-titulo').textContent = `Cancelar Pedido #${idPedido}`;
  
  // Le asignamos el ID al botón de confirmación
  const btnConfirmar = document.getElementById('btn-confirmar-cancelar');
  btnConfirmar.onclick = () => procesarCancelacion(idPedido);
  
  card.style.display = 'block';
  card.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function cerrarCancelar() {
  document.getElementById('cancelar-card').style.display = 'none';
}

async function procesarCancelacion(idPedido) {
  const btn = document.getElementById('btn-confirmar-cancelar');
  btn.disabled = true;
  btn.textContent = 'Cancelando...';

  // AQUÍ LLAMAMOS AL BACKEND PARA CAMBIAR EL ESTADO
  const respuesta = await apiFetch(`api/pedidos/${idPedido}/cancelar`, {
    method: 'PUT',
    body: { estado: 'Cancelado' }
  });

  btn.disabled = false;
  btn.textContent = 'Sí, cancelar pedido';

  if (respuesta.exito) {
    cerrarCancelar();
    cargarPedidos(); // Recargamos la tabla para ver el nuevo estado
    
    // Si tienes implementado el sistema de alertas del dashboard:
    if (typeof showDashboardAlert === 'function') {
      showDashboardAlert(`El pedido #${idPedido} fue cancelado exitosamente.`, 'success');
    } else {
      alert('Pedido cancelado exitosamente.');
    }
  } else {
    alert(respuesta.mensaje || 'Hubo un error al intentar cancelar el pedido.');
  }
}