<?php
require_once BASE_PATH . '/models/Cliente.php';
require_once BASE_PATH . '/models/Empleado.php';
require_once BASE_PATH . '/models/Notificacion.php';
require_once BASE_PATH . '/app/core/Request.php';
require_once BASE_PATH . '/app/core/Response.php';
require_once BASE_PATH . '/app/core/Sesion.php';

/**
 * ==========================================================================
 *  AuthController.php
 * ==========================================================================
 *  Controlador de Autenticación. Implementa el CU001 (Acceder al Sistema)
 *  y el CU010 (Registrarse / Recuperar Contraseña).
 *
 *  IMPORTANTE SOBRE EL PATRÓN MVC:
 *  Este controlador NO sabe nada de SQL. Toda la lógica de "cómo se
 *  guarda/consulta un cliente o un empleado" ya vive en los Modelos
 *  (Cliente.php, Empleado.php) construidos en la Fase 1. Este archivo
 *  solo se encarga de:
 *    1. Leer lo que llegó del frontend (Request).
 *    2. Validar que los datos mínimos estén presentes.
 *    3. Pedirle al Modelo correspondiente que haga el trabajo.
 *    4. Traducir el resultado a una respuesta JSON clara (Response).
 *    5. Si el login fue exitoso, guardar los datos en la Sesión.
 * ==========================================================================
 */
class AuthController
{
    /**
     * login()
     * ------------------------------------------------------------
     * POST /api/auth/login
     * Body JSON esperado:
     *   Para clientes:  { "tipo": "cliente",  "correo": "...", "contrasena": "..." }
     *   Para empleados: { "tipo": "empleado", "usuario": "...", "contrasena": "..." }
     *
     * El campo "tipo" existe porque, tal como pide el Diagrama de Clases,
     * Cliente y Empleado son entidades completamente distintas (tablas
     * distintas, con distintos campos de identificación: correo vs. usuario).
     */
    public function login(): void
    {
        $datos = Request::jsonBody();
        $tipo = $datos['tipo'] ?? '';

        try {
            if ($tipo === 'cliente') {
                $this->loginCliente($datos);
            } elseif ($tipo === 'empleado') {
                $this->loginEmpleado($datos);
            } else {
                Response::error("Debes indicar el campo 'tipo' con el valor 'cliente' o 'empleado'.", 400);
            }
        } catch (Exception $e) {
            Response::error('Ocurrió un error al iniciar sesión: ' . $e->getMessage(), 500);
        }
    }
    /**
     * loginCliente() — sub-rutina privada de login() para el caso 'cliente'.
     * Usa Cliente::iniciarSesion(), construido en la Fase 1.
     */
    private function loginCliente(array $datos): void
    {
        $correo = trim($datos['correo'] ?? '');
        $contrasena = $datos['contrasena'] ?? '';

        if ($correo === '' || $contrasena === '') {
            Response::error('El correo y la contraseña son obligatorios.', 400);
            return;
        }

        $cliente = Cliente::iniciarSesion($correo, $contrasena);
        if ($cliente === null) {
            // Mensaje genérico a propósito: no decimos si el error fue el
            // correo o la contraseña, para no darle pistas a un atacante
            // sobre qué correos existen en el sistema.
            Response::error('Correo o contraseña incorrectos.', 401);
            return;
        }

       Sesion::guardar([
            'id'                => $cliente->idCliente,
            'tipo'              => 'cliente',
            'rol'               => 'Cliente',
            'nombre'            => $cliente->nombre,
            'identificador'     => $cliente->correo,
            'telefono'          => $cliente->telefono,
            'direccion_entrega' => $cliente->direccionEntrega,
        ]);

        Response::exito([
            'id_cliente'        => $cliente->idCliente,
            'nombre'            => $cliente->nombre,
            'correo'            => $cliente->correo,
            'telefono'          => $cliente->telefono,
            'direccion_entrega' => $cliente->direccionEntrega,
            'rol'               => 'Cliente',
        ], 'Sesión iniciada correctamente. ¡Bienvenido(a) de nuevo!');
    }

    /**
     * loginEmpleado() — sub-rutina privada de login() para 'empleado'.
     * Usa Empleado::ingresarSistema(), construido en la Fase 1, que ya
     * sabe validar tanto contraseñas hasheadas (bcrypt) como las de
     * prueba en texto plano que trae tu script SQL original.
     */
    private function loginEmpleado(array $datos): void
    {
        $usuario = trim($datos['usuario'] ?? '');
        $contrasena = $datos['contrasena'] ?? '';

        if ($usuario === '' || $contrasena === '') {
            Response::error('El usuario y la contraseña son obligatorios.', 400);
            return;
        }

        $filaEmpleado = Empleado::ingresarSistema($usuario, $contrasena);
        if ($filaEmpleado === null) {
            Response::error('Usuario o contraseña incorrectos.', 401);
            return;
        }

        // El campo 'rol' de la tabla `empleado` ya nos dice si es
        // 'Administrador' o 'Cajero'; con eso basta para el Middleware
        // de Roles, sin necesidad de instanciar Administrador/Cajero aquí.
        Sesion::guardar([
            'id'            => (int) $filaEmpleado['id_empleado'],
            'tipo'          => 'empleado',
            'rol'           => $filaEmpleado['rol'],
            'nombre'        => $filaEmpleado['nombre'],
            'identificador' => $filaEmpleado['usuario'],
        ]);

        Response::exito([
            'id_empleado' => (int) $filaEmpleado['id_empleado'],
            'nombre'      => $filaEmpleado['nombre'],
            'usuario'     => $filaEmpleado['usuario'],
            'rol'         => $filaEmpleado['rol'],
        ], 'Sesión iniciada correctamente. ¡Bienvenido(a) de nuevo!');
    }

