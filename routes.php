<?php

require_once __DIR__ . '/app/controllers/AuthController.php';
require_once __DIR__ . '/app/controllers/InventarioController.php';
require_once __DIR__ . '/app/controllers/VentaController.php';
require_once __DIR__ . '/app/controllers/CajaController.php';
require_once __DIR__ . '/app/controllers/CatalogoController.php';
require_once __DIR__ . '/app/controllers/CarritoController.php';
require_once __DIR__ . '/app/controllers/PedidoController.php';
require_once __DIR__ . '/app/controllers/PagoController.php';
require_once __DIR__ . '/app/controllers/NotificacionController.php';
require_once __DIR__ . '/app/controllers/AsistenteIAController.php';
require_once __DIR__ . '/app/core/Middleware.php';

$router->post('/api/auth/login',       [AuthController::class, 'login']);
$router->post('/api/auth/registro',    [AuthController::class, 'registro']);
$router->post('/api/auth/recuperar',   [AuthController::class, 'recuperar']);
$router->post('/api/auth/restablecer', [AuthController::class, 'restablecer']);
$router->post('/api/auth/logout',      [AuthController::class, 'logout']);

$router->get('/api/auth/me', [AuthController::class, 'me'], [
    [Middleware::class, 'autenticado'],
]);

$rolInventarioProductos = Middleware::rol(['Administrador', 'Cajero']);
$rolSoloAdmin            = Middleware::rol(['Administrador']);


$router->get('/api/inventario/productos',                    [InventarioController::class, 'listarProductos'],      [$rolInventarioProductos]);
$router->get('/api/inventario/productos/{id}',                [InventarioController::class, 'verProducto'],          [$rolInventarioProductos]);

$router->get('/api/inventario/productos/{id}/foto',           [InventarioController::class, 'fotoProducto']);
$router->post('/api/inventario/productos',                    [InventarioController::class, 'crearProducto'],        [$rolInventarioProductos]);
$router->put('/api/inventario/productos/{id}',                 [InventarioController::class, 'actualizarProducto'],   [$rolInventarioProductos]);
$router->post('/api/inventario/productos/{id}/ajustar-stock',  [InventarioController::class, 'ajustarStockProducto'], [$rolInventarioProductos]);
$router->delete('/api/inventario/productos/{id}',              [InventarioController::class, 'eliminarProducto'],     [$rolSoloAdmin]);


$router->get('/api/inventario/productos/{id}/receta', [InventarioController::class, 'verRecetaDeProducto'], [$rolSoloAdmin]);


$router->get('/api/inventario/materias-primas',                    [InventarioController::class, 'listarMateriasPrimas'],   [$rolSoloAdmin]);
$router->get('/api/inventario/materias-primas/bajo-stock',          [InventarioController::class, 'listarMateriasBajoStock'], [$rolSoloAdmin]);
$router->post('/api/inventario/materias-primas',                    [InventarioController::class, 'crearMateriaPrima'],      [$rolSoloAdmin]);
$router->put('/api/inventario/materias-primas/{id}',                 [InventarioController::class, 'actualizarMateriaPrima'], [$rolSoloAdmin]);
$router->post('/api/inventario/materias-primas/{id}/ajustar-stock',  [InventarioController::class, 'ajustarStockMateria'],    [$rolSoloAdmin]);
$router->delete('/api/inventario/materias-primas/{id}',              [InventarioController::class, 'eliminarMateriaPrima'],   [$rolSoloAdmin]);


$router->post('/api/inventario/recetas',        [InventarioController::class, 'agregarLineaReceta'],  [$rolSoloAdmin]);
$router->delete('/api/inventario/recetas/{id}',  [InventarioController::class, 'eliminarLineaReceta'], [$rolSoloAdmin]);


$router->get('/api/inventario/alertas',               [InventarioController::class, 'listarAlertas'], [$rolSoloAdmin]);
$router->post('/api/inventario/alertas/{id}/atender', [InventarioController::class, 'atenderAlerta'], [$rolSoloAdmin]);

$rolVentas = Middleware::rol(['Administrador', 'Cajero']);

$router->post('/api/ventas',                [VentaController::class, 'crear'],           [$rolVentas]);
$router->get('/api/ventas',                 [VentaController::class, 'listar'],          [$rolVentas]);
$router->get('/api/ventas/{id}',             [VentaController::class, 'ver'],             [$rolVentas]);
$router->post('/api/ventas/{id}/productos',  [VentaController::class, 'agregarProducto'], [$rolVentas]);
$router->post('/api/ventas/{id}/finalizar',  [VentaController::class, 'finalizar'],       [$rolVentas]);
$router->post('/api/ventas/{id}/anular',     [VentaController::class, 'anular'],          [$rolVentas]);

$router->get('/api/cajas/hoy', [CajaController::class, 'hoy'], [$rolVentas]);

$router->post('/api/cajas/egreso',  [CajaController::class, 'registrarEgreso'],        [$rolSoloAdmin]);
$router->post('/api/cajas/ingreso', [CajaController::class, 'registrarIngresoManual'], [$rolSoloAdmin]);

$router->get('/api/cajas/unidad/{unidad}', [CajaController::class, 'porUnidad'], [$rolSoloAdmin]);
$router->get('/api/cajas/canal/{canal}',   [CajaController::class, 'porCanal'],  [$rolSoloAdmin]);

$router->get('/api/cajas/reportes/diario',  [CajaController::class, 'reporteDiario'],  [$rolSoloAdmin]);
$router->get('/api/cajas/reportes/semanal', [CajaController::class, 'reporteSemanal'], [$rolSoloAdmin]);
$router->get('/api/cajas/reportes/mensual', [CajaController::class, 'reporteMensual'], [$rolSoloAdmin]);
$router->get('/api/cajas/reportes/rango',   [CajaController::class, 'reporteRango'],   [$rolSoloAdmin]);

$router->get('/api/asistente-ia/historial',    [AsistenteIAController::class, 'historial'],       [$rolSoloAdmin]);
$router->post('/api/asistente-ia/mensaje',     [AsistenteIAController::class, 'mensaje'],         [$rolSoloAdmin]);
$router->delete('/api/asistente-ia/historial', [AsistenteIAController::class, 'limpiarHistorial'],[$rolSoloAdmin]);

$router->get('/api/catalogo/productos',      [CatalogoController::class, 'listar']);
$router->get('/api/catalogo/productos/{id}', [CatalogoController::class, 'ver']);

$rolCliente = Middleware::rol(['Cliente']);

$router->get('/api/carrito',                          [CarritoController::class, 'ver'],              [$rolCliente]);
$router->post('/api/carrito/productos',                [CarritoController::class, 'agregarProducto'],  [$rolCliente]);
$router->put('/api/carrito/productos/{idProducto}',     [CarritoController::class, 'modificarCantidad'], [$rolCliente]);
$router->delete('/api/carrito/productos/{idProducto}',  [CarritoController::class, 'eliminarProducto'],  [$rolCliente]);
$router->delete('/api/carrito',                        [CarritoController::class, 'vaciar'],           [$rolCliente]);

$router->post('/api/pedidos', [PedidoController::class, 'confirmar'], [$rolCliente]);
$router->get('/api/pedidos',  [PedidoController::class, 'misPedidos'], [$rolCliente]);
$router->put('/api/pedidos/{id}/cancelar', [PedidoController::class, 'cancelarPorCliente'], [$rolCliente]);

$router->get('/api/pedidos/gestion', [PedidoController::class, 'listarPorEstado'], [$rolVentas]);
$router->put('/api/pedidos/{id}/estado', [PedidoController::class, 'actualizarEstado'], [$rolVentas]);

$router->get('/api/pedidos/{id}', [PedidoController::class, 'ver'], [
    [Middleware::class, 'autenticado'],
]);

$router->post('/api/pagos',                 [PagoController::class, 'iniciar'],   [$rolCliente]);
$router->post('/api/pagos/{id}/confirmar',  [PagoController::class, 'confirmar'], [$rolCliente]);
$router->post('/api/pagos/webhook-nequi',   [PagoController::class, 'webhookNequi']);

$router->get('/api/pagos/pedido/{idPedido}', [PagoController::class, 'porPedido'], [
    [Middleware::class, 'autenticado'],
]);
$router->get('/api/pagos/{id}/comprobante', [PagoController::class, 'comprobante'], [
    [Middleware::class, 'autenticado'],
]);

$router->get('/api/notificaciones', [NotificacionController::class, 'misNotificaciones'], [$rolCliente]);

$router->post('/api/actualizar-perfil', [AuthController::class, 'actualizarPerfil'], [$rolCliente]);

require_once __DIR__ . '/app/controllers/UsuarioController.php';

$router->get('/api/usuarios/clientes',               [UsuarioController::class, 'listarClientes'],    [$rolSoloAdmin]);
$router->get('/api/usuarios/clientes/{id}',           [UsuarioController::class, 'verCliente'],        [$rolSoloAdmin]);
$router->put('/api/usuarios/clientes/{id}',            [UsuarioController::class, 'actualizarCliente'], [$rolSoloAdmin]);
$router->post('/api/usuarios/clientes/{id}/activar',   [UsuarioController::class, 'activarCliente'],    [$rolSoloAdmin]);
$router->delete('/api/usuarios/clientes/{id}',         [UsuarioController::class, 'desactivarCliente'], [$rolSoloAdmin]);

$router->get('/api/usuarios/empleados',                 [UsuarioController::class, 'listarEmpleados'],          [$rolSoloAdmin]);
$router->get('/api/usuarios/empleados/{id}',             [UsuarioController::class, 'verEmpleado'],              [$rolSoloAdmin]);
$router->post('/api/usuarios/empleados',                 [UsuarioController::class, 'crearEmpleado'],            [$rolSoloAdmin]);
$router->put('/api/usuarios/empleados/{id}',              [UsuarioController::class, 'actualizarEmpleado'],       [$rolSoloAdmin]);
$router->put('/api/usuarios/empleados/{id}/contrasena',   [UsuarioController::class, 'cambiarContrasenaEmpleado'],[$rolSoloAdmin]);
$router->post('/api/usuarios/empleados/{id}/activar',     [UsuarioController::class, 'activarEmpleado'],          [$rolSoloAdmin]);
$router->delete('/api/usuarios/empleados/{id}',            [UsuarioController::class, 'desactivarEmpleado'],       [$rolSoloAdmin]);
