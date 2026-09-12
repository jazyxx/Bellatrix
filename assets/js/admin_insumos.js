/**
 * assets/js/admin_insumos.js
 * Lógica EXCLUSIVA para gestión de materias primas, alertas y recetas.
 */

let todasMateriasPrimas = [];
let modalMateriaBs = null;

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión: EXCLUSIVO ADMINISTRADOR
  const usuario = await obtenerSesionActual();
  if (!usuario || usuario.rol !== 'Administrador') {
    window.location.href = 'login_empleado.html';
    return;
  }

  // 2. Inyectar Layout del Dashboard (Activa el botón "Insumos" si existe)
  await injectDashboardLayout(usuario.rol, 'admin_insumos');

  // 3. Inicializar instancias de modales Bootstrap
  modalMateriaBs = new bootstrap.Modal(document.getElementById('modal-materia'));

  // 4. Activar visualización de componentes (ya no necesitan if esAdministrador)
  document.getElementById('form-agregar-receta-linea').style.display = 'flex';
  
  // 5. Inicializar listeners
  document.getElementById('form-modal-materia').addEventListener('submit', guardarMateriaPrima);
  document.getElementById('form-agregar-receta-linea').addEventListener('submit', agregarLineaReceta);
  
  // 6. Cargar datos iniciales
  await cargarSelectoresProductos(); // Carga productos para el select de la receta
  await cargarAlertasAbastecimiento();
  await cargarMateriasPrimas();
});

// Función nueva para llenar el <select> de recetas sin tener la vitrina
async function cargarSelectoresProductos() {
  const respuesta = await apiFetch('api/inventario/productos');
  if (!respuesta.exito || !respuesta.datos) return;
  
  const selector = document.getElementById('receta-producto-selector');
  if (selector) {
    selector.innerHTML = `<option value="">Selecciona un Producto</option>` + 
      respuesta.datos.map(p => `<option value="${p.id_producto}">${escaparHtml(p.nombre)}</option>`).join('');
  }
}

async function cargarMateriasPrimas() {
  const resp = await apiFetch('api/inventario/materias-primas');
  const tbody = document.getElementById('tabla-materias-primas');
  const selectReceta = document.getElementById('receta-nueva-materia');
  if (!tbody) return;

  if (!resp.exito || !resp.datos) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-center py-3 text-muted">Error al cargar insumos.</td></tr>`;
    return;
  }

  todasMateriasPrimas = resp.datos;

  tbody.innerHTML = todasMateriasPrimas.map(m => {
    const isBajo = m.stock_actual <= m.stock_minimo;
    return `
      <tr>
        <td>
          <div class="fw-bold text-dark">${escaparHtml(m.nombre)}</div>
          <span class="text-muted small">${m.unidad_medida || 'unidades'}</span>
        </td>
        <td>
          <div class="d-flex align-items-center gap-1">
            <button class="btn btn-sm btn-light border py-0 px-1 fw-bold" style="font-size: 0.75rem;" onclick="ajustarMateriaStock(${m.id_materia}, 'descontar', 1)">-</button>
            <span class="fw-bold ${isBajo ? 'text-danger' : 'text-success'}">${m.stock_actual}</span>
            <button class="btn btn-sm btn-light border py-0 px-1 fw-bold" style="font-size: 0.75rem;" onclick="ajustarMateriaStock(${m.id_materia}, 'aumentar', 1)">+</button>
          </div>
        </td>
        <td class="text-center text-muted fw-bold">${m.stock_minimo}</td>
        <td class="text-end">
          <button class="btn btn-sm btn-db-danger py-0 px-2" style="font-size: 0.75rem;" onclick="eliminarMateriaPrima(${m.id_materia})"><i class="bi bi-trash-fill"></i></button>
        </td>
      </tr>
    `;
  }).join('');

  if (selectReceta) {
    selectReceta.innerHTML = `<option value="">Selecciona</option>` + 
      todasMateriasPrimas.map(m => `<option value="${m.id_materia}">${escaparHtml(m.nombre)} (${m.unidad_medida})</option>`).join('');
  }
}

async function ajustarMateriaStock(idMateria, tipo, cantidad) {
  const resp = await apiFetch(`api/inventario/materias-primas/${idMateria}/ajustar-stock`, {
    method: 'POST',
    body: { tipo, cantidad }
  });

  if (!resp.exito) {
    alert(resp.mensaje || 'Error al ajustar el insumo.');
    return;
  }

  await cargarMateriasPrimas();
  await cargarAlertasAbastecimiento();
}

function abrirModalMateria() {
  document.getElementById('form-modal-materia').reset();
  modalMateriaBs.show();
}