    /**
     * registro()
     * ------------------------------------------------------------
     * POST /api/auth/registro
     * Body JSON: { "nombre", "correo", "contrasena", "telefono"?, "direccion_entrega"? }
     *
     * Implementa el CU010. IMPORTANTE: solo los CLIENTES se registran
     * ellos mismos desde la tienda en línea. Los empleados (Cajeros)
     * los crea el Administrador desde su panel, usando
     * Administrador::gestionarEmpleado() (ya construido en la Fase 1) —
     * ese flujo se conectará a una ruta propia en la Fase 3, protegida
     * con Middleware::rol(['Administrador']).
     */
    public function registro(): void
    {
        $datos = Request::jsonBody();

        $errores = $this->validarDatosRegistro($datos);
        if (!empty($errores)) {
            Response::error(implode(' ', $errores), 422);
            return;
        }

        try {
            $cliente = new Cliente([
                'nombre'            => trim($datos['nombre']),
                'correo'            => trim(strtolower($datos['correo'])),
                'contraseña'        => $datos['contrasena'],
                'telefono'          => $datos['telefono'] ?? null,
                'direccion_entrega' => $datos['direccion_entrega'] ?? null,
            ]);

            $seCreoCorrectamente = $cliente->registrarse();
            if (!$seCreoCorrectamente) {
                Response::error('Ya existe una cuenta registrada con ese correo electrónico.', 409);
                return;
            }

            // CU016: se genera (y "envía", de forma simulada hasta la Fase 4)
            // la notificación de bienvenida.
            $this->notificarRegistro($cliente);

            Response::exito(
                ['id_cliente' => $cliente->idCliente],
                'Cuenta creada exitosamente. ¡Bienvenido(a) a Ambrosía!',
                201
            );
        } catch (Exception $e) {
            Response::error('No se pudo completar el registro: ' . $e->getMessage(), 500);
        }
    }

