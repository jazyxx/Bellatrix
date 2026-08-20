/**
 * assets/js/admin_caja.js
 * Lógica de conciliación de cajas y reportes financieros para Administradores (y consulta Cajeros).
 */

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión
  const usuario = await obtenerSesionActual();
  if (!usuario || (usuario.rol !== 'Administrador' && usuario.rol !== 'Cajero')) {
    window.location.href = 'login_empleado.html';
    return;
  }

  // 2. Inyectar Layout del Dashboard
  await injectDashboardLayout(usuario.rol, 'admin_caja');

  // 3. Habilitar secciones según el rol (Administrador vs Cajero)
  if (usuario.rol === 'Administrador') {
    document.getElementById('admin-only-section').style.display = 'flex';
    document.getElementById('cajero-restriction-banner').style.display = 'none';
    inicializarAdminCaja();
  } else {
    document.getElementById('admin-only-section').style.display = 'none';
    document.getElementById('cajero-restriction-banner').style.display = 'block';
  }

  // 4. Cargar la caja inicial del día por defecto
  consultarCajaHoy();
});

function inicializarAdminCaja() {
  // Listener de transacciones manuales (ingresos/egresos)
  const formTrans = document.getElementById('form-transaccion-caja');
  if (formTrans) {
    formTrans.addEventListener('submit', registrarTransaccionCaja);
  }

  // Setear fecha de hoy por defecto en reporte
  const repFechaIni = document.getElementById('rep-fecha-inicio');
  if (repFechaIni) {
    const hoy = new Date().toISOString().split('T')[0];
    repFechaIni.value = hoy;
  }
}

async function consultarCajaHoy() {
  const unidad = document.getElementById('caja-unidad-filtro').value;
  const canal = document.getElementById('caja-canal-filtro').value;

  const respuesta = await apiFetch(`api/cajas/hoy?canal=${encodeURIComponent(canal)}&unidad=${encodeURIComponent(unidad)}`);

  if (!respuesta.exito || !respuesta.datos) {
    showDashboardAlert(respuesta.mensaje || 'Error al consultar la caja del día.', 'danger');
    return;
  }

  const c = respuesta.datos;
  
  // Pintar valores KPI
  document.getElementById('kpi-ventas').textContent = formatearPrecioCOP(c.total_ventas || 0);
  document.getElementById('kpi-egresos').textContent = formatearPrecioCOP(c.total_egresos || 0);
  
  const saldo = Number(c.saldo) || (c.total_ventas - c.total_egresos);
  document.getElementById('kpi-saldo').textContent = formatearPrecioCOP(saldo);
}

async function registrarTransaccionCaja(e) {
  e.preventDefault();

  const tipo = document.querySelector('input[name="tipo_mov"]:checked').value;
  const monto = Number(document.getElementById('mov-monto').value);
  const unidad = document.getElementById('mov-unidad').value;
  const canal = document.getElementById('mov-canal').value;

  if (!monto || monto <= 0) {
    alert('Por favor ingresa un monto válido mayor a cero.');
    return;
  }

  const btn = document.getElementById('btn-registrar-mov');
  btn.disabled = true;
  btn.textContent = 'Procesando movimiento…';

  // Endpoint cambia según tipo
  const endpoint = tipo === 'egreso' ? 'api/cajas/egreso' : 'api/cajas/ingreso';

  const respuesta = await apiFetch(endpoint, {
    method: 'POST',
    body: {
      canal: canal,
      unidad_negocio: unidad,
      monto: monto
    }
  });

  if (!respuesta.exito) {
    // Si excede el saldo (bloqueo 422), el backend responde success:false
    // Mostramos la alerta de error pastel estilizada
    showDashboardAlert(respuesta.mensaje || 'El egreso no pudo registrarse por saldo insuficiente.', 'danger');
    btn.disabled = false;
    btn.textContent = '🚀 Procesar Movimiento';
    return;
  }

  // Éxito
  showDashboardAlert(`Movimiento registrado con éxito: ${tipo.toUpperCase()} por ${formatearPrecioCOP(monto)} en Caja ${unidad} (${canal}).`, 'success');
  
  // Limpiar formulario y reactivar botón
  document.getElementById('mov-monto').value = '';
  btn.disabled = false;
  btn.textContent = '🚀 Procesar Movimiento';

  // Sincronizar KPI de inmediato
  consultarCajaHoy();
}

function cambiarInputsReporte() {
  const tipo = document.getElementById('rep-tipo').value;
  const container = document.getElementById('rep-input-container');
  if (!container) return;

  const hoy = new Date().toISOString().split('T')[0];

  if (tipo === 'diario' || tipo === 'semanal') {
    container.innerHTML = `
      <label class="small text-muted d-block mb-1">Fecha ${tipo === 'semanal' ? 'Inicio' : ''}:</label>
      <input type="date" class="form-control form-db form-control-sm" id="rep-fecha-inicio" value="${hoy}">
    `;
  } else if (tipo === 'mensual') {
    const anio = new Date().getFullYear();
    const mes = new Date().getMonth() + 1;
    container.innerHTML = `
      <div class="row g-1">
        <div class="col-6">
          <label class="small text-muted d-block mb-1">Año:</label>
          <input type="number" class="form-control form-db form-control-sm" id="rep-anio" value="${anio}" min="2020" max="2035">
        </div>
        <div class="col-6">
          <label class="small text-muted d-block mb-1">Mes:</label>
          <input type="number" class="form-control form-db form-control-sm" id="rep-mes" value="${mes}" min="1" max="12">
        </div>
      </div>
    `;
  } else if (tipo === 'rango') {
    container.innerHTML = `
      <div class="row g-1">
        <div class="col-6">
          <label class="small text-muted d-block mb-1">Desde:</label>
          <input type="date" class="form-control form-db form-control-sm" id="rep-fecha-inicio" value="${hoy}">
        </div>
        <div class="col-6">
          <label class="small text-muted d-block mb-1">Hasta:</label>
          <input type="date" class="form-control form-db form-control-sm" id="rep-fecha-fin" value="${hoy}">
        </div>
      </div>
    `;
  }
}

async function generarReporte() {
  const tipo = document.getElementById('rep-tipo').value;
  let queryParams = '';

  if (tipo === 'diario') {
    const fecha = document.getElementById('rep-fecha-inicio').value;
    queryParams = `fecha=${fecha}`;
  } else if (tipo === 'semanal') {
    const inicio = document.getElementById('rep-fecha-inicio').value;
    queryParams = `inicio=${inicio}`;
  } else if (tipo === 'mensual') {
    const anio = document.getElementById('rep-anio').value;
    const mes = document.getElementById('rep-mes').value;
    queryParams = `anio=${anio}&mes=${mes}`;
  } else if (tipo === 'rango') {
    const inicio = document.getElementById('rep-fecha-inicio').value;
    const fin = document.getElementById('rep-fecha-fin').value;
    queryParams = `inicio=${inicio}&fin=${fin}`;
  }

  const endpoint = `api/cajas/reportes/${tipo}?${queryParams}`;
  const respuesta = await apiFetch(endpoint);
  const boxResult = document.getElementById('reporte-resultado-box');
  const tbody = document.getElementById('tabla-reporte-resultados');

  if (!boxResult || !tbody) return;

  if (!respuesta.exito || !respuesta.datos) {
    showDashboardAlert(respuesta.mensaje || 'Error al generar reporte financiero.', 'danger');
    boxResult.style.display = 'none';
    return;
  }

  boxResult.style.display = 'block';
  document.getElementById('reporte-titulo-consola').textContent = `Resultado Reporte ${tipo.toUpperCase()}`;

  let datos = respuesta.datos;
  // Si el backend responde con un objeto único en vez de arreglo, lo metemos en arreglo
  if (!Array.isArray(datos)) {
    datos = [datos];
  }

  if (datos.length === 0 || (datos.length === 1 && datos[0] === null)) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">No se encontraron movimientos financieros en este periodo.</td></tr>`;
    return;
  }

  tbody.innerHTML = datos.map(row => {
    if (!row) return '';
    const fechaCol = row.fecha || `${row.mes}/${row.anio}` || 'Consolidado';
    const totalV = Number(row.total_ventas || row.ventas || 0);
    const totalE = Number(row.total_egresos || row.egresos || 0);
    const saldo = Number(row.saldo || (totalV - totalE));

    return `
      <tr>
        <td><strong>${fechaCol}</strong></td>
        <td>🍰 ${row.unidad_negocio || 'Todos'}</td>
        <td>🌐 ${row.canal || 'Todos'}</td>
        <td class="text-success fw-bold">${formatearPrecioCOP(totalV)}</td>
        <td class="text-danger fw-bold">${formatearPrecioCOP(totalE)}</td>
        <td><strong class="text-primary">${formatearPrecioCOP(saldo)}</strong></td>
      </tr>
    `;
  }).join('');
}
