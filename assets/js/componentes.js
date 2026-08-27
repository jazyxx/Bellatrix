/**
 * ============================================================================
 *  componentes.js — Piezas de interfaz que se repiten en varias páginas
 * ============================================================================
 *  Dos responsabilidades:
 *    1. Pintar el header según haya o no sesión activa (Iniciar sesión vs.
 *       Mi cuenta / Cerrar sesión), y actualizar el contador del carrito.
 *    2. Generar el HTML de una tarjeta de producto, usado tanto en la
 *       Landing (destacados) como en el Catálogo completo.
 * ============================================================================
 */

/**
 * inicializarHeader()
 * ------------------------------------------------------------
 * Se llama una vez, al cargar cualquier página que tenga el header
 * de Ambrosía. Revisa la sesión y actualiza la zona de acciones del
 * header (#zona-cuenta) y el contador del carrito (#contador-carrito).
 */
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
      // Enlace dinámico según rol
      const linkLabel = usuario.rol === 'Administrador' ? 'Administrar' : 'Dashboard';
      zonaCuenta.innerHTML = `
        <span class="me-3 d-none d-md-inline" style="font-weight:700;">${escaparHtml(usuario.rol)}: ${escaparHtml(primerNombre(usuario.nombre))}</span>
        <a href="admin_pos.html" class="btn-ambrosia btn-sm me-2">${linkLabel}</a>
        <button id="boton-cerrar-sesion" class="btn-ambrosia-outline btn-sm">Salir</button>
      `;
    }
    document.getElementById('boton-cerrar-sesion').addEventListener('click', cerrarSesion);
  } else {
    // Sin sesión
    zonaCuenta.innerHTML = `
      <a href="login_empleado.html" class="btn-sm me-3 text-muted text-decoration-none" style="font-weight:700; font-size: 0.9rem;">Portal Empleados</a>
      <a href="login.html" class="btn-ambrosia-outline btn-sm me-2">Iniciar sesión</a>
      <a href="registro.html" class="btn-ambrosia btn-sm">Crear cuenta</a>
    `;
  }
}

/**
 * actualizarContadorCarrito()
 * Consulta el carrito real del cliente (si hay sesión) y actualiza
 * la burbuja numérica del ícono de carrito en el header.
 */
async function actualizarContadorCarrito() {
  const contador = document.getElementById('contador-carrito');
  if (!contador) return;

  const respuesta = await apiFetch('api/carrito');
  if (!respuesta.exito) return;

  const totalItems = respuesta.datos.items.reduce((suma, item) => suma + item.cantidad, 0);
  contador.textContent = totalItems;
  contador.style.display = totalItems > 0 ? 'flex' : 'none';
}

/**
 * cerrarSesion()
 * Cierra la sesión en el backend y recarga la página actual.
 */
async function cerrarSesion() {
  await apiFetch('api/auth/logout', { method: 'POST' });
  window.location.href = 'index.html';
}

/**
 * tarjetaProductoHTML(producto)
 * ------------------------------------------------------------
 * Recibe un producto (tal como lo devuelve GET /api/catalogo/productos)
 * y devuelve el HTML de su tarjeta, lista para insertar en una grilla.
 * Se usa tanto en index.html (destacados) como en catalogo.html.
 */
function tarjetaProductoHTML(producto) {
  const claseBadge = producto.unidad_negocio === 'Pastelería' ? 'badge-pasteleria' : 'badge-heladeria';
  const inicial = producto.nombre ? producto.nombre.charAt(0).toUpperCase() : '?';

  return `
    <div class="col-6 col-md-4 col-lg-3">
      <div class="tarjeta-producto">
        ${producto.agotado ? '<span class="badge-agotado">Agotado</span>' : ''}
        <div class="tarjeta-producto__imagen" aria-hidden="true">${escaparHtml(inicial)}</div>
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

/**
 * activarBotonesAgregarCarrito(contenedor)
 * ------------------------------------------------------------
 * Le conecta el comportamiento de "Agregar al carrito" a todos los
 * botones .boton-agregar-carrito dentro de un contenedor (llamar
 * DESPUÉS de insertar las tarjetas en el DOM).
 *
 * ESTE ES EL PUNTO DONDE SE EXIGE AUTENTICACIÓN: si no hay sesión de
 * Cliente activa, en vez de agregar el producto, se redirige a
 * login.html — tal como pide la Fase 5 ("flujo de checkout que exige
 * autenticación").
 */
function activarBotonesAgregarCarrito(contenedor) {
  contenedor.querySelectorAll('.boton-agregar-carrito').forEach((boton) => {
    boton.addEventListener('click', async () => {
      const usuario = await obtenerSesionActual();

      if (!usuario || usuario.tipo !== 'cliente') {
        // Guardamos a dónde volver después de iniciar sesión.
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

/** Utilidades pequeñas, usadas por varias páginas. */
function escaparHtml(texto) {
  const div = document.createElement('div');
  div.textContent = texto ?? '';
  return div.innerHTML;
}
function primerNombre(nombreCompleto) {
  return (nombreCompleto || '').split(' ')[0];
}

document.addEventListener('DOMContentLoaded', inicializarHeader);