    /**
     * validarDatosRegistro()
     * Valida los campos mínimos obligatorios antes de tocar la base de datos.
     */
    private function validarDatosRegistro(array $datos): array
    {
        $errores = [];

        if (trim($datos['nombre'] ?? '') === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if (!filter_var($datos['correo'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'Debes ingresar un correo electrónico válido.';
        }
        if (strlen($datos['contrasena'] ?? '') < 6) {
            $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
        }

        return $errores;
    }

    /**
     * notificarRegistro() — arma y "envía" (simulado) el mensaje de bienvenida.
     */
    private function notificarRegistro(Cliente $cliente): void
    {
        $notificacion = new Notificacion([
            'id_cliente' => $cliente->idCliente,
            'tipo'       => 'Confirmación de registro',
        ]);
        $notificacion->mensaje = "¡Bienvenido(a) a Ambrosía, {$cliente->nombre}! Tu cuenta fue creada exitosamente.";
        $notificacion->crear();
        $notificacion->enviarCorreo($cliente->correo, $notificacion->mensaje);
    }

    /**
     * logout()
     * ------------------------------------------------------------
     * POST /api/auth/logout
     * Cierra la sesión de quien esté autenticado (Cliente, Cajero o
     * Administrador). No requiere Middleware: si no había nadie con
     * sesión activa, simplemente no hay nada que destruir.
     */
    public function logout(): void
    {
        Sesion::destruir();
        Response::exito([], 'Sesión cerrada correctamente.');
    }

    /**
     * me()
     * ------------------------------------------------------------
     * GET /api/auth/me   (ruta protegida con Middleware::autenticado)
     * Devuelve los datos de quien tiene la sesión activa en este momento.
     * Muy útil para que el frontend (Fase 5/6), al cargar cualquier
     * página, sepa "quién soy y qué rol tengo" sin pedir la contraseña
     * de nuevo.
     */
    public function me(): void
    {
        $usuario = Sesion::obtenerUsuario();

        // Si es un cliente, consulta los datos frescos de la base de datos
        if ($usuario && ($usuario['tipo'] ?? '') === 'cliente') {
            $cliente = Cliente::obtenerPorId((int) $usuario['id']);
            if ($cliente !== null) {
                $usuario['nombre']            = $cliente->nombre;
                $usuario['telefono']          = $cliente->telefono;
                $usuario['direccion_entrega'] = $cliente->direccionEntrega;

                // Actualiza la sesión para futuras consultas
                Sesion::guardar($usuario);
            }
        }

        Response::exito($usuario, 'Sesión activa encontrada.');
    }
    /**
     * recuperar()
     * ------------------------------------------------------------
     * POST /api/auth/recuperar
     * Body JSON: { "correo": "..." }
     *
     * Primera mitad del CU010 (recuperación de contraseña). Genera un
     * token temporal usando Cliente::recuperarContrasena() (Fase 1) y
     * lo "envía" (simulado hasta la Fase 4) al correo del cliente.
     */
    public function recuperar(): void
    {
        $datos = Request::jsonBody();
        $correo = trim($datos['correo'] ?? '');

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Response::error('Debes ingresar un correo electrónico válido.', 400);
            return;
        }

        $cliente = Cliente::obtenerPorCorreo($correo);

        // Por seguridad, NUNCA revelamos si el correo existe o no en el
        // sistema: siempre respondemos el mismo mensaje genérico. Si el
        // cliente sí existe, ahí sí generamos y "enviamos" el token.
        if ($cliente !== null) {
            $token = $cliente->recuperarContrasena();

            $notificacion = new Notificacion([
                'id_cliente' => $cliente->idCliente,
                'tipo'       => 'Recuperación de contraseña',
            ]);
            $notificacion->mensaje = "Usa este código para restablecer tu contraseña: {$token}\n"
                                    . "Este código vence en 1 hora. Si tú no solicitaste esto, ignora este mensaje.";
            $notificacion->crear();
            $notificacion->enviarCorreo($cliente->correo, $notificacion->mensaje);
        }

        Response::exito([], 'Si el correo está registrado en nuestro sistema, recibirás instrucciones para recuperar tu contraseña.');
    }

    /**
     * restablecer()
     * ------------------------------------------------------------
     * POST /api/auth/restablecer
     * Body JSON: { "token": "...", "nueva_contrasena": "..." }
     *
     * Segunda mitad del CU010: usa el token generado por recuperar()
     * para permitir definir una nueva contraseña, vía
     * Cliente::restablecerContrasena() (Fase 1).
     */
    public function restablecer(): void
    {
        $datos = Request::jsonBody();
        $token = trim($datos['token'] ?? '');
        $nuevaContrasena = $datos['nueva_contrasena'] ?? '';

        if ($token === '') {
            Response::error('El código de recuperación es obligatorio.', 400);
            return;
        }
        if (strlen($nuevaContrasena) < 6) {
            Response::error('La nueva contraseña debe tener al menos 6 caracteres.', 422);
            return;
        }

        $seActualizoCorrectamente = Cliente::restablecerContrasena($token, $nuevaContrasena);
        if (!$seActualizoCorrectamente) {
            Response::error('El código de recuperación no es válido o ya expiró. Solicita uno nuevo.', 400);
            return;
        }

        Response::exito([], 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.');
    }

    /**
     * actualizarPerfil()
     * ------------------------------------------------------------
     * POST /api/actualizar-perfil  (rol: Cliente)
     */
    public function actualizarPerfil(): void
    {
        $datos = Request::jsonBody();
        $idCliente = Sesion::obtenerId();

        $nombre = trim($datos['nombre'] ?? '');
        if ($nombre === '') {
            Response::error('El nombre es obligatorio.', 400);
            return;
        }

        $cliente = Cliente::obtenerPorId($idCliente);
        if ($cliente === null) {
            Response::error('No se encontró la información del cliente.', 404);
            return;
        }

        $cliente->nombre = $nombre;
        $cliente->telefono = !empty($datos['telefono']) ? trim($datos['telefono']) : null;
        $cliente->direccionEntrega = !empty($datos['direccion_entrega']) ? trim($datos['direccion_entrega']) : null;

        try {
            if ($cliente->actualizar()) {
                Sesion::guardar([
                    'id'                => $cliente->idCliente,
                    'tipo'              => 'cliente',
                    'rol'               => 'Cliente',
                    'nombre'            => $cliente->nombre,
                    'identificador'     => $cliente->correo,
                    'telefono'          => $cliente->telefono,
                    'direccion_entrega' => $cliente->direccionEntrega,
                ]);

                Response::exito([
                    'nombre'            => $cliente->nombre,
                    'telefono'          => $cliente->telefono,
                    'direccion_entrega' => $cliente->direccionEntrega,
                ], 'Perfil actualizado exitosamente.');
            } else {
                Response::error('No se pudieron guardar los cambios en el perfil.', 500);
            }
        } catch (Exception $e) {
            Response::error('Error al actualizar el perfil: ' . $e->getMessage(), 500);
        }
    }
} // <- Asegúrate de que esta sea la última llave de la clase

