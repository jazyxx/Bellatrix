<?php

class Sesion
{
   
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function guardar(array $datosUsuario): void
    {
        self::iniciar();
        $_SESSION['usuario'] = $datosUsuario;
    }

    public static function estaAutenticado(): bool
    {
        self::iniciar();
        return isset($_SESSION['usuario']);
    }

    public static function obtenerUsuario(): ?array
    {
        self::iniciar();
        return $_SESSION['usuario'] ?? null;
    }

    public static function obtenerId(): ?int
    {
        self::iniciar();
        return $_SESSION['usuario']['id'] ?? null;
    }

    public static function obtenerRol(): ?string
    {
        self::iniciar();
        return $_SESSION['usuario']['rol'] ?? null;
    }

    public static function destruir(): void
    {
        self::iniciar();
        $_SESSION = [];
  
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