async function guardarMateriaPrima(e) {
  e.preventDefault();

  const nombre = document.getElementById('mat-nombre').value.trim();
  const unidad_medida = document.getElementById('mat-unidad').value.trim();
  const stock_minimo = Number(document.getElementById('mat-minimo').value);
  const stock_actual = Number(document.getElementById('mat-stock').value) || 0;

  const resp = await apiFetch('api/inventario/materias-primas', {
    method: 'POST',
    body: { nombre, unidad_medida, stock_minimo, stock_actual }
  });

  if (!resp.exito) {
    alert(resp.mensaje || 'Error al registrar insumo.');
    return;
  }

  modalMateriaBs.hide();
  showDashboardAlert(`Insumo "${nombre}" registrado correctamente.`, 'success');
  await cargarMateriasPrimas();
  await cargarAlertasAbastecimiento();
}

async function eliminarMateriaPrima(idMateria) {
  if (confirm('¿Eliminar esta materia prima? Se romperá la receta asociada.')) {
    const resp = await apiFetch(`api/inventario/materias-primas/${idMateria}`, { method: 'DELETE' });
    if (!resp.exito) {
      alert(resp.mensaje || 'Error al eliminar el insumo.');
      return;
    }
    await cargarMateriasPrimas();
  }
}

/* =================================================================
   Recetas 
   ================================================================= */
async function cargarRecetaDeProducto() {
  const idProducto = document.getElementById('receta-producto-selector').value;
  const tbody = document.getElementById('tabla-lineas-receta');
  if (!tbody) return;

  if (!idProducto) {
    tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-3">Selecciona un producto arriba.</td></tr>`;
    return;
  }

  const resp = await apiFetch(`api/inventario/productos/${idProducto}/receta`);

  if (!resp.exito || !resp.datos || resp.datos.length === 0) {
    tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-3">Este producto aún no tiene fórmula o ingredientes registrados.</td></tr>`;
    return;
  }

  tbody.innerHTML = resp.datos.map(r => `
    <tr>
      <td><strong>${escaparHtml(r.nombre_materia || r.id_materia)}</strong></td>
      <td class="text-center fw-bold text-dark">${r.cantidad} ${r.unidad_medida || ''}</td>
      <td class="text-end">
        <button class="btn btn-sm btn-light text-danger py-0 px-2" onclick="eliminarLineaReceta(${r.id_receta})"><i class="bi bi-trash-fill"></i></button>
      </td>
    </tr>
  `).join('');
}

async function agregarLineaReceta(e) {
  e.preventDefault();

  const idProducto = document.getElementById('receta-producto-selector').value;
  const idMateria = document.getElementById('receta-nueva-materia').value;
  const cantidad = Number(document.getElementById('receta-nueva-cantidad').value);

  if (!idProducto) {
    alert('Por favor selecciona primero un producto.');
    return;
  }

  const resp = await apiFetch('api/inventario/recetas', {
    method: 'POST',
    body: {
      id_producto: Number(idProducto),
      id_materia: Number(idMateria),
      cantidad: cantidad
    }
  });

  if (!resp.exito) {
    alert(resp.mensaje || 'Error al agregar ingrediente a la receta.');
    return;
  }

  document.getElementById('receta-nueva-cantidad').value = '';
  await cargarRecetaDeProducto();
}

async function eliminarLineaReceta(idReceta) {
  if (confirm('¿Eliminar este insumo de la fórmula del producto?')) {
    const resp = await apiFetch(`api/inventario/recetas/${idReceta}`, { method: 'DELETE' });
    if (!resp.exito) {
      alert(resp.mensaje || 'Error al eliminar el ingrediente.');
      return;
    }
    await cargarRecetaDeProducto();
  }
}

/* =================================================================
   Alertas de Abastecimiento 
   ================================================================= */
async function cargarAlertasAbastecimiento() {
  const resp = await apiFetch('api/inventario/alertas');
  const tbody = document.getElementById('tabla-alertas-activas');
  if (!tbody) return;

  if (!resp.exito || !resp.datos || resp.datos.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-center py-3 text-muted"><i class="bi bi-check-circle-fill me-1"></i>No hay alertas de stock bajo activas ahora mismo. ¡Excelente control!</td></tr>`;
    return;
  }

  tbody.innerHTML = resp.datos.map(a => `
    <tr class="table-warning">
      <td><strong>#${a.id_alerta}</strong></td>
      <td><strong>${escaparHtml(a.nombre_materia || 'Insumo')}</strong></td>
      <td><span class="text-dark small">${escaparHtml(a.mensaje)}</span></td>
      <td><span class="badge-pastel badge-pastel-danger">${a.estado}</span></td>
    </tr>
  `).join('');
}

async function atenderAlertaStock(idAlerta) {
  const resp = await apiFetch(`api/inventario/alertas/${idAlerta}/atender`, { method: 'POST' });
  if (!resp.exito) {
    alert(resp.mensaje || 'Error al atender la alerta.');
    return;
  }

  showDashboardAlert('Alerta de stock atendida y archivada con éxito.', 'success');
  await cargarAlertasAbastecimiento();
  await cargarMateriasPrimas();
}