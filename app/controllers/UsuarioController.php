<?php
require_once BASE_PATH . '/models/Cliente.php';
require_once BASE_PATH . '/models/Empleado.php';
require_once BASE_PATH . '/models/Administrador.php';
require_once BASE_PATH . '/models/Cajero.php';
require_once BASE_PATH . '/app/core/Request.php';
require_once BASE_PATH . '/app/core/Response.php';
require_once BASE_PATH . '/app/core/Sesion.php';

/**
 * ==========================================================================
 *  UsuarioController.php
 * ==========================================================================
 *  Implementa el CU003 (Gestión de Usuarios) desde el panel de
 *  Administrador: listar, ver, crear, actualizar, restablecer contraseña
 *  y activar/desactivar (borrado lógico) tanto de Clientes como de
 *  Empleados (Administrador/Cajero).
 *
 *  Este controlador NO reinventa lógica de negocio: reutiliza los
 *  modelos Cliente, Empleado, Administrador y Cajero construidos en la
 *  Fase 1 (registrarse/crear, actualizar, desactivar/activar) y solo se
 *  encarga de leer el Request, validar los datos mínimos y traducir el
 *  resultado a una Response — el mismo rol que cumplen el resto de
 *  controladores del sistema (ver InventarioController, por ejemplo).
 *
 *  Todas las rutas de este controlador están protegidas en routes.php
 *  con Middleware::rol(['Administrador']): la gestión de cuentas de
 *  acceso al sistema es información sensible, exclusiva del rol con
 *  mayor nivel de confianza.
 * ==========================================================================
 */
class UsuarioController
{
    // =================================================================
    //  CLIENTES
    // =================================================================

    /**
     * listarClientes()
     * GET /api/usuarios/clientes
     */
    public function listarClientes(): void
    {
        $clientes = Cliente::listarTodos();
        $datos = array_map(fn(Cliente $c) => $c->obtenerDatos(), $clientes);
        Response::exito($datos, 'Clientes obtenidos correctamente.');
    }

    /**
     * verCliente($id)
     * GET /api/usuarios/clientes/{id}
     */
    public function verCliente(string $id): void
    {
        $cliente = Cliente::obtenerPorId((int) $id);
        if ($cliente === null) {
            Response::error('El cliente solicitado no existe.', 404);
            return;
        }
        Response::exito($cliente->obtenerDatos());
    }

    /**
     * actualizarCliente($id)
     * PUT /api/usuarios/clientes/{id}
     * Body: { nombre?, telefono?, direccion_entrega? }
     *
     * El correo NUNCA se edita desde aquí, tampoco por el Administrador:
     * es el identificador de acceso del cliente al sistema (misma regla
     * que aplica en AuthController::actualizarPerfil(), la autogestión
     * del propio cliente).
     */
    public function actualizarCliente(string $id): void
    {
        $cliente = Cliente::obtenerPorId((int) $id);
        if ($cliente === null) {
            Response::error('El cliente solicitado no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        $nombre = trim($datos['nombre'] ?? $cliente->nombre);
        if ($nombre === '') {
            Response::error('El nombre no puede estar vacío.', 422);
            return;
        }

        $cliente->nombre = $nombre;
        if (array_key_exists('telefono', $datos)) {
            $cliente->telefono = trim((string) $datos['telefono']) !== '' ? trim((string) $datos['telefono']) : null;
        }
        if (array_key_exists('direccion_entrega', $datos)) {
            $cliente->direccionEntrega = trim((string) $datos['direccion_entrega']) !== '' ? trim((string) $datos['direccion_entrega']) : null;
        }

        if (!$cliente->actualizar()) {
            Response::error('No se pudieron guardar los cambios del cliente.', 500);
            return;
        }

        Response::exito($cliente->obtenerDatos(), 'Cliente actualizado correctamente.');
    }

    /**
     * activarCliente($id)
     * POST /api/usuarios/clientes/{id}/activar
     */
    public function activarCliente(string $id): void
    {
        $cliente = Cliente::obtenerPorId((int) $id);
        if ($cliente === null) {
            Response::error('El cliente solicitado no existe.', 404);
            return;
        }
        $cliente->activar();
        Response::exito($cliente->obtenerDatos(), 'Cliente activado correctamente.');
    }

    /**
     * desactivarCliente($id)
     * DELETE /api/usuarios/clientes/{id}
     * Borrado LÓGICO (activo = 0), igual que en el resto del sistema:
     * eliminar físicamente al cliente rompería sus pedidos/pagos
     * históricos relacionados por llave foránea.
     */
    public function desactivarCliente(string $id): void
    {
        $cliente = Cliente::obtenerPorId((int) $id);
        if ($cliente === null) {
            Response::error('El cliente solicitado no existe.', 404);
            return;
        }
        $cliente->desactivar();
        Response::exito([], 'Cliente desactivado correctamente.');
    }

    // =================================================================
    //  EMPLEADOS (Administrador / Cajero)
    // =================================================================

    /**
     * listarEmpleados()
     * GET /api/usuarios/empleados
     */
    public function listarEmpleados(): void
    {
        $empleados = Empleado::listarTodos();
        $datos = array_map(fn(Empleado $e) => $e->obtenerDatos(), $empleados);
        Response::exito($datos, 'Empleados obtenidos correctamente.');
    }

    /**
     * verEmpleado($id)
     * GET /api/usuarios/empleados/{id}
     * Se recarga como Administrador o Cajero (según su rol) para incluir
     * en la respuesta el campo extra de su subtipo (nivel_acceso / turno).
     */
    public function verEmpleado(string $id): void
    {
        $empleado = Empleado::obtenerPorId((int) $id);
        if ($empleado === null) {
            Response::error('El empleado solicitado no existe.', 404);
            return;
        }

        $completo = $empleado->rol === 'Administrador'
            ? Administrador::obtenerPorId($empleado->idEmpleado)
            : Cajero::obtenerPorId($empleado->idEmpleado);

        Response::exito(($completo ?? $empleado)->obtenerDatos());
    }

    /**
     * crearEmpleado()
     * POST /api/usuarios/empleados
     * Body: { nombre, apellido?, usuario, correo, contrasena, rol,
     *         telefono?, salario?, fecha_contratacion?,
     *         nivel_acceso? (si rol = 'Administrador'),
     *         turno? (si rol = 'Cajero') }
     *
     * A diferencia del Cliente (que se registra solo desde la tienda),
     * los empleados los da de alta el Administrador desde este panel —
     * tal como estaba previsto desde la Fase 1 en el diagrama de clases,
     * pero sin ruta expuesta todavía (ver CONTEXTO_PROYECTO_BELLATRIX.md,
     * sección "Pendientes explícitos").
     */
    public function crearEmpleado(): void
    {
        $datos = Request::jsonBody();
        $errores = $this->validarDatosEmpleado($datos, true);
        if (!empty($errores)) {
            Response::error(implode(' ', $errores), 422);
            return;
        }

        try {
            $empleado = $datos['rol'] === 'Administrador'
                ? new Administrador($datos)
                : new Cajero($datos);

            $empleado->contrasenaSinHashear = $datos['contrasena'];
            $empleado->crear();

            Response::exito($empleado->obtenerDatos(), 'Empleado creado exitosamente.', 201);
        } catch (PDOException $e) {
            // Código 23000 = violación de restricción única (usuario o correo duplicado).
            if ($e->getCode() === '23000') {
                Response::error('Ya existe un empleado con ese usuario o correo electrónico.', 409);
                return;
            }
            Response::error('No se pudo crear el empleado: ' . $e->getMessage(), 500);
        } catch (Exception $e) {
            Response::error('No se pudo crear el empleado: ' . $e->getMessage(), 500);
        }
    }

    /**
     * actualizarEmpleado($id)
     * PUT /api/usuarios/empleados/{id}
     * Body: { nombre?, apellido?, usuario?, correo?, telefono?, salario? }
     *
     * El rol NO se puede cambiar desde aquí: un Cajero y un Administrador
     * viven en tablas distintas relacionadas 1 a 1 con `empleado`, así que
     * "cambiar de rol" implicaría mover la fila entre tablas. Si se
     * necesita, la forma correcta es desactivar este empleado y crear uno
     * nuevo con el rol correcto (mismo criterio que ya usa el sistema
     * para clientes/empleados con el borrado lógico).
     */
    public function actualizarEmpleado(string $id): void
    {
        $empleado = Empleado::obtenerPorId((int) $id);
        if ($empleado === null) {
            Response::error('El empleado solicitado no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        $nombre = trim($datos['nombre'] ?? $empleado->nombre);
        $usuario = trim($datos['usuario'] ?? $empleado->usuario);
        $correo = trim($datos['correo'] ?? $empleado->correo);

        if ($nombre === '' || $usuario === '') {
            Response::error('El nombre y el usuario son obligatorios.', 422);
            return;
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Response::error('Debes ingresar un correo electrónico válido.', 422);
            return;
        }

        $empleado->nombre = $nombre;
        $empleado->usuario = $usuario;
        $empleado->correo = $correo;
        if (array_key_exists('apellido', $datos)) {
            $empleado->apellido = trim((string) $datos['apellido']) !== '' ? trim((string) $datos['apellido']) : null;
        }
        if (array_key_exists('telefono', $datos)) {
            $empleado->telefono = trim((string) $datos['telefono']) !== '' ? trim((string) $datos['telefono']) : null;
        }
        if (array_key_exists('salario', $datos) && $datos['salario'] !== '' && $datos['salario'] !== null) {
            $empleado->salario = (float) $datos['salario'];
        }

        try {
            if (!$empleado->actualizar()) {
                Response::error('No se pudieron guardar los cambios del empleado.', 500);
                return;
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Ya existe otro empleado con ese usuario o correo electrónico.', 409);
                return;
            }
            Response::error('No se pudo actualizar el empleado: ' . $e->getMessage(), 500);
            return;
        }

        Response::exito($empleado->obtenerDatos(), 'Empleado actualizado correctamente.');
    }

    /**
     * cambiarContrasenaEmpleado($id)
     * PUT /api/usuarios/empleados/{id}/contrasena
     * Body: { nueva_contrasena }
     *
     * Los empleados no tienen flujo de "olvidé mi contraseña" (ese es
     * exclusivo del Cliente, ver AuthController::recuperar()/restablecer()):
     * es el Administrador quien restablece su contraseña manualmente
     * desde aquí cuando lo necesiten.
     */
    public function cambiarContrasenaEmpleado(string $id): void
    {
        $empleado = Empleado::obtenerPorId((int) $id);
        if ($empleado === null) {
            Response::error('El empleado solicitado no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        $nueva = $datos['nueva_contrasena'] ?? '';
        if (strlen($nueva) < 6) {
            Response::error('La nueva contraseña debe tener al menos 6 caracteres.', 422);
            return;
        }

        if (!$empleado->cambiarContrasena($nueva)) {
            Response::error('No se pudo actualizar la contraseña.', 500);
            return;
        }

        Response::exito([], 'Contraseña del empleado actualizada correctamente.');
    }

    /**
     * activarEmpleado($id)
     * POST /api/usuarios/empleados/{id}/activar
     */
    public function activarEmpleado(string $id): void
    {
        $empleado = Empleado::obtenerPorId((int) $id);
        if ($empleado === null) {
            Response::error('El empleado solicitado no existe.', 404);
            return;
        }
        $empleado->activar();
        Response::exito($empleado->obtenerDatos(), 'Empleado activado correctamente.');
    }

    /**
     * desactivarEmpleado($id)
     * DELETE /api/usuarios/empleados/{id}
     * Borrado LÓGICO (activo = 0), igual que en el resto del sistema:
     * eliminar físicamente al empleado rompería el historial de
     * ventas/pedidos que gestionó.
     */
    public function desactivarEmpleado(string $id): void
    {
        $empleado = Empleado::obtenerPorId((int) $id);
        if ($empleado === null) {
            Response::error('El empleado solicitado no existe.', 404);
            return;
        }

        // Un Administrador no puede desactivar su propia cuenta desde
        // aquí (evita que se quede sin acceso al panel accidentalmente).
        if ((int) $empleado->idEmpleado === (int) Sesion::obtenerId()) {
            Response::error('No puedes desactivar tu propia cuenta de administrador.', 422);
            return;
        }

        $empleado->desactivar();
        Response::exito([], 'Empleado desactivado correctamente.');
    }

    // =================================================================
    //  VALIDACIONES
    // =================================================================

    private function validarDatosEmpleado(array $datos, bool $esCreacion): array
    {
        $errores = [];

        if (trim($datos['nombre'] ?? '') === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if (trim($datos['usuario'] ?? '') === '') {
            $errores[] = 'El usuario es obligatorio.';
        }
        if (!filter_var($datos['correo'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'Debes ingresar un correo electrónico válido.';
        }
        if (!in_array($datos['rol'] ?? '', ['Administrador', 'Cajero'], true)) {
            $errores[] = "El rol debe ser 'Administrador' o 'Cajero'.";
        }
        if ($esCreacion && strlen($datos['contrasena'] ?? '') < 6) {
            $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
        }

        return $errores;
    }
}
