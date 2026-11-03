
let idPedidoActual = null;

document.addEventListener('DOMContentLoaded', async () => {
  const usuario = await obtenerSesionActual();

  if (!usuario || usuario.tipo !== 'cliente') {
    sessionStorage.setItem('ambrosia_redirigir_despues_login', window.location.href);
    window.location.href = 'login.html?motivo=pago';
    return;
  }

  await inicializarHeader();
  inicializarVistaPago();
});

function inicializarVistaPago() {
  const params = new URLSearchParams(window.location.search);
  idPedidoActual = params.get('id_pedido');

  if (!idPedidoActual) {
    window.location.href = 'catalogo.html';
    return;
  }

  document.getElementById('pago-id-pedido').textContent = idPedidoActual;

  document.getElementById('medio-pago').addEventListener('change', manejarCambioMedioPago);
  document.getElementById('form-pago').addEventListener('submit', procesarPago);

  const btnSimular = document.getElementById('btn-simular-pago');
  if (btnSimular) {
    btnSimular.addEventListener('click', simularPago);
  }
}

function manejarCambioMedioPago(e) {
  const cajaNequi = document.getElementById('caja-celular-nequi');
  const inputCelular = document.getElementById('celular-nequi');

  if (e.target.value === 'Nequi') {
    cajaNequi.style.display = 'block';
    inputCelular.required = true;
  } else {
    cajaNequi.style.display = 'none';
    inputCelular.required = false;
    inputCelular.value = ''; 
  }
}

async function procesarPago(e) {
  e.preventDefault();

  const medioPago = document.getElementById('medio-pago').value;
  const inputCelular = document.getElementById('celular-nequi');
  const mensaje = document.getElementById('mensaje-pago');
  const boton = document.getElementById('btn-pagar');

  mensaje.style.display = 'none';
  boton.disabled = true;
  boton.textContent = 'Procesando...';

  const payload = {
    id_pedido: Number(idPedidoActual),
    medio_pago: medioPago
  };

  if (medioPago === 'Nequi') {
    payload.celular = inputCelular.value.trim();
  }

  const respuesta = await apiFetch('api/pagos', {
    method: 'POST',
    body: payload,
  });

  if (!respuesta.exito) {
    mensaje.textContent = respuesta.mensaje || 'Error al procesar el pago.';
    mensaje.style.display = 'block';
    boton.disabled = false;
    boton.textContent = 'Pagar Ahora';
    return;
  }

  mostrarPantallaExito(medioPago);
}

async function simularPago(e) {
  e.preventDefault();

  const mensaje = document.getElementById('mensaje-pago');
  const botonPagar = document.getElementById('btn-pagar');
  const botonSimular = document.getElementById('btn-simular-pago');

  mensaje.style.display = 'none';
  botonPagar.disabled = true;
  botonSimular.disabled = true;
  botonSimular.textContent = 'Simulando...';

  const resInicio = await apiFetch('api/pagos', {
    method: 'POST',
    body: { id_pedido: Number(idPedidoActual), medio_pago: 'Otro' },
  });

  if (!resInicio.exito) {
    mensaje.textContent = resInicio.mensaje || 'Error al iniciar simulación.';
    mensaje.style.display = 'block';
    botonPagar.disabled = false;
    botonSimular.disabled = false;
    botonSimular.textContent = 'Simular Pago Aprobado (Prueba)';
    return;
  }

  const resConfirmar = await apiFetch(`api/pagos/${resInicio.datos.id_pago}/confirmar`, {
    method: 'POST',
    body: { 
      aprobado: true, 
      referencia_pasarela: 'SIM-PRUEBA-' + Math.floor(Math.random() * 100000) 
    }
  });

  if (!resConfirmar.exito) {
    mensaje.textContent = resConfirmar.mensaje || 'Error al confirmar simulación.';
    mensaje.style.display = 'block';
    botonPagar.disabled = false;
    botonSimular.disabled = false;
    botonSimular.textContent = 'Simular Pago Aprobado (Prueba)';
    return;
  }

  mostrarPantallaExito('Simulación');
}

function mostrarPantallaExito(medioPago) {
  const contenedor = document.querySelector('.resumen-carrito');
  let textoSecundario = 'Tu pago se ha confirmado exitosamente.';

  if (medioPago === 'Nequi') {
    textoSecundario = '¡Notificación enviada! Revisa la app de Nequi en tu celular para aprobar el cobro. Cuando lo aceptes, procesaremos tu pedido automáticamente.';
  } else if (medioPago === 'Simulación') {
    textoSecundario = '¡Pago simulado con éxito! Tu pedido ha sido confirmado en la base de datos de pruebas.';
  }

  contenedor.innerHTML = `
    <div class="col-12 estado-vacio text-center">
      <div class="estado-vacio__icono"><i class="bi bi-check-circle"></i></div>
      
      <!-- Se añadieron los colores claros (var(--crema)) y opacidad para mejorar el contraste -->
      <h2 class="h4 mt-3" style="color: var(--crema, #FDFBF7); font-family: var(--fuente-display);">
        ¡Transacción Completada!
      </h2>
      <p class="mt-2 mb-4" style="color: var(--crema, #FDFBF7); opacity: 0.85;">
        ${textoSecundario}
      </p>
      
      <a href="catalogo.html" class="btn-ambrosia">Volver al catálogo</a>
    </div>`;
}