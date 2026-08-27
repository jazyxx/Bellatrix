<?php

/**
 * ==========================================================================
 *  index.php — FRONT CONTROLLER
 * ==========================================================================
 *  Este es el ÚNICO punto de entrada de TODO el backend de Bellatrix.
 *  Sin importar si el frontend pide /api/auth/login, /api/productos, o
 *  cualquier otra ruta futura, TODAS las peticiones HTTP llegan primero
 *  aquí (gracias al archivo .htaccess de la carpeta), y este archivo
 *  decide qué hacer con cada una, apoyándose en el Router.
 *
 *  ¿QUÉ HACE, PASO A PASO?
 *   1. Configura el manejo de errores (visible en desarrollo).
 *   2. Carga las piezas del núcleo (Router, Request, Response, Sesión).
 *   3. Arranca la sesión de PHP.
 *   4. Configura las cabeceras necesarias para que el frontend (con
 *      JavaScript / fetch) pueda comunicarse sin problemas (CORS).
 *   5. Crea el Router, registra las rutas (routes.php) y le pide que
 *      despache (resuelva) la petición actual.
 * ==========================================================================
 */

// --------------------------------------------------------------------
// 0. BASE_PATH — la constante más importante del proyecto.
//    Se define UNA sola vez, aquí, en el Front Controller. A partir de
//    este punto, TODO el sistema (modelos, controladores, núcleo)
//    arma sus rutas de "require" a partir de BASE_PATH en vez de
//    encadenar '../../' relativos. Esto elimina por completo los
//    errores de "Failed to open stream" causados por rutas relativas
//    mal calculadas o por archivos movidos de carpeta.
//
//    __DIR__ aquí es la carpeta donde vive index.php, es decir, la
//    RAÍZ del proyecto (ej: C:\xampp\htdocs\bellatrix). Como index.php
//    es el ÚNICO punto de entrada (Front Controller), BASE_PATH queda
//    definida ANTES de que cualquier otro archivo del sistema se cargue.
// --------------------------------------------------------------------
define('BASE_PATH', __DIR__);

// --------------------------------------------------------------------
// 1. Manejo de errores. En PRODUCCIÓN, cambia display_errors a 0 para
//    no mostrarle detalles técnicos del servidor a los usuarios finales.
// --------------------------------------------------------------------
ini_set('display_errors', '1');
error_reporting(E_ALL);

// --------------------------------------------------------------------
// 2. Carga de las piezas del núcleo (todas construidas en la Fase 2).
// --------------------------------------------------------------------
require_once BASE_PATH . '/app/core/Router.php';
require_once BASE_PATH . '/app/core/Request.php';
require_once BASE_PATH . '/app/core/Response.php';
require_once BASE_PATH . '/app/core/Sesion.php';

// --------------------------------------------------------------------
// 3. Arranca la sesión de PHP (necesaria para saber quién está logueado
//    en cada petición, ej. al revisar el Middleware de Roles).
// --------------------------------------------------------------------
Sesion::iniciar();

// --------------------------------------------------------------------
// 4. Cabeceras CORS para desarrollo local.
//    Esto permite que el frontend (que en Fase 5/6 podría correr en
//    otro puerto, ej. Live Server en el puerto 5500) pueda hacer
//    peticiones fetch() a este backend sin que el navegador las bloquee.
//    IMPORTANTE: en producción, reemplaza el '*' por el dominio real
//    de tu sitio (ej. 'https://ambrosia.com') por seguridad.
// --------------------------------------------------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

// Los navegadores, antes de una petición "real" (ej. un POST con JSON),
// a veces mandan una petición de "sondeo" OPTIONS. Aquí simplemente
// se responde OK sin hacer nada más, para no romper esas peticiones.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --------------------------------------------------------------------
// 5. Se crea el Router, se registran las rutas, y se despacha la
//    petición actual. Todo el proceso queda envuelto en un try/catch
//    para que, si CUALQUIER cosa inesperada falla en el sistema, el
//    usuario reciba un JSON de error claro en vez de una pantalla
//    blanca o un error crudo de PHP.
// --------------------------------------------------------------------
$router = new Router();

require BASE_PATH . '/routes.php'; // Aquí se registran todas las rutas ($router ya existe).

try {
    $router->despachar();
} catch (Throwable $errorInesperado) {
    Response::error('Error interno del servidor: ' . $errorInesperado->getMessage(), 500);
}
