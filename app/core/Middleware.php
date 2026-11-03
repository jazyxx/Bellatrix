<?php
require_once __DIR__ . '/Sesion.php';
require_once __DIR__ . '/Response.php';

class Middleware
{
 
    public static function autenticado(): bool
    {
        if (!Sesion::estaAutenticado()) {
            Response::error('Debes iniciar sesión para acceder a este recurso.', 401);
            return false;
        }
        return true;
    }

    public static function rol(array $rolesPermitidos): callable
    {
        return function () use ($rolesPermitidos): bool {
            
            if (!Sesion::estaAutenticado()) {
                Response::error('Debes iniciar sesión para acceder a este recurso.', 401);
                return false;
            }

            
            $rolDelUsuario = Sesion::obtenerRol();
            if (!in_array($rolDelUsuario, $rolesPermitidos, true)) {
                Response::error('No tienes permisos suficientes para acceder a este recurso.', 403);
                return false;
            }

            return true;
        };
    }
}
