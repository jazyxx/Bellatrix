
let ventasLocales = [];

document.addEventListener('DOMContentLoaded', async () => {
  const usuario = await obtenerSesionActual();
  if (!usuario || (usuario.rol !== 'Administrador' && usuario.rol !== 'Cajero')) {
    window.location.href = 'login_empleado.html';
    return;
  }

  const activeTabId = usuario.rol === 'Administrador' ? 'admin_ventas' : 'cajero_ventas';
  await injectDashboardLayout(usuario.rol, activeTabId);

  const sub = document.getElementById('ventas-subtitulo');
  if (sub) {
    if (usuario.rol === 'Administrador') {
      sub.textContent = 'Visualizando TODAS las transacciones registradas del sistema Ambrosía.';
    } else {
      sub.textContent = 'Visualizando únicamente tus ventas registradas del día.';
    }
  }

  await cargarHistorialVentas();

  document.querySelectorAll('.opcion-canal').forEach(opcion => {
    opcion.addEventListener('click', function(e) {
      e.preventDefault();

            const texto = this.innerText;
      const valor = this.getAttribute('data-value');

      const btn = document.getElementById('btnCanales');
      if (btn) btn.innerText = texto;

      const input = document.getElementById('ventas-filtro-canal');
      if (input) input.value = valor;

      filtrarVentasLocales();
    });
  });

  document.querySelectorAll('.opcion-periodo').forEach(opcion => {
    opcion.addEventListener('click', function(e) {
      e.preventDefault();

      const texto = this.innerText;
      const valor = this.getAttribute('data-value');

      const btn = document.getElementById('btnPeriodo');
      if (btn) btn.innerText = texto;

      const input = document.getElementById('ventas-filtro-periodo');
      if (input) input.value = valor;

      const contenedorRango = document.getElementById('filtro-rango-personalizado');
      if (contenedorRango) {
        if (valor === 'personalizado') {
          contenedorRango.classList.remove('d-none');
        } else {
          contenedorRango.classList.add('d-none');
          filtrarVentasLocales(); 
        }
      }
    });
  });

  const btnAplicarRango = document.getElementById('btn-aplicar-rango');
  if (btnAplicarRango) {
    btnAplicarRango.addEventListener('click', () => {
      filtrarVentasLocales();
    });
  }
});

async function cargarHistorialVentas() {
  const tbody = document.getElementById('tabla-ventas-historial');
  if (!tbody) return;

  tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">Buscando historial de caja…</td></tr>`;

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
  const elCanal = document.getElementById('ventas-filtro-canal');
  const elPeriodo = document.getElementById('ventas-filtro-periodo');
  if (!elCanal || !elPeriodo) return;

  const canal = elCanal.value;
  const periodo = elPeriodo.value;

  let filtradas = [...ventasLocales];

  if (canal !== 'todos') {
    filtradas = filtradas.filter(v => v.canal.toLowerCase() === canal.toLowerCase());
  }

  if (periodo !== 'todos') {
    const ahora = new Date();
    let desde = null;
    let hasta = null;

    if (periodo === 'hoy') {
      desde = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate(), 0, 0, 0);
      hasta = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate(), 23, 59, 59);
    } else if (periodo === 'semana') {
      const dia = ahora.getDay(); 
      const diff = dia === 0 ? 6 : dia - 1;
      desde = new Date(ahora);
      desde.setDate(ahora.getDate() - diff);
      desde.setHours(0, 0, 0, 0);
      hasta = new Date(desde);
      hasta.setDate(desde.getDate() + 6);
      hasta.setHours(23, 59, 59, 999);
    } else if (periodo === 'mes') {
      desde = new Date(ahora.getFullYear(), ahora.getMonth(), 1, 0, 0, 0);
      hasta = new Date(ahora.getFullYear(), ahora.getMonth() + 1, 0, 23, 59, 59);
    } else if (periodo === 'personalizado') {
      const inputDesde = document.getElementById('filtro-fecha-desde');
      const inputHasta = document.getElementById('filtro-fecha-hasta');

      if (inputDesde && inputDesde.value) {
        desde = new Date(inputDesde.value + 'T00:00:00');
      }
      if (inputHasta && inputHasta.value) {
        hasta = new Date(inputHasta.value + 'T23:59:59');
      }
    }

    if (desde || hasta) {
      filtradas = filtradas.filter(v => {
        if (!v.fecha) return false;
        const fechaVenta = new Date(v.fecha);

        if (desde && fechaVenta < desde) return false;
        if (hasta && fechaVenta > hasta) return false;
        return true;
      });
    }
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

  const list = document.getElementById('detalle-productos-lista');
  if (v.detalles && v.detalles.length > 0) {
    list.innerHTML = v.detalles.map(item => `
      <li class="d-flex justify-content-between py-1 border-bottom small text-muted">
        <span>${item.cantidad}x ${escaparHtml(item.nombre_producto || item.nombre || 'Producto')}</span>
        <span>${formatearPrecioCOP(item.subtotal || (item.precio_unitario * item.cantidad))}</span>
      </li>
    `).join('');
  } else {
    list.innerHTML = `<li class="small text-muted py-2 text-center">Sin productos listados</li>`;
  }

  const btnAnular = document.getElementById('btn-anular-venta');
  if (v.estado === 'Activa' && !v.id_pedido) {
    btnAnular.style.display = 'block';
    btnAnular.onclick = () => anularTransaccionVenta(v.id_venta);
  } else {
    btnAnular.style.display = 'none';
  }

  const btnRecibo = document.getElementById('btn-imprimir-recibo');
  if (btnRecibo) {
    if (v.estado === 'Anulada') {
      btnRecibo.style.display = 'none';
    } else {
      btnRecibo.style.display = 'block';
      btnRecibo.onclick = () => window.open(`recibo_venta.html?id=${v.id_venta}`, '_blank', 'width=800,height=700');
    }
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
