<?php

/**
 * ==========================================================================
 *  routes.php
 * ==========================================================================
 *  Registro central de TODAS las rutas de la API. index.php crea el
 *  objeto $router y luego incluye este archivo, así que aquí ya existe
 *  la variable $router lista para usarse.
 *
 *  Convención de protección por rol usada en este archivo:
 *    - Sin tercer argumento               -> ruta PÚBLICA (sin sesión).
 *    - [Middleware::class,'autenticado']  -> exige sesión, cualquier rol.
 *    - Middleware::rol([...])             -> exige sesión Y uno de esos roles.
 * ==========================================================================
 */

require_once __DIR__ . '/app/controllers/AuthController.php';
require_once __DIR__ . '/app/controllers/InventarioController.php';
require_once __DIR__ . '/app/controllers/VentaController.php';
require_once __DIR__ . '/app/controllers/CajaController.php';
require_once __DIR__ . '/app/controllers/CatalogoController.php';
require_once __DIR__ . '/app/controllers/CarritoController.php';
require_once __DIR__ . '/app/controllers/PedidoController.php';
require_once __DIR__ . '/app/controllers/PagoController.php';
require_once __DIR__ . '/app/controllers/NotificacionController.php';
require_once __DIR__ . '/app/core/Middleware.php';

/** @var Router $router Viene definido en index.php antes de incluir este archivo. */

// ==========================================================================
//  CU001 / CU010 — Autenticación (Fase 2, rutas PÚBLICAS)
// ==========================================================================

$router->post('/api/auth/login',       [AuthController::class, 'login']);
$router->post('/api/auth/registro',    [AuthController::class, 'registro']);
$router->post('/api/auth/recuperar',   [AuthController::class, 'recuperar']);
$router->post('/api/auth/restablecer', [AuthController::class, 'restablecer']);
$router->post('/api/auth/logout',      [AuthController::class, 'logout']);

$router->get('/api/auth/me', [AuthController::class, 'me'], [
    [Middleware::class, 'autenticado'],
]);

// ==========================================================================
//  CU002 / CU009 / CU019 — Inventario, Materias Primas y Alertas (Fase 3)
//  Administrador y Cajero pueden gestionar productos (según el CU002);
//  la gestión de MATERIAS PRIMAS y ALERTAS queda restringida solo a
//  Administrador (es una operación más delicada de control de insumos).
// ==========================================================================

$rolInventarioProductos = Middleware::rol(['Administrador', 'Cajero']);
$rolSoloAdmin            = Middleware::rol(['Administrador']);

// -- Productos --
$router->get('/api/inventario/productos',                    [InventarioController::class, 'listarProductos'],      [$rolInventarioProductos]);
$router->get('/api/inventario/productos/{id}',                [InventarioController::class, 'verProducto'],          [$rolInventarioProductos]);
$router->post('/api/inventario/productos',                    [InventarioController::class, 'crearProducto'],        [$rolInventarioProductos]);
$router->put('/api/inventario/productos/{id}',                 [InventarioController::class, 'actualizarProducto'],   [$rolInventarioProductos]);
$router->post('/api/inventario/productos/{id}/ajustar-stock',  [InventarioController::class, 'ajustarStockProducto'], [$rolInventarioProductos]);
$router->delete('/api/inventario/productos/{id}',              [InventarioController::class, 'eliminarProducto'],     [$rolSoloAdmin]);

// -- Receta de un producto --
$router->get('/api/inventario/productos/{id}/receta', [InventarioController::class, 'verRecetaDeProducto'], [$rolSoloAdmin]);

// -- Materias primas --
$router->get('/api/inventario/materias-primas',                    [InventarioController::class, 'listarMateriasPrimas'],   [$rolSoloAdmin]);
$router->get('/api/inventario/materias-primas/bajo-stock',          [InventarioController::class, 'listarMateriasBajoStock'], [$rolSoloAdmin]);
$router->post('/api/inventario/materias-primas',                    [InventarioController::class, 'crearMateriaPrima'],      [$rolSoloAdmin]);
$router->put('/api/inventario/materias-primas/{id}',                 [InventarioController::class, 'actualizarMateriaPrima'], [$rolSoloAdmin]);
$router->post('/api/inventario/materias-primas/{id}/ajustar-stock',  [InventarioController::class, 'ajustarStockMateria'],    [$rolSoloAdmin]);
$router->delete('/api/inventario/materias-primas/{id}',              [InventarioController::class, 'eliminarMateriaPrima'],   [$rolSoloAdmin]);

// -- Recetas (líneas individuales producto <-> materia prima) --
$router->post('/api/inventario/recetas',        [InventarioController::class, 'agregarLineaReceta'],  [$rolSoloAdmin]);
$router->delete('/api/inventario/recetas/{id}',  [InventarioController::class, 'eliminarLineaReceta'], [$rolSoloAdmin]);

// -- Alertas de stock --
$router->get('/api/inventario/alertas',               [InventarioController::class, 'listarAlertas'], [$rolSoloAdmin]);
$router->post('/api/inventario/alertas/{id}/atender', [InventarioController::class, 'atenderAlerta'], [$rolSoloAdmin]);

// ==========================================================================
//  CU005 / CU006 / CU007 / CU008 — Punto de Venta presencial (Fase 3)
//  Administrador y Cajero pueden operar el punto de venta.
// ==========================================================================

