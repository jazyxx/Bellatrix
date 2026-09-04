/**
 * assets/js/admin_pedidos.js
 * Lógica de la bandeja de gestión de pedidos en línea.
 */

// Variable global para controlar el tiempo de actualización
let intervaloPedidos;

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión
  const usuario = await obtenerSesionActual();
  if (!usuario || (usuario.rol !== 'Administrador' && usuario.rol !== 'Cajero')) {
    window.location.href = 'login_empleado.html';
    return;
  }

  // 2. Inyectar Layout del Dashboard
  await injectDashboardLayout(usuario.rol, 'admin_pedidos');

  // 3. Cargar bandeja de pedidos iniciales y revisar alertas de cancelación
  cargarPedidosBandeja();
  revisarCancelaciones();

  // 4. Iniciar Auto-Refresh cada 15 segundos
  // Esto hará que la tabla se actualice sola sin tener que recargar la página
  intervaloPedidos = setInterval(() => {
    cargarPedidosBandeja();
    revisarCancelaciones();
  }, 15000);
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
    // 1. CORRECCIÓN VISUAL: Diseño limpio para los productos (sin el óvalo gris)
    const itemsHTML = p.productos && p.productos.length > 0 
      ? p.productos.map(item => `
          <div class="small text-dark mb-1">
            <strong class="text-primary">${item.cantidad}x</strong> 
            <span class="text-muted mx-1">|</span>
            ${escaparHtml(item.nombre_producto || item.nombre || 'Producto')}
          </div>
        `).join('') 
      : '<span class="text-muted small">Sin items listados</span>';

    const clienteNombre = p.nombre_cliente || (p.id_cliente ? `Cliente #${p.id_cliente}` : 'Cliente Desconocido');
    const telefonoCliente = p.telefono_cliente || 'Teléfono no disp.';

    // 2. CORRECCIÓN VISUAL: Contenedor flex con 'gap-2' para evitar que los botones colisionen
    let botonesGestion = '';

    if (p.estado === 'Confirmado') {
      botonesGestion = `
        <div class="d-flex flex-wrap justify-content-end gap-2">
          <button class="btn btn-sm btn-db-primary py-1 font-weight-bold shadow-sm" onclick="cambiarEstadoPedido(${p.id_pedido}, 'En preparación')"><i class="bi bi-fire me-1"></i>Preparar</button>
          <button class="btn btn-sm btn-db-danger py-1 font-weight-bold shadow-sm" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Cancelado')"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
        </div>
      `;
    } else if (p.estado === 'En preparación') {
      botonesGestion = `
        <div class="d-flex flex-wrap justify-content-end gap-2">
          <button class="btn btn-sm btn-db-success py-1 font-weight-bold shadow-sm" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Listo para recoger')"><i class="bi bi-check2-square me-1"></i>Marcar Listo</button>
          <button class="btn btn-sm btn-db-danger py-1 font-weight-bold shadow-sm" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Cancelado')"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
        </div>
      `;
    } else if (p.estado === 'Listo para recoger') {
      botonesGestion = `
        <div class="d-flex flex-wrap justify-content-end gap-2">
          <button class="btn btn-sm btn-db-success py-1 font-weight-bold shadow-sm" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Entregado')"><i class="bi bi-truck me-1"></i>Despachar/Entregar</button>
          <button class="btn btn-sm btn-db-danger py-1 font-weight-bold shadow-sm" onclick="cambiarEstadoPedido(${p.id_pedido}, 'Cancelado')"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
        </div>
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

    return `
      <tr class="align-middle">
        <td><strong>#${p.id_pedido}</strong></td>
        <td>
           <div class="small fw-bold text-dark">${formatearFecha(p.fecha_creacion || '')}</div>
        </td>
        <td>
          <div class="fw-bold text-dark">${escaparHtml(clienteNombre)}</div>
          <div class="text-muted small"><i class="bi bi-telephone-fill me-1"></i>${escaparHtml(telefonoCliente)}</div>
        </td>
        <td>
          <span class="small d-block pe-2">${escaparHtml(p.direccion_entrega || 'Recoge en Tienda')}</span>
        </td>
        <td>
          <div class="d-flex flex-column justify-content-center">${itemsHTML}</div>
        </td>
        <td><strong class="text-success h6 mb-0">${formatearPrecioCOP(p.total)}</strong></td>
        <td class="text-end" style="min-width: 170px;">${botonesGestion}</td>
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

// ==========================================
// NUEVO: SISTEMA DE ALERTAS Y AUTO-REFRESH
// ==========================================

async function revisarCancelaciones() {
    // Busca todos los pedidos cancelados
    const respuesta = await apiFetch('api/pedidos/gestion?estado=Cancelado');
    const alertasDiv = document.getElementById('alertas-cancelaciones');
    if (!alertasDiv) return;

    if (respuesta.exito && respuesta.datos && respuesta.datos.length > 0) {
        const hoy = new Date().toLocaleDateString('es-CO');
        
        // 1. Leemos de la memoria del navegador los pedidos que ya descartamos
        const alertasDescartadas = JSON.parse(localStorage.getItem('alertasDescartadas') || '[]');

        // 2. Filtramos: que sea de hoy Y que NO esté en la lista de descartados
        const canceladosHoy = respuesta.datos.filter(p => {
            const fechaAct = new Date(p.fecha_actualizacion || p.fecha_creacion).toLocaleDateString('es-CO');
            const esDeHoy = fechaAct === hoy;
            const noEstaDescartado = !alertasDescartadas.includes(p.id_pedido);
            
            return esDeHoy && noEstaDescartado;
        });

        if (canceladosHoy.length > 0) {
            // Fíjate que le pasamos el p.id_pedido a la función descartarAlerta
            alertasDiv.innerHTML = canceladosHoy.map(p => `
                <div class="alert alert-danger d-flex align-items-center justify-content-between p-3 mb-3 shadow-sm border-0" style="border-left: 5px solid #dc3545; background-color: #fffafb;">
                    <div>
                        <span class="badge bg-danger me-2 shadow-sm">🚨 URGENTE</span>
                        <strong>El cliente canceló el pedido #${p.id_pedido}</strong><br>
                        <span class="text-muted small">Total: <strong class="text-dark">${formatearPrecioCOP(p.total)}</strong>. Por favor, detén la preparación y verifica si requiere reembolso en caja/Nequi.</span>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" onclick="descartarAlerta(this, ${p.id_pedido})">Descartar Alerta</button>
                </div>
            `).join('');
            return;
        }
    }
    
    // Si no hay alertas nuevas, limpiamos el div
    alertasDiv.innerHTML = '';
}

// Actualizamos esta función para que reciba y guarde el ID del pedido
function descartarAlerta(btnElement, idPedido) {
    // 1. Obtenemos el arreglo actual de descartados
    const alertasDescartadas = JSON.parse(localStorage.getItem('alertasDescartadas') || '[]');
    
    // 2. Si el ID no está en la lista, lo agregamos y guardamos
    if (!alertasDescartadas.includes(idPedido)) {
        alertasDescartadas.push(idPedido);
        localStorage.setItem('alertasDescartadas', JSON.stringify(alertasDescartadas));
    }

    // 3. Quitamos la alerta visualmente
    btnElement.closest('.alert').remove();
}