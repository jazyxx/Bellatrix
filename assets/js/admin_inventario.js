/**
 * assets/js/admin_inventario.js
 * Lógica de gestión de inventario, recetas y materias primas.
 */

let todosProductosVitrina = [];
let todasMateriasPrimas = [];
let modalProductoBs = null;
let modalMateriaBs = null;
let esAdministrador = false;

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión
  const usuario = await obtenerSesionActual();
  if (!usuario || (usuario.rol !== 'Administrador' && usuario.rol !== 'Cajero')) {
    window.location.href = 'login_empleado.html';
    return;
  }

  esAdministrador = usuario.rol === 'Administrador';

  // 2. Inyectar Layout del Dashboard
  await injectDashboardLayout(usuario.rol, 'admin_inventario');

  // 3. Inicializar instancias de modales Bootstrap
  modalProductoBs = new bootstrap.Modal(document.getElementById('modal-producto'));
  modalMateriaBs = new bootstrap.Modal(document.getElementById('modal-materia'));

  // 4. Mostrar secciones de Administrador si aplica
  if (esAdministrador) {
    document.getElementById('alertas-stock-admin-section').style.display = 'block';
    document.getElementById('materias-primas-admin-section').style.display = 'block';
    document.getElementById('form-agregar-receta-linea').style.display = 'flex';
    
    document.getElementById('form-modal-materia').addEventListener('submit', guardarMateriaPrima);
    document.getElementById('form-agregar-receta-linea').addEventListener('submit', agregarLineaReceta);
    
    await cargarAlertasAbastecimiento();
    await cargarMateriasPrimas();
  }

  // 5. Inicializar listeners generales
  document.getElementById('form-modal-producto').addEventListener('submit', guardarProducto);

  // 6. Cargar datos iniciales de vitrina
  await cargarProductosVitrina();
});

async function cargarProductosVitrina() {
  const respuesta = await apiFetch('api/inventario/productos');
  if (!respuesta.exito || !respuesta.datos) {
    showDashboardAlert('Error al cargar productos del inventario.', 'danger');
    return;
  }

  todosProductosVitrina = respuesta.datos;
  renderizarProductosVitrina(todosProductosVitrina);

  // Cargar selectores de receta para Admin
  if (esAdministrador) {
    const selector = document.getElementById('receta-producto-selector');
    if (selector) {
      selector.innerHTML = `<option value="">-- Selecciona un Producto --</option>` + 
        todosProductosVitrina.map(p => `<option value="${p.id_producto}">${escaparHtml(p.nombre)}</option>`).join('');
    }
  }
}

