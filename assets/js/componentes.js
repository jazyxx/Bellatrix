
async function inicializarHeader() {
  const zonaCuenta = document.getElementById('zona-cuenta');
  if (!zonaCuenta) return;

  const usuario = await obtenerSesionActual();

  if (usuario) {
    if (usuario.tipo === 'cliente') {
      zonaCuenta.innerHTML = `
        <span class="me-3 d-none d-md-inline" style="font-weight:700;">Hola, ${escaparHtml(primerNombre(usuario.nombre))}</span>
        <a href="cliente_dashboard.html" class="btn-ambrosia-outline btn-sm me-2">Mi panel</a>
        <button id="boton-cerrar-sesion" class="btn-ambrosia-outline btn-sm">Cerrar sesión</button>
      `;
      await actualizarContadorCarrito();
    } else if (usuario.tipo === 'empleado') {
      const linkLabel = usuario.rol === 'Administrador' ? 'Administrar' : 'Dashboard';
      zonaCuenta.innerHTML = `
        <span class="me-3 d-none d-md-inline" style="font-weight:700;">${escaparHtml(usuario.rol)}: ${escaparHtml(primerNombre(usuario.nombre))}</span>
        <a href="admin_pos.html" class="btn-ambrosia btn-sm me-2">${linkLabel}</a>
        <button id="boton-cerrar-sesion" class="btn-ambrosia-outline btn-sm">Salir</button>
      `;
    }
    document.getElementById('boton-cerrar-sesion').addEventListener('click', cerrarSesion);
  } else {
    zonaCuenta.innerHTML = `
      <a href="login_empleado.html" class="btn-sm me-3 text-muted text-decoration-none" style="font-weight:700; font-size: 0.9rem;">Portal Empleados</a>
      <a href="login.html" class="btn-ambrosia-outline btn-sm me-2">Iniciar sesión</a>
      <a href="registro.html" class="btn-ambrosia btn-sm">Crear cuenta</a>
    `;
  }
}

async function actualizarContadorCarrito() {
  const contador = document.getElementById('contador-carrito');
  if (!contador) return;

  const respuesta = await apiFetch('api/carrito');
  if (!respuesta.exito) return;

  const totalItems = respuesta.datos.items.reduce((suma, item) => suma + item.cantidad, 0);
  contador.textContent = totalItems;
  contador.style.display = totalItems > 0 ? 'flex' : 'none';
}

async function cerrarSesion() {
  await apiFetch('api/auth/logout', { method: 'POST' });
  window.location.href = 'index.html';
}

function tarjetaProductoHTML(producto) {
  const claseBadge = producto.unidad_negocio === 'Pastelería' ? 'badge-pasteleria' : 'badge-heladeria';
  const inicial = producto.nombre ? producto.nombre.charAt(0).toUpperCase() : '?';

  const imagen = producto.tiene_foto
    ? `<img src="api/inventario/productos/${producto.id_producto}/foto" alt="${escaparHtml(producto.nombre)}" class="tarjeta-producto__imagen" loading="lazy">`
    : `<div class="tarjeta-producto__imagen" aria-hidden="true">${escaparHtml(inicial)}</div>`;

  return `
    <div class="col-12 col-md-6 col-lg-3">
      <div class="tarjeta-producto">
        ${producto.agotado ? '<span class="badge-agotado">Agotado</span>' : ''}
        ${imagen}
        <div class="tarjeta-producto__cuerpo">
          <span class="${claseBadge} mb-2" style="width: fit-content;">${escaparHtml(producto.unidad_negocio)}</span>
          <h3 class="h6 fuente-display mb-1">${escaparHtml(producto.nombre)}</h3>
          <p class="small text-muted mb-2" style="min-height: 2.4em;">${escaparHtml(producto.descripcion || '')}</p>
          <div class="d-flex align-items-center justify-content-between">
            <span class="tarjeta-producto__precio">${formatearPrecioCOP(producto.precio)}</span>
            <button
              class="btn-ambrosia btn-sm boton-agregar-carrito"
              data-id-producto="${producto.id_producto}"
              ${producto.agotado ? 'disabled' : ''}
            >${producto.agotado ? 'Agotado' : 'Agregar'}</button>
          </div>
        </div>
      </div>
    </div>
  `;
}

function activarBotonesAgregarCarrito(contenedor) {
  contenedor.querySelectorAll('.boton-agregar-carrito').forEach((boton) => {
    boton.addEventListener('click', async () => {
      const usuario = await obtenerSesionActual();

      if (!usuario || usuario.tipo !== 'cliente') {
        sessionStorage.setItem('ambrosia_redirigir_despues_login', window.location.href);
        window.location.href = 'login.html?motivo=carrito';
        return;
      }

      const idProducto = Number(boton.dataset.idProducto);
      const textoOriginal = boton.textContent;
      boton.disabled = true;
      boton.textContent = 'Agregando…';

      const respuesta = await apiFetch('api/carrito/productos', {
        method: 'POST',
        body: { id_producto: idProducto, cantidad: 1 },
      });

      if (respuesta.exito) {
        boton.innerHTML = '<i class="bi bi-check-lg me-1"></i>¡Agregado!';
        await actualizarContadorCarrito();
        setTimeout(() => {
          boton.textContent = textoOriginal;
          boton.disabled = false;
        }, 1200);
      } else {
        alert(respuesta.mensaje || 'No se pudo agregar el producto al carrito.');
        boton.textContent = textoOriginal;
        boton.disabled = false;
      }
    });
  });
}

function escaparHtml(texto) {
  const div = document.createElement('div');
  div.textContent = texto ?? '';
  return div.innerHTML;
}
function primerNombre(nombreCompleto) {
  return (nombreCompleto || '').split(' ')[0];
}

document.addEventListener('DOMContentLoaded', inicializarHeader);
