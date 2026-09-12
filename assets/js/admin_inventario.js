/**
 * assets/js/admin_inventario.js
 * Lógica EXCLUSIVA para gestión de stock de productos terminados (Vitrina).
 */

let todosProductosVitrina = [];
let modalProductoBs = null;
let esAdministrador = false;

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión
  const usuario = await obtenerSesionActual();
  if (!usuario || (usuario.rol !== 'Administrador' && usuario.rol !== 'Cajero')) {
    window.location.href = 'login_empleado.html';
    return;
  }

  esAdministrador = usuario.rol === 'Administrador';

  // 2. Inyectar Layout del Dashboard (Activa el botón "Inventario")
  await injectDashboardLayout(usuario.rol, 'admin_inventario');

  // 3. Inicializar instancias de modales Bootstrap
  modalProductoBs = new bootstrap.Modal(document.getElementById('modal-producto'));

  // 4. Inicializar listeners
  document.getElementById('form-modal-producto').addEventListener('submit', guardarProducto);
  document.getElementById('prod-foto').addEventListener('change', manejarSeleccionDeFoto);

  // 5. Cargar datos iniciales de vitrina
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
}

function renderizarProductosVitrina(productos) {
  const tbody = document.getElementById('tabla-productos-inventario');
  if (!tbody) return;

  if (productos.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">No se encontraron productos.</td></tr>`;
    return;
  }

  tbody.innerHTML = productos.map(p => {
    const isAgotado = p.stock <= 0;
    const badgeColor = p.unidad_negocio === 'Pastelería' ? 'badge-pastel-danger' : 'badge-pastel-success';
    const miniatura = p.tiene_foto
      ? `<img src="api/inventario/productos/${p.id_producto}/foto" alt="${escaparHtml(p.nombre)}" style="width:40px;height:40px;object-fit:cover;border-radius:8px;">`
      : `<div class="d-flex align-items-center justify-content-center" style="width:40px;height:40px;border-radius:8px;background:var(--db-bg-light);color:var(--db-espresso-suave);"><i class="bi bi-image"></i></div>`;

    return `
      <tr>
        <td>${miniatura}</td>
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
  resetearFotoModal();
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

  resetearFotoModal();
  if (p.tiene_foto) {
    // Muestra la foto que ya está guardada (sin necesidad de que el
    // Administrador la vuelva a subir si no quiere cambiarla).
    mostrarVistaPreviaFoto(`api/inventario/productos/${p.id_producto}/foto`);
  }

  document.getElementById('prod-stock-container').style.display = 'none';
  document.getElementById('modal-producto-titulo').textContent = 'Editar Producto';
  modalProductoBs.show();
}

/**
 * ------------------------------------------------------------
 * Manejo de la foto del producto (subir + vista previa)
 * ------------------------------------------------------------
 * fotoBase64Seleccionada guarda el resultado de FileReader como un
 * "data URL" (data:image/png;base64,XXXX). Se manda tal cual en el
 * body de guardarProducto() SOLO si el Administrador eligió un
 * archivo nuevo — si no, no se manda el campo, y el backend conserva
 * la foto que ya existía (ver Producto::actualizar()).
 */
let fotoBase64Seleccionada = null;

function resetearFotoModal() {
  fotoBase64Seleccionada = null;
  document.getElementById('prod-foto').value = '';
  document.getElementById('prod-foto-preview').style.display = 'none';
  document.getElementById('prod-foto-placeholder').style.display = 'flex';
}

function mostrarVistaPreviaFoto(src) {
  const img = document.getElementById('prod-foto-preview');
  img.src = src;
  img.style.display = 'block';
  document.getElementById('prod-foto-placeholder').style.display = 'none';
}

function manejarSeleccionDeFoto(e) {
  const archivo = e.target.files[0];
  if (!archivo) return;

  if (archivo.size > 4 * 1024 * 1024) {
    alert('La imagen es demasiado pesada (máximo 4MB).');
    e.target.value = '';
    return;
  }

  const lector = new FileReader();
  lector.onload = () => {
    fotoBase64Seleccionada = lector.result; // "data:image/png;base64,...."
    mostrarVistaPreviaFoto(fotoBase64Seleccionada);
  };
  lector.readAsDataURL(archivo);
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
  if (fotoBase64Seleccionada) {
    body.foto_base64 = fotoBase64Seleccionada;
  }

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