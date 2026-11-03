<?php
require_once BASE_PATH . '/models/Cliente.php';
require_once BASE_PATH . '/models/Empleado.php';
require_once BASE_PATH . '/models/Administrador.php';
require_once BASE_PATH . '/models/Cajero.php';
require_once BASE_PATH . '/app/core/Request.php';
require_once BASE_PATH . '/app/core/Response.php';
require_once BASE_PATH . '/app/core/Sesion.php';

class UsuarioController
{
 
    public function listarClientes(): void
    {
        $clientes = Cliente::listarTodos();
        $datos = array_map(fn(Cliente $c) => $c->obtenerDatos(), $clientes);
        Response::exito($datos, 'Clientes obtenidos correctamente.');
    }

    public function verCliente(string $id): void
    {
        $cliente = Cliente::obtenerPorId((int) $id);
        if ($cliente === null) {
            Response::error('El cliente solicitado no existe.', 404);
            return;
        }
        Response::exito($cliente->obtenerDatos());
    }

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

    public function listarEmpleados(): void
    {
        $empleados = Empleado::listarTodos();
        $datos = array_map(fn(Empleado $e) => $e->obtenerDatos(), $empleados);
        Response::exito($datos, 'Empleados obtenidos correctamente.');
    }

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
            
            if ($e->getCode() === '23000') {
                Response::error('Ya existe un empleado con ese usuario o correo electrónico.', 409);
                return;
            }
            Response::error('No se pudo crear el empleado: ' . $e->getMessage(), 500);
        } catch (Exception $e) {
            Response::error('No se pudo crear el empleado: ' . $e->getMessage(), 500);
        }
    }

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

    public function desactivarEmpleado(string $id): void
    {
        $empleado = Empleado::obtenerPorId((int) $id);
        if ($empleado === null) {
            Response::error('El empleado solicitado no existe.', 404);
            return;
        }
     
        if ((int) $empleado->idEmpleado === (int) Sesion::obtenerId()) {
            Response::error('No puedes desactivar tu propia cuenta de administrador.', 422);
            return;
        }

        $empleado->desactivar();
        Response::exito([], 'Empleado desactivado correctamente.');
    }

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
