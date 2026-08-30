<?php
require_once __DIR__ . '/Sesion.php';
require_once __DIR__ . '/Response.php';

/**
 * ==========================================================================
 *  Middleware.php
 * ==========================================================================
 *  Un "middleware" es una función que se ejecuta ANTES de que la petición
 *  llegue a su controlador. Funciona como un guardia de seguridad en la
 *  puerta: revisa una condición y, si no se cumple, RECHAZA la petición
 *  ahí mismo (con un error 401 o 403) sin dejar que el controlador
 *  siquiera se entere de que la petición existió.
 *
 *  Este archivo define los DOS guardias que pide la Fase 2:
 *    1) autenticado()  -> "¿Hay alguien con sesión iniciada?"
 *    2) rol($roles)    -> "¿Esa sesión tiene uno de estos roles permitidos?"
 *
 *  Se usan al registrar las rutas en routes.php, así:
 *
 *      // Cualquiera con sesión activa (Cliente, Cajero o Administrador):
 *      $router->get('/api/auth/me', [AuthController::class, 'me'],
 *                   [ [Middleware::class, 'autenticado'] ]);
 *
 *      // Solo Administrador:
 *      $router->get('/api/admin/x', [...],
 *                   [ Middleware::rol(['Administrador']) ]);
 *
 *      // Administrador O Cajero:
 *      $router->get('/api/caja/x', [...],
 *                   [ Middleware::rol(['Administrador', 'Cajero']) ]);
 * ==========================================================================
 */
class Middleware
{
    /**
     * autenticado()
     * ------------------------------------------------------------
     * El middleware más simple: solo exige que exista una sesión
     * activa, sin importar si es Cliente, Cajero o Administrador.
     *
     * @return bool true si puede continuar; false si ya se envió el error 401.
     */
    public static function autenticado(): bool
    {
        if (!Sesion::estaAutenticado()) {
            Response::error('Debes iniciar sesión para acceder a este recurso.', 401);
            return false;
        }
        return true;
    }

    /**
     * rol($rolesPermitidos)
     * ------------------------------------------------------------
     * A diferencia de autenticado(), este middleware SÍ necesita un
     * parámetro (la lista de roles permitidos). Como el Router solo
     * sabe llamar middlewares SIN parámetros (ver Router::despachar()),
     * este método no actúa como el guardia directamente: actúa como
     * una "FÁBRICA" que arma y devuelve una función (closure) con el
     * parámetro $rolesPermitidos ya "guardado en su memoria". Esa
     * función devuelta es la que realmente se ejecuta como middleware.
     *
     * Este patrón se llama "function factory" o "closure factory" y es
     * muy común en PHP moderno para crear middlewares configurables.
     *
     * @param string[] $rolesPermitidos Ej: ['Administrador'] o ['Administrador','Cajero']
     * @return callable La función-guardia lista para usar en routes.php.
     */
    public static function rol(array $rolesPermitidos): callable
    {
        return function () use ($rolesPermitidos): bool {
            // Primero se valida que haya sesión (igual que autenticado()).
            if (!Sesion::estaAutenticado()) {
                Response::error('Debes iniciar sesión para acceder a este recurso.', 401);
                return false;
            }

            // Luego, que el rol de esa sesión esté en la lista permitida.
            $rolDelUsuario = Sesion::obtenerRol();
            if (!in_array($rolDelUsuario, $rolesPermitidos, true)) {
                Response::error('No tienes permisos suficientes para acceder a este recurso.', 403);
                return false;
            }

            return true;
        };
    }
}
