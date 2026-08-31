<?php

/**
 * ==========================================================================
 *  Sesion.php
 * ==========================================================================
 *  Envuelve el manejo nativo de sesiones de PHP ($_SESSION) en una clase
 *  con métodos claros. Así, ningún otro archivo del sistema toca
 *  $_SESSION directamente: todos pasan por aquí. Esto evita errores
 *  como "olvidar hacer session_start()" o "guardar la sesión con una
 *  estructura distinta en cada controlador".
 *
 *  ¿QUÉ GUARDAMOS EN LA SESIÓN?
 *  Un solo arreglo bajo la clave 'usuario', con esta forma:
 *
 *      $_SESSION['usuario'] = [
 *          'id'            => 5,
 *          'tipo'          => 'cliente' | 'empleado',
 *          'rol'           => 'Cliente' | 'Administrador' | 'Cajero',
 *          'nombre'        => 'Angel Jimenez',
 *          'identificador' => 'angel.admin' (usuario) o 'cliente@correo.com' (correo),
 *      ];
 *
 *  Esta misma estructura sirve para los 3 actores del sistema
 *  (Cliente, Administrador, Cajero), lo cual simplifica muchísimo el
 *  Middleware de Roles: siempre se pregunta por 'rol', sin importar
 *  si es un Cliente o un Empleado.
 * ==========================================================================
 */
class Sesion
{
    /**
     * iniciar()
     * Arranca la sesión de PHP si todavía no se ha arrancado. Se llama
     * al principio de CADA petición (desde index.php), y también aquí
     * mismo en cada método, por seguridad, en caso de que algún archivo
     * llegue a usar Sesion sin pasar primero por index.php.
     */
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * guardar($datosUsuario)
     * Se llama justo después de un login exitoso (ver AuthController::login()).
     */
    public static function guardar(array $datosUsuario): void
    {
        self::iniciar();
        $_SESSION['usuario'] = $datosUsuario;
    }

    /**
     * estaAutenticado()
     * true si hay alguien con sesión activa (sin importar el rol).
     */
    public static function estaAutenticado(): bool
    {
        self::iniciar();
        return isset($_SESSION['usuario']);
    }

    /**
     * obtenerUsuario()
     * Devuelve todo el arreglo del usuario en sesión, o null si nadie
     * ha iniciado sesión. Lo usa AuthController::me().
     */
    public static function obtenerUsuario(): ?array
    {
        self::iniciar();
        return $_SESSION['usuario'] ?? null;
    }

    /**
     * obtenerId()
     * Atajo para obtener solo el id del usuario en sesión
     * (id_cliente si es Cliente, id_empleado si es Administrador/Cajero).
     */
    public static function obtenerId(): ?int
    {
        self::iniciar();
        return $_SESSION['usuario']['id'] ?? null;
    }

    /**
     * obtenerRol()
     * Atajo muy usado por el Middleware de Roles: 'Cliente', 'Administrador' o 'Cajero'.
     */
    public static function obtenerRol(): ?string
    {
        self::iniciar();
        return $_SESSION['usuario']['rol'] ?? null;
    }

    /**
     * destruir()
     * Cierra la sesión por completo (logout). Borra tanto los datos en
     * el servidor como la cookie de sesión en el navegador del usuario.
     */
    public static function destruir(): void
    {
        self::iniciar();
        $_SESSION = [];

        // Además de vaciar los datos, se le pide al navegador que
        // elimine la cookie de sesión (buena práctica de seguridad).
        if (ini_get('session.use_cookies')) {
            $parametrosCookie = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $parametrosCookie['path'],
                $parametrosCookie['domain'],
                $parametrosCookie['secure'],
                $parametrosCookie['httponly']
            );
        }

        session_destroy();
    }
}