function renderizarProductosVitrina(productos) {
  const tbody = document.getElementById('tabla-productos-inventario');
  if (!tbody) return;

  if (productos.length === 0) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-muted">No se encontraron productos.</td></tr>`;
    return;
  }

  tbody.innerHTML = productos.map(p => {
    const isAgotado = p.stock <= 0;
    const badgeColor = p.unidad_negocio === 'Pastelería' ? 'badge-pastel-danger' : 'badge-pastel-success';

    return `
      <tr>
        <td>
          <div class="fw-bold text-dark">${escaparHtml(p.nombre)}</div>
          <span class="badge-pastel ${badgeColor}" style="font-size:0.65rem;">${p.unidad_negocio} · ${p.tipo || 'Postre'}</span>
        </td>
        <td><strong class="text-success">${formatearPrecioCOP(p.precio)}</strong></td>
        <td>${p.unidad_negocio}</td>
        <td class="text-center">
          <div class="d-flex align-items-center justify-content-center gap-2">
            <button class="btn btn-sm btn-light border py-0 px-2 fw-bold" onclick="ajustarStockRapido(${p.id_producto}, -1)">-</button>
            <span class="fw-bold ${isAgotado ? 'text-danger' : 'text-dark'}" style="min-width: 30px;">${p.stock}</span>
            <button class="btn btn-sm btn-light border py-0 px-2 fw-bold" onclick="ajustarStockRapido(${p.id_producto}, 1)">+</button>
          </div>
        </td>
        <td class="text-end">
          <div class="dropdown">
            <button class="btn btn-sm btn-db-outline py-1 px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end small shadow border-0" style="border-radius: var(--db-radius-sm);">
              <li><a class="dropdown-item fw-bold" href="#" onclick="abrirEditarProducto(${p.id_producto}); return false;"><i class="bi bi-pencil-fill me-1"></i>Editar</a></li>
              ${esAdministrador ? `
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger fw-bold" href="#" onclick="eliminarProducto(${p.id_producto}); return false;"><i class="bi bi-trash-fill me-1"></i>Eliminar</a></li>
              ` : ''}
            </ul>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function filtrarProductosVitrina() {
  const query = document.getElementById('prod-buscar').value.toLowerCase().trim();
  const filtrados = todosProductosVitrina.filter(p => p.nombre.toLowerCase().includes(query) || (p.tipo && p.tipo.toLowerCase().includes(query)));
  renderizarProductosVitrina(filtrados);
}

async function ajustarStockRapido(idProducto, cantidad) {
  const resp = await apiFetch(`api/inventario/productos/${idProducto}/ajustar-stock`, {
    method: 'POST',
    body: { cantidad: cantidad }
  });

  if (!resp.exito) {
    alert(resp.mensaje || 'Error al ajustar el stock.');
    return;
  }

  await cargarProductosVitrina();
}

function abrirModalProducto() {
  document.getElementById('form-modal-producto').reset();
  document.getElementById('prod-id').value = '';
  document.getElementById('modal-producto-titulo').textContent = 'Crear Nuevo Producto';
  document.getElementById('prod-stock-container').style.display = 'block';
  modalProductoBs.show();
}

async function abrirEditarProducto(idProducto) {
  const resp = await apiFetch(`api/inventario/productos/${idProducto}`);
  if (!resp.exito || !resp.datos) {
    alert('No se pudo cargar la información del producto.');
    return;
  }

  const p = resp.datos;
  document.getElementById('prod-id').value = p.id_producto;
  document.getElementById('prod-nombre').value = p.nombre;
  document.getElementById('prod-descripcion').value = p.descripcion || '';
  document.getElementById('prod-unidad').value = p.unidad_negocio;
  document.getElementById('prod-tipo').value = p.tipo || 'Tortas';
  document.getElementById('prod-precio').value = p.precio;
  
  // Ocultamos el stock inicial al editar, ya que el stock se ajusta mediante +/- en la tabla
  document.getElementById('prod-stock-container').style.display = 'none';
  document.getElementById('modal-producto-titulo').textContent = 'Editar Producto';
  modalProductoBs.show();
}

async function guardarProducto(e) {
  e.preventDefault();

  const id = document.getElementById('prod-id').value;
  const nombre = document.getElementById('prod-nombre').value.trim();
  const descripcion = document.getElementById('prod-descripcion').value.trim();
  const unidad_negocio = document.getElementById('prod-unidad').value;
  const tipo = document.getElementById('prod-tipo').value;
  const precio = Number(document.getElementById('prod-precio').value);
  const stock = Number(document.getElementById('prod-stock').value) || 0;

  const body = { nombre, descripcion, unidad_negocio, tipo, precio };
  let endpoint = 'api/inventario/productos';
  let method = 'POST';

  if (id) {
    endpoint = `api/inventario/productos/${id}`;
    method = 'PUT';
  } else {
    body.stock = stock;
  }

  const resp = await apiFetch(endpoint, { method, body });

  if (!resp.exito) {
    alert(resp.mensaje || 'Error al guardar el producto.');
    return;
  }

  modalProductoBs.hide();
  showDashboardAlert(`Producto "${nombre}" guardado correctamente.`, 'success');
  await cargarProductosVitrina();
}

async function eliminarProducto(idProducto) {
  if (confirm('¿Estás seguro de que deseas eliminar este producto permanentemente de la vitrina?')) {
    const resp = await apiFetch(`api/inventario/productos/${idProducto}`, { method: 'DELETE' });
    if (!resp.exito) {
      alert(resp.mensaje || 'Error al eliminar el producto.');
      return;
    }
    showDashboardAlert('Producto eliminado de la vitrina con éxito.', 'success');
    await cargarProductosVitrina();
  }
}

/* =================================================================
   ADMIN ONLY: Materias Primas, Recetas y Alertas (CU019, CU018)
   ================================================================= */

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

  // Llenar tabla
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

  // Llenar selector de nueva linea de receta
  if (selectReceta) {
    selectReceta.innerHTML = `<option value="">-- Selecciona --</option>` + 
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

/* Recetas */
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

/* Alertas de Abastecimiento */
async function cargarAlertasAbastecimiento() {
  const resp = await apiFetch('api/inventario/alertas');
  const tbody = document.getElementById('tabla-alertas-activas');
  if (!tbody) return;

  if (!resp.exito || !resp.datos || resp.datos.length === 0) {
    tbody.innerHTML = `<tr><td colspan="5" class="text-center py-3 text-muted"><i class="bi bi-check-circle-fill me-1"></i>No hay alertas de stock bajo activas ahora mismo. ¡Excelente control!</td></tr>`;
    return;
  }

  tbody.innerHTML = resp.datos.map(a => `
    <tr class="table-warning">
      <td><strong>#${a.id_alerta}</strong></td>
      <td><strong>${escaparHtml(a.nombre_materia || 'Insumo')}</strong></td>
      <td><span class="text-dark small">${escaparHtml(a.mensaje)}</span></td>
      <td><span class="badge-pastel badge-pastel-danger">${a.estado}</span></td>
      <td class="text-center">
        <button class="btn btn-sm btn-db-success py-1 px-3 font-weight-bold" onclick="atenderAlertaStock(${a.id_alerta})"><i class="bi bi-check2 me-1"></i>Atender</button>
      </td>
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
