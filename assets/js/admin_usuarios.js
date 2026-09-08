/**
 * assets/js/admin_usuarios.js
 * Lógica de gestión de usuarios (CU003): CRUD de Empleados
 * (Administrador/Cajero) y gestión de Clientes desde el panel de
 * Administrador. Exclusivo del rol Administrador.
 */

let todosLosEmpleados = [];
let todosLosClientes = [];
let modalEmpleadoBs = null;
let modalClienteBs = null;
let modalContrasenaBs = null;

document.addEventListener('DOMContentLoaded', async () => {
  // 1. Guardia de sesión — exclusivo Administrador.
  const usuario = await obtenerSesionActual();
  if (!usuario || usuario.rol !== 'Administrador') {
    window.location.href = 'login_empleado.html';
    return;
  }

  // 2. Inyectar Layout del Dashboard
  await injectDashboardLayout('Administrador', 'admin_usuarios');

  // 3. Inicializar instancias de modales Bootstrap
  modalEmpleadoBs = new bootstrap.Modal(document.getElementById('modal-empleado'));
  modalClienteBs = new bootstrap.Modal(document.getElementById('modal-cliente'));
  modalContrasenaBs = new bootstrap.Modal(document.getElementById('modal-contrasena'));

  // 4. Listeners de formularios
  document.getElementById('form-modal-empleado').addEventListener('submit', guardarEmpleado);
  document.getElementById('form-modal-cliente').addEventListener('submit', guardarCliente);
  document.getElementById('form-modal-contrasena').addEventListener('submit', guardarContrasenaEmpleado);

  // 5. Cargar datos iniciales
  await cargarEmpleados();
  await cargarClientes();
});

/* =================================================================
   EMPLEADOS (Administrador / Cajero)
   ================================================================= */

async function cargarEmpleados() {
  const resp = await apiFetch('api/usuarios/empleados');
  if (!resp.exito || !resp.datos) {
    showDashboardAlert('Error al cargar los empleados.', 'danger');
    return;
  }
  todosLosEmpleados = resp.datos;
  renderizarEmpleados(todosLosEmpleados);
}

function renderizarEmpleados(empleados) {
  const tbody = document.getElementById('tabla-empleados');
  if (!tbody) return;

  if (empleados.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">No se encontraron empleados.</td></tr>`;
    return;
  }

  tbody.innerHTML = empleados.map(e => {
    const badgeRol = e.rol === 'Administrador' ? 'badge-pastel-primary' : 'badge-pastel-warning';
    const badgeEstado = e.activo ? 'badge-pastel-success' : 'badge-pastel-danger';
    const textoEstado = e.activo ? 'Activo' : 'Inactivo';
    const accionEstado = e.activo
      ? `<li><a class="dropdown-item text-danger fw-bold" href="#" onclick="cambiarEstadoEmpleado(${e.id_empleado}, false); return false;"><i class="bi bi-slash-circle me-1"></i>Desactivar</a></li>`
      : `<li><a class="dropdown-item text-success fw-bold" href="#" onclick="cambiarEstadoEmpleado(${e.id_empleado}, true); return false;"><i class="bi bi-check-circle me-1"></i>Activar</a></li>`;

    return `
      <tr>
        <td>
          <div class="fw-bold text-dark">${escaparHtml(e.nombre)} ${escaparHtml(e.apellido || '')}</div>
          <span class="badge-pastel ${badgeRol}" style="font-size:0.65rem;">${e.rol}</span>
        </td>
        <td>${escaparHtml(e.usuario)}</td>
        <td>${escaparHtml(e.correo)}</td>
        <td>${e.rol}</td>
        <td class="text-center"><span class="badge-pastel ${badgeEstado}">${textoEstado}</span></td>
        <td class="text-end">
          <div class="dropdown">
            <button class="btn btn-sm btn-db-outline py-1 px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end small shadow border-0" style="border-radius: var(--db-radius-sm);">
              <li><a class="dropdown-item fw-bold" href="#" onclick="abrirEditarEmpleado(${e.id_empleado}); return false;"><i class="bi bi-pencil-fill me-1"></i>Editar</a></li>
              <li><a class="dropdown-item fw-bold" href="#" onclick="abrirModalContrasena(${e.id_empleado}, '${escaparHtml(e.nombre)}'); return false;"><i class="bi bi-shield-lock-fill me-1"></i>Restablecer contraseña</a></li>
              <li><hr class="dropdown-divider"></li>
              ${accionEstado}
            </ul>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function filtrarEmpleados() {
  const query = document.getElementById('emp-buscar').value.toLowerCase().trim();
  const filtrados = todosLosEmpleados.filter(e =>
    e.nombre.toLowerCase().includes(query) ||
    e.usuario.toLowerCase().includes(query) ||
    e.correo.toLowerCase().includes(query)
  );
  renderizarEmpleados(filtrados);
}

function alternarCamposRolEmpleado() {
  const esAdmin = document.getElementById('emp-rol').value === 'Administrador';
  document.getElementById('emp-nivel-acceso-container').style.display = esAdmin ? 'block' : 'none';
  document.getElementById('emp-turno-container').style.display = esAdmin ? 'none' : 'block';
}

function abrirModalEmpleado() {
  document.getElementById('form-modal-empleado').reset();
  document.getElementById('emp-id').value = '';
  document.getElementById('modal-empleado-titulo').textContent = 'Nuevo Empleado';
  document.getElementById('emp-usuario').disabled = false;
  document.getElementById('emp-rol').disabled = false;
  document.getElementById('emp-contrasena-container').style.display = 'block';
  document.getElementById('emp-contrasena').required = true;
  alternarCamposRolEmpleado();
  modalEmpleadoBs.show();
}

async function abrirEditarEmpleado(idEmpleado) {
  const resp = await apiFetch(`api/usuarios/empleados/${idEmpleado}`);
  if (!resp.exito || !resp.datos) {
    alert(resp.mensaje || 'No se pudo cargar la información del empleado.');
    return;
  }

  const e = resp.datos;
  document.getElementById('form-modal-empleado').reset();
  document.getElementById('emp-id').value = e.id_empleado;
  document.getElementById('emp-nombre').value = e.nombre;
  document.getElementById('emp-apellido').value = e.apellido || '';
  document.getElementById('emp-usuario').value = e.usuario;
  document.getElementById('emp-correo').value = e.correo;
  document.getElementById('emp-telefono').value = e.telefono || '';
  document.getElementById('emp-salario').value = e.salario || '';
  document.getElementById('emp-rol').value = e.rol;
  document.getElementById('emp-nivel-acceso').value = e.nivel_acceso || 1;
  document.getElementById('emp-turno').value = e.turno || '';

  // El rol no se puede editar (ver UsuarioController::actualizarEmpleado).
  document.getElementById('emp-rol').disabled = true;

  // La contraseña se cambia solo desde "Restablecer contraseña".
  document.getElementById('emp-contrasena-container').style.display = 'none';
  document.getElementById('emp-contrasena').required = false;

  document.getElementById('modal-empleado-titulo').textContent = 'Editar Empleado';
  alternarCamposRolEmpleado();
  modalEmpleadoBs.show();
}

async function guardarEmpleado(evento) {
  evento.preventDefault();

  const id = document.getElementById('emp-id').value;
  const rol = document.getElementById('emp-rol').value;

  const body = {
    nombre: document.getElementById('emp-nombre').value.trim(),
    apellido: document.getElementById('emp-apellido').value.trim(),
    usuario: document.getElementById('emp-usuario').value.trim(),
    correo: document.getElementById('emp-correo').value.trim(),
    telefono: document.getElementById('emp-telefono').value.trim(),
    salario: document.getElementById('emp-salario').value,
  };

  let endpoint = 'api/usuarios/empleados';
  let method = 'POST';

  if (id) {
    endpoint = `api/usuarios/empleados/${id}`;
    method = 'PUT';
  } else {
    body.rol = rol;
    body.contrasena = document.getElementById('emp-contrasena').value;
    if (rol === 'Administrador') {
      body.nivel_acceso = Number(document.getElementById('emp-nivel-acceso').value) || 1;
    } else {
      body.turno = document.getElementById('emp-turno').value.trim();
    }
  }

  const resp = await apiFetch(endpoint, { method, body });

  if (!resp.exito) {
    alert(resp.mensaje || 'Error al guardar el empleado.');
    return;
  }

  modalEmpleadoBs.hide();
  showDashboardAlert(id ? 'Empleado actualizado con éxito.' : 'Empleado creado con éxito.', 'success');
  await cargarEmpleados();
}

async function cambiarEstadoEmpleado(idEmpleado, activar) {
  const accion = activar ? 'activar' : 'desactivar';
  const confirmMsg = activar
    ? '¿Deseas reactivar el acceso de este empleado?'
    : '¿Deseas desactivar el acceso de este empleado? Podrás reactivarlo cuando quieras.';

  if (!confirm(confirmMsg)) return;

  const resp = activar
    ? await apiFetch(`api/usuarios/empleados/${idEmpleado}/activar`, { method: 'POST' })
    : await apiFetch(`api/usuarios/empleados/${idEmpleado}`, { method: 'DELETE' });

  if (!resp.exito) {
    alert(resp.mensaje || `No se pudo ${accion} el empleado.`);
    return;
  }

  showDashboardAlert(`Empleado ${activar ? 'activado' : 'desactivado'} correctamente.`, 'success');
  await cargarEmpleados();
}

function abrirModalContrasena(idEmpleado, nombre) {
  document.getElementById('form-modal-contrasena').reset();
  document.getElementById('pass-emp-id').value = idEmpleado;
  document.getElementById('pass-emp-nombre').textContent = nombre || 'este empleado';
  modalContrasenaBs.show();
}

async function guardarContrasenaEmpleado(evento) {
  evento.preventDefault();

  const id = document.getElementById('pass-emp-id').value;
  const nueva_contrasena = document.getElementById('pass-nueva').value;

  const resp = await apiFetch(`api/usuarios/empleados/${id}/contrasena`, {
    method: 'PUT',
    body: { nueva_contrasena },
  });

  if (!resp.exito) {
    alert(resp.mensaje || 'No se pudo actualizar la contraseña.');
    return;
  }

  modalContrasenaBs.hide();
  showDashboardAlert('Contraseña actualizada correctamente.', 'success');
}

/* =================================================================
   CLIENTES
   ================================================================= */

async function cargarClientes() {
  const resp = await apiFetch('api/usuarios/clientes');
  if (!resp.exito || !resp.datos) {
    showDashboardAlert('Error al cargar los clientes.', 'danger');
    return;
  }
  todosLosClientes = resp.datos;
  renderizarClientes(todosLosClientes);
}

function renderizarClientes(clientes) {
  const tbody = document.getElementById('tabla-clientes');
  if (!tbody) return;

  if (clientes.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">No se encontraron clientes.</td></tr>`;
    return;
  }

  tbody.innerHTML = clientes.map(c => {
    const badgeEstado = c.activo ? 'badge-pastel-success' : 'badge-pastel-danger';
    const textoEstado = c.activo ? 'Activo' : 'Inactivo';
    const accionEstado = c.activo
      ? `<li><a class="dropdown-item text-danger fw-bold" href="#" onclick="cambiarEstadoCliente(${c.id_cliente}, false); return false;"><i class="bi bi-slash-circle me-1"></i>Desactivar</a></li>`
      : `<li><a class="dropdown-item text-success fw-bold" href="#" onclick="cambiarEstadoCliente(${c.id_cliente}, true); return false;"><i class="bi bi-check-circle me-1"></i>Activar</a></li>`;

    return `
      <tr>
        <td class="fw-bold text-dark">${escaparHtml(c.nombre)}</td>
        <td>${escaparHtml(c.correo)}</td>
        <td>${escaparHtml(c.telefono || '-')}</td>
        <td class="text-truncate" style="max-width: 220px;">${escaparHtml(c.direccion_entrega || '-')}</td>
        <td class="text-center"><span class="badge-pastel ${badgeEstado}">${textoEstado}</span></td>
        <td class="text-end">
          <div class="dropdown">
            <button class="btn btn-sm btn-db-outline py-1 px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end small shadow border-0" style="border-radius: var(--db-radius-sm);">
              <li><a class="dropdown-item fw-bold" href="#" onclick="abrirEditarCliente(${c.id_cliente}); return false;"><i class="bi bi-pencil-fill me-1"></i>Editar</a></li>
              <li><hr class="dropdown-divider"></li>
              ${accionEstado}
            </ul>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function filtrarClientes() {
  const query = document.getElementById('cli-buscar').value.toLowerCase().trim();
  const filtrados = todosLosClientes.filter(c =>
    c.nombre.toLowerCase().includes(query) || c.correo.toLowerCase().includes(query)
  );
  renderizarClientes(filtrados);
}

async function abrirEditarCliente(idCliente) {
  const resp = await apiFetch(`api/usuarios/clientes/${idCliente}`);
  if (!resp.exito || !resp.datos) {
    alert(resp.mensaje || 'No se pudo cargar la información del cliente.');
    return;
  }

  const c = resp.datos;
  document.getElementById('cli-id').value = c.id_cliente;
  document.getElementById('cli-nombre').value = c.nombre;
  document.getElementById('cli-correo-modal').value = c.correo;
  document.getElementById('cli-telefono').value = c.telefono || '';
  document.getElementById('cli-direccion').value = c.direccion_entrega || '';

  modalClienteBs.show();
}

async function guardarCliente(evento) {
  evento.preventDefault();

  const id = document.getElementById('cli-id').value;
  const body = {
    nombre: document.getElementById('cli-nombre').value.trim(),
    telefono: document.getElementById('cli-telefono').value.trim(),
    direccion_entrega: document.getElementById('cli-direccion').value.trim(),
  };

  const resp = await apiFetch(`api/usuarios/clientes/${id}`, { method: 'PUT', body });

  if (!resp.exito) {
    alert(resp.mensaje || 'Error al guardar los cambios del cliente.');
    return;
  }

  modalClienteBs.hide();
  showDashboardAlert('Cliente actualizado con éxito.', 'success');
  await cargarClientes();
}

async function cambiarEstadoCliente(idCliente, activar) {
  const confirmMsg = activar
    ? '¿Deseas reactivar la cuenta de este cliente?'
    : '¿Deseas desactivar la cuenta de este cliente? Podrás reactivarla cuando quieras.';

  if (!confirm(confirmMsg)) return;

  const resp = activar
    ? await apiFetch(`api/usuarios/clientes/${idCliente}/activar`, { method: 'POST' })
    : await apiFetch(`api/usuarios/clientes/${idCliente}`, { method: 'DELETE' });

  if (!resp.exito) {
    alert(resp.mensaje || 'No se pudo actualizar el estado del cliente.');
    return;
  }

  showDashboardAlert(`Cliente ${activar ? 'activado' : 'desactivado'} correctamente.`, 'success');
  await cargarClientes();
}