$rolVentas = Middleware::rol(['Administrador', 'Cajero']);

$router->post('/api/ventas',                [VentaController::class, 'crear'],           [$rolVentas]);
$router->get('/api/ventas',                 [VentaController::class, 'listar'],          [$rolVentas]);
$router->get('/api/ventas/{id}',             [VentaController::class, 'ver'],             [$rolVentas]);
$router->post('/api/ventas/{id}/productos',  [VentaController::class, 'agregarProducto'], [$rolVentas]);
$router->post('/api/ventas/{id}/finalizar',  [VentaController::class, 'finalizar'],       [$rolVentas]);
$router->post('/api/ventas/{id}/anular',     [VentaController::class, 'anular'],          [$rolVentas]);

// ==========================================================================
//  CU018 — Gestión de Cajas por Unidad y Bloqueo de Egresos (Fase 3)
//  Consultar el estado de la caja de HOY: Administrador y Cajero.
//  Registrar egresos/ingresos manuales y ver reportes históricos:
//  solo Administrador (control financiero centralizado).
// ==========================================================================

$router->get('/api/cajas/hoy', [CajaController::class, 'hoy'], [$rolVentas]);

$router->post('/api/cajas/egreso',  [CajaController::class, 'registrarEgreso'],        [$rolSoloAdmin]);
$router->post('/api/cajas/ingreso', [CajaController::class, 'registrarIngresoManual'], [$rolSoloAdmin]);

$router->get('/api/cajas/unidad/{unidad}', [CajaController::class, 'porUnidad'], [$rolSoloAdmin]);
$router->get('/api/cajas/canal/{canal}',   [CajaController::class, 'porCanal'],  [$rolSoloAdmin]);

$router->get('/api/cajas/reportes/diario',  [CajaController::class, 'reporteDiario'],  [$rolSoloAdmin]);
$router->get('/api/cajas/reportes/semanal', [CajaController::class, 'reporteSemanal'], [$rolSoloAdmin]);
$router->get('/api/cajas/reportes/mensual', [CajaController::class, 'reporteMensual'], [$rolSoloAdmin]);
$router->get('/api/cajas/reportes/rango',   [CajaController::class, 'reporteRango'],   [$rolSoloAdmin]);

// ==========================================================================
//  CU011 — Catálogo de Productos (Fase 4, rutas PÚBLICAS, sin sesión)
// ==========================================================================

$router->get('/api/catalogo/productos',      [CatalogoController::class, 'listar']);
$router->get('/api/catalogo/productos/{id}', [CatalogoController::class, 'ver']);

// ==========================================================================
//  CU012 — Carrito de Compras (Fase 4, exclusivo del rol Cliente)
// ==========================================================================

$rolCliente = Middleware::rol(['Cliente']);

$router->get('/api/carrito',                          [CarritoController::class, 'ver'],              [$rolCliente]);
$router->post('/api/carrito/productos',                [CarritoController::class, 'agregarProducto'],  [$rolCliente]);
$router->put('/api/carrito/productos/{idProducto}',     [CarritoController::class, 'modificarCantidad'], [$rolCliente]);
$router->delete('/api/carrito/productos/{idProducto}',  [CarritoController::class, 'eliminarProducto'],  [$rolCliente]);
$router->delete('/api/carrito',                        [CarritoController::class, 'vaciar'],           [$rolCliente]);

// ==========================================================================
//  CU013 / CU015 / CU017 — Pedidos en Línea (Fase 4)
//  Confirmar pedido y ver "mis pedidos": exclusivo del Cliente.
//  Gestionar pedidos (bandeja de trabajo + cambiar estado): Admin/Cajero.
//  Ver el detalle de un pedido puntual: cualquiera autenticado (el
//  propio controlador valida que un Cliente solo vea LOS SUYOS).
// ==========================================================================

$router->post('/api/pedidos', [PedidoController::class, 'confirmar'], [$rolCliente]);
$router->get('/api/pedidos',  [PedidoController::class, 'misPedidos'], [$rolCliente]);

$router->get('/api/pedidos/gestion', [PedidoController::class, 'listarPorEstado'], [$rolVentas]);
$router->put('/api/pedidos/{id}/estado', [PedidoController::class, 'actualizarEstado'], [$rolVentas]);

$router->get('/api/pedidos/{id}', [PedidoController::class, 'ver'], [
    [Middleware::class, 'autenticado'],
]);

// ==========================================================================
//  CU014 — Pago en Línea (Fase 4, pasarela SIMULADA, exclusivo del Cliente)
// ==========================================================================

$router->post('/api/pagos',                 [PagoController::class, 'iniciar'],   [$rolCliente]);
$router->post('/api/pagos/{id}/confirmar',  [PagoController::class, 'confirmar'], [$rolCliente]);

$router->get('/api/pagos/pedido/{idPedido}', [PagoController::class, 'porPedido'], [
    [Middleware::class, 'autenticado'],
]);
$router->get('/api/pagos/{id}/comprobante', [PagoController::class, 'comprobante'], [
    [Middleware::class, 'autenticado'],
]);

// ==========================================================================
//  CU016 — Historial de Notificaciones (Fase 4, exclusivo del Cliente)
// ==========================================================================

$router->get('/api/notificaciones', [NotificacionController::class, 'misNotificaciones'], [$rolCliente]);
