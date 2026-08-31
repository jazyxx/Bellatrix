/**
 * assets/js/admin_pedidos.js
 * Lógica de la bandeja de gestión de pedidos en línea.
 */

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión
  const usuario = await obtenerSesionActual();
  if (!usuario || (usuario.rol !== 'Administrador' && usuario.rol !== 'Cajero')) {
    window.location.href = 'login_empleado.html';
    return;
  }

  // 2. Inyectar Layout del Dashboard
  await injectDashboardLayout(usuario.rol, 'admin_pedidos');

  // 3. Cargar bandeja de pedidos iniciales
  cargarPedidosBandeja();
});

async function cargarPedidosBandeja() {
  const estado = document.getElementById('pedidos-estado-filtro').value;
  const tbody = document.getElementById('tabla-bandeja-pedidos');
  if (!tbody) return;

  tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">Buscando pedidos en cola…</td></tr>`;

  // Consultar pedidos por estado
  const respuesta = await apiFetch(`api/pedidos/gestion?estado=${encodeURIComponent(estado)}`);

  if (!respuesta.exito || !respuesta.datos) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">Error al cargar pedidos: ${respuesta.mensaje}</td></tr>`;
    return;
  }

  const pedidos = respuesta.datos;

  if (pedidos.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">No hay pedidos registrados en estado <strong>"${estado}"</strong>.</td></tr>`;
    return;
  }

  tbody.innerHTML = pedidos.map(p => {
    // Formatear los productos vendidos
    const itemsHTML = p.productos ? p.productos.map(item => `
      <div class="small text-muted d-flex justify-content-between border-bottom pb-1 mb-1">
        <span>${item.cantidad}x ${escaparHtml(item.nombre || 'Producto')}</span>
      </div>
    `).join('') : '<span class="text-muted small">Sin items listados</span>';

    // Botones de acción dinámicos según el estado de la cola
    let botonesGestion = '';

    if (p.estado === 'Confirmado') {
      botonesGestion = `
        <button class="btn btn-sm btn-db-primary me-1 py-1 font-weight-bold" onclick="cambiarEstadoPedido(${p.id_pedido}, 'En preparación')"><i class="bi bi-fire me-1"></i>Preparar</button>
        <button class="btn btn-sm btn-db-danger py-1 font-weight-bold" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Cancelado')"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
      `;
    } else if (p.estado === 'En preparación') {
      botonesGestion = `
        <button class="btn btn-sm btn-db-success me-1 py-1 font-weight-bold" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Listo para recoger')"><i class="bi bi-check2-square me-1"></i>Marcar Listo</button>
        <button class="btn btn-sm btn-db-danger py-1 font-weight-bold" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Cancelado')"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
      `;
    } else if (p.estado === 'Listo para recoger') {
      botonesGestion = `
        <button class="btn btn-sm btn-db-success me-1 py-1 font-weight-bold" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Entregado')"><i class="bi bi-truck me-1"></i>Despachar/Entregar</button>
        <button class="btn btn-sm btn-db-danger py-1 font-weight-bold" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Cancelado')"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
      `;
    } else if (p.estado === 'Pendiente de pago') {
      botonesGestion = `
        <span class="text-muted small italic">Esperando pasarela</span>
      `;
    } else {
      botonesGestion = `
        <span class="text-muted small">Ninguna acción</span>
      `;
    }

    const clienteNombre = p.nombre_cliente || p.id_cliente;

    return `
      <tr>
        <td><strong>#${p.id_pedido}</strong></td>
        <td>${formatearFecha(p.fecha_creacion || '')}</td>
        <td>
          <div class="fw-bold text-dark">${escaparHtml(clienteNombre)}</div>
          <span class="text-muted small">Cliente #${p.id_cliente}</span>
        </td>
        <td>
          <span class="small d-block text-truncate" style="max-width: 150px;">${escaparHtml(p.direccion_entrega || 'Recoge en Tienda')}</span>
        </td>
        <td><div style="max-height: 80px; overflow-y: auto; min-width: 140px;">${itemsHTML}</div></td>
        <td><strong class="text-success">${formatearPrecioCOP(p.total)}</strong></td>
        <td class="text-end" style="min-width: 160px;">${botonesGestion}</td>
      </tr>
    `;
  }).join('');
}

async function cambiarEstadoPedido(idPedido, nuevoEstado) {
  if (confirm(`¿Seguro que deseas mover el pedido #${idPedido} a estado "${nuevoEstado}"?`)) {
    const respuesta = await apiFetch(`api/pedidos/${idPedido}/estado`, {
      method: 'PUT',
      body: { estado: nuevoEstado }
    });

    if (!respuesta.exito) {
      alert(respuesta.mensaje || 'No se pudo cambiar el estado del pedido.');
      return;
    }

    showDashboardAlert(`Pedido #${idPedido} movido con éxito a "${nuevoEstado}".`, 'success');
    cargarPedidosBandeja();
  }
}

function formatearFecha(fechaStr) {
  if (!fechaStr) return '';
  const f = new Date(fechaStr);
  return f.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
