<?php
require_once BASE_PATH . '/models/Cliente.php';
require_once BASE_PATH . '/models/Empleado.php';
require_once BASE_PATH . '/models/Notificacion.php';
require_once BASE_PATH . '/app/core/Request.php';
require_once BASE_PATH . '/app/core/Response.php';
require_once BASE_PATH . '/app/core/Sesion.php';

class AuthController
{
    
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

    


    private function notificarRegistro(Cliente $cliente): void
    {
        $notificacion = new Notificacion([
            'id_cliente' => $cliente->idCliente,
            'tipo'       => 'Confirmación de registro',
        ]);
        $notificacion->mensaje = "¡Bienvenido(a) a Ambrosía, {$cliente->nombre}! Tu cuenta fue creada exitosamente.";
        $notificacion->crear();
        $notificacion->enviarCorreoMarca(
            $cliente->correo,
            $cliente->nombre,
            'Te damos la bienvenida a Ambrosía',
            '¡Bienvenido(a)!',
            '#2f855a',
            "Tu cuenta en Ambrosía fue creada exitosamente. Ya puedes iniciar sesión, explorar nuestro catálogo y hacer tus pedidos en línea.",
            null,
            'Te avisaremos por este medio cada vez que cambie el estado de tus pedidos.'
        );
    }

    







    public function logout(): void
    {
        Sesion::destruir();
        Response::exito([], 'Sesión cerrada correctamente.');
    }

    








    public function me(): void
    {
        $usuario = Sesion::obtenerUsuario();

        
        if ($usuario && ($usuario['tipo'] ?? '') === 'cliente') {
            $cliente = Cliente::obtenerPorId((int) $usuario['id']);
            if ($cliente !== null) {
                $usuario['nombre']            = $cliente->nombre;
                $usuario['telefono']          = $cliente->telefono;
                $usuario['direccion_entrega'] = $cliente->direccionEntrega;

                
                Sesion::guardar($usuario);
            }
        }

        Response::exito($usuario, 'Sesión activa encontrada.');
    }
    









    public function recuperar(): void
    {
        $datos = Request::jsonBody();
        $correo = trim($datos['correo'] ?? '');

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Response::error('Debes ingresar un correo electrónico válido.', 400);
            return;
        }

        $cliente = Cliente::obtenerPorCorreo($correo);

        
        
        
        if ($cliente !== null) {
            $token = $cliente->recuperarContrasena();

            

            
            $mensajeApp = "Hemos enviado las instrucciones y el código de recuperación a tu correo electrónico registrado.";

            
            $notificacion = new Notificacion([
                'id_cliente' => $cliente->idCliente,
                'tipo'       => 'Recuperación de contraseña',
            ]);
            $notificacion->mensaje = $mensajeApp;
            $notificacion->crear(); 

            
            $notificacion->enviarCorreoMarca(
                $cliente->correo,
                $cliente->nombre,
                'Código para restablecer tu contraseña - Ambrosía',
                'Recuperación de contraseña',
                '#b7791f',
                'Recibimos una solicitud para restablecer la contraseña de tu cuenta. Usa este código:',
                $token,
                'Este código vence en 1 hora. Si tú no solicitaste esto, ignora este mensaje.'
            );
        }

        Response::exito([], 'Si el correo está registrado en nuestro sistema, recibirás instrucciones para recuperar tu contraseña.');
    }

    









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
} 

