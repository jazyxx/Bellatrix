/**
 * assets/js/cliente_dashboard.js
 * Lógica del panel de control de Clientes.
 */

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión
  const usuario = await obtenerSesionActual();
  if (!usuario || usuario.tipo !== 'cliente') {
    window.location.href = 'login.html';
    return;
  }

  // 2. Inyectar Layout del Dashboard
  await injectDashboardLayout('Cliente', 'cliente_pedidos');

  // 3. Pintar Perfil
  document.getElementById('perfil-nombre').textContent = usuario.nombre || 'Cliente';
  document.getElementById('perfil-correo').textContent = usuario.identificador || '';

  // 4. Cargar datos
  cargarPedidos();
  cargarNotificaciones();
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

    return `
      <tr>
        <td><strong>#${p.id_pedido}</strong></td>
        <td>${formatearFecha(p.fecha_creacion || '')}</td>
        <td><strong class="text-success">${formatearPrecioCOP(p.total)}</strong></td>
        <td><span class="badge-pastel ${badgeClass}">${p.estado}</span></td>
        <td class="text-center">
          <button class="btn btn-sm btn-db-primary py-1 mb-1" onclick="verTracking(${p.id_pedido})"><i class="bi bi-eye-fill me-1"></i>Rastrear</button>
          <button class="btn btn-sm btn-outline-secondary py-1 mb-1 ms-1" onclick="abrirReciboVirtual(${p.id_pedido})" title="Imprimir Recibo"><i class="bi bi-receipt me-1"></i>Recibo</button>
        </td>
      </tr>
    `;
  }).join('');
}

async function cargarNotificaciones() {
  const respuesta = await apiFetch('api/notificaciones');
  const container = document.getElementById('lista-notificaciones');
  if (!container) return;

  if (!respuesta.exito || !respuesta.datos || respuesta.datos.length === 0) {
    container.innerHTML = `<p class="text-muted small text-center my-4">No tienes notificaciones pendientes.</p>`;
    return;
  }

  container.innerHTML = respuesta.datos.map(n => `
    <div class="p-2 border-bottom mb-2">
      <div class="d-flex align-items-center justify-content-between mb-1">
        <span class="badge-pastel badge-pastel-primary" style="font-size: 0.65rem;">Mensaje</span>
        <span class="text-muted" style="font-size: 0.7rem;">${formatearFecha(n.fecha_creacion || '')}</span>
      </div>
      <p class="mb-0 small text-dark">${escaparHtml(n.mensaje)}</p>
    </div>
  `).join('');
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

  // Pintar productos
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

  // Actualizar los Steppers de tracking
  // Estados válidos: 'Pendiente de pago' -> 'Confirmado' -> 'En preparación' -> 'Listo para recoger' -> 'Entregado' (o 'Cancelado')
  const steps = [
    { id: 'step-pendiente', state: 'Pendiente de pago' },
    { id: 'step-confirmado', state: 'Confirmado' },
    { id: 'step-preparacion', state: 'En preparación' },
    { id: 'step-recoger', state: 'Listo para recoger' },
    { id: 'step-entregado', state: 'Entregado' }
  ];

  let currentStepIdx = steps.findIndex(s => s.state === p.estado);
  if (p.estado === 'Cancelado') {
    currentStepIdx = -1; // Desactivar barra si está cancelado
  }

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

  // Scroll suave al tracking card
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
  // Abre una pestaña nueva enfocada solo en el recibo
  window.open(`recibo_pedido.html?id=${idPedido}`, '_blank', 'width=800,height=700');
}