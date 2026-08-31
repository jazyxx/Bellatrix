/**
 * assets/js/admin_ventas.js
 * Lógica del panel histórico de ventas (CU005).
 */

let ventasLocales = [];

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión
  const usuario = await obtenerSesionActual();
  if (!usuario || (usuario.rol !== 'Administrador' && usuario.rol !== 'Cajero')) {
    window.location.href = 'login_empleado.html';
    return;
  }

  // 2. Inyectar Layout del Dashboard
  const activeTabId = usuario.rol === 'Administrador' ? 'admin_ventas' : 'cajero_ventas';
  await injectDashboardLayout(usuario.rol, activeTabId);

  // 3. Ajustar subtitulo de acuerdo al rol
  const sub = document.getElementById('ventas-subtitulo');
  if (sub) {
    if (usuario.rol === 'Administrador') {
      sub.textContent = 'Visualizando TODAS las transacciones registradas del sistema Ambrosía (Admin console, CU005).';
    } else {
      sub.textContent = 'Visualizando únicamente tus ventas registradas del día (CU005).';
    }
  }

  // 4. Cargar historial
  await cargarHistorialVentas();
});

async function cargarHistorialVentas() {
  const tbody = document.getElementById('tabla-ventas-historial');
  if (!tbody) return;

  tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">Buscando historial de caja…</td></tr>`;

  // Consultar ventas (el backend filtra automáticamente si es Cajero o Admin)
  const respuesta = await apiFetch('api/ventas');

  if (!respuesta.exito || !respuesta.datos) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">Error al cargar historial: ${respuesta.mensaje}</td></tr>`;
    return;
  }

  ventasLocales = respuesta.datos;
  renderizarVentasTabla(ventasLocales);
}

function renderizarVentasTabla(ventas) {
  const tbody = document.getElementById('tabla-ventas-historial');
  if (!tbody) return;

  if (ventas.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">No se registran transacciones en este periodo.</td></tr>`;
    return;
  }

  tbody.innerHTML = ventas.map(v => {
    let badgeClass = 'badge-pastel-success';
    if (v.estado === 'Anulada') badgeClass = 'badge-pastel-danger';

    const canalIcon = v.canal === 'Presencial' ? 'bi-shop' : 'bi-globe2';
    const unidadColor = v.unidad_negocio === 'Pastelería' ? 'badge-pastel-danger' : 'badge-pastel-success';

    return `
      <tr style="cursor: pointer;" onclick="verDetalleVenta(${v.id_venta})">
        <td data-label="ID"><strong>#${v.id_venta}</strong></td>
        <td data-label="FECHA/HORA">${formatearFecha(v.fecha || '')}</td>
        <td data-label="CANAL"><i class="bi ${canalIcon} me-1 text-muted"></i>${v.canal}</td>
        <td data-label="UNIDAD"><span class="badge-pastel ${unidadColor}" style="font-size:0.65rem;">${v.unidad_negocio}</span></td>
        <td data-label="TOTAL"><strong class="text-success">${formatearPrecioCOP(v.total)}</strong></td>
        <td data-label="ESTADO"><span class="badge-pastel ${badgeClass}">${v.estado}</span></td>
        <td data-label="DETALLE" class="text-center">
          <button class="btn btn-sm btn-light border py-1" onclick="event.stopPropagation(); verDetalleVenta(${v.id_venta})"><i class="bi bi-eye-fill me-1"></i>Ver</button>
        </td>
      </tr>
    `;
  }).join('');
}

function filtrarVentasLocales() {
  const canal = document.getElementById('ventas-filtro-canal').value;
  let filtradas = ventasLocales;

  if (canal !== 'todos') {
    filtradas = filtradas.filter(v => v.canal === canal);
  }

  renderizarVentasTabla(filtradas);
}

async function verDetalleVenta(idVenta) {
  const respuesta = await apiFetch(`api/ventas/${idVenta}`);
  if (!respuesta.exito || !respuesta.datos) {
    alert('No se pudo cargar el desglose de esta venta.');
    return;
  }

  const v = respuesta.datos;

  document.getElementById('venta-detalle-placeholder').style.display = 'none';
  const detailCard = document.getElementById('venta-detalle-card');
  detailCard.style.display = 'block';

  document.getElementById('detalle-id-titulo').textContent = `Detalle de Venta #${v.id_venta}`;
  document.getElementById('detalle-fecha').textContent = formatearFecha(v.fecha);
  document.getElementById('detalle-canal').textContent = v.canal;
  document.getElementById('detalle-unidad').textContent = v.unidad_negocio;
  document.getElementById('detalle-empleado').textContent = v.nombre_empleado || `Empleado #${v.id_empleado}`;
  document.getElementById('detalle-total').textContent = formatearPrecioCOP(v.total);

  // Pintar productos vendidos
  const list = document.getElementById('detalle-productos-lista');
  if (v.detalles && v.detalles.length > 0) {
    list.innerHTML = v.detalles.map(item => `
      <li class="d-flex justify-content-between py-1 border-bottom small text-muted">
        <span>${item.cantidad}x ${escaparHtml(item.nombre || 'Producto')}</span>
        <span>${formatearPrecioCOP(item.subtotal || (item.precio_unitario * item.cantidad))}</span>
      </li>
    `).join('');
  } else {
    list.innerHTML = `<li class="small text-muted py-2 text-center">Sin productos listados</li>`;
  }

  // Configurar botón de anulación (CU008)
  const btnAnular = document.getElementById('btn-anular-venta');
  if (v.estado === 'Activa') {
    btnAnular.style.display = 'block';
    btnAnular.onclick = () => anularTransaccionVenta(v.id_venta);
  } else {
    btnAnular.style.display = 'none';
  }

  detailCard.scrollIntoView({ behavior: 'smooth' });
}

async function anularTransaccionVenta(idVenta) {
  if (confirm(`ATENCIÓN: ¿Estás seguro de que deseas ANULAR la venta #${idVenta}? Se revertirán las cantidades vendidas al stock de productos y materias primas, y se descontará el dinero de la caja del día. Esta acción no se puede deshacer.`)) {
    const btn = document.getElementById('btn-anular-venta');
    btn.disabled = true;
    btn.textContent = 'Anulando transacción…';

    const respuesta = await apiFetch(`api/ventas/${idVenta}/anular`, { method: 'POST' });

    if (!respuesta.exito) {
      alert(respuesta.mensaje || 'Error al anular la venta.');
      btn.disabled = false;
      btn.textContent = 'Anular Transacción';
      return;
    }

    showDashboardAlert(`La venta #${idVenta} ha sido ANULADA correctamente. Inventario y Caja conciliados.`, 'success');
    cerrarDetalleVenta();
    await cargarHistorialVentas();
  }
}

function cerrarDetalleVenta() {
  document.getElementById('venta-detalle-card').style.display = 'none';
  document.getElementById('venta-detalle-placeholder').style.display = 'block';
}

function formatearFecha(fechaStr) {
  if (!fechaStr) return '';
  const f = new Date(fechaStr);
  return f.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
