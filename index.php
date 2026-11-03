<?php

define('BASE_PATH', __DIR__);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once BASE_PATH . '/app/core/Router.php';
require_once BASE_PATH . '/app/core/Request.php';
require_once BASE_PATH . '/app/core/Response.php';
require_once BASE_PATH . '/app/core/Sesion.php';

Sesion::iniciar();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();

require BASE_PATH . '/routes.php'; 

try {
    $router->despachar();
} catch (Throwable $errorInesperado) {
    Response::error('Error interno del servidor: ' . $errorInesperado->getMessage(), 500);
}
