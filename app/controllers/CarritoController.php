<?php
require_once __DIR__ . '/../../models/CarritoDeCompras.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

/**
 * ==========================================================================
 *  CarritoController.php
 * ==========================================================================
 *  Implementa el CU012: "Gestionar Carrito de Compras". Todas las rutas
 *  de este controlador requieren sesión de Cliente (ver Middleware::
 *  rol(['Cliente']) en routes.php) — el carrito es personal, así que
 *  SIEMPRE se trabaja sobre el carrito del cliente que tiene la sesión
 *  activa (Sesion::obtenerId()), nunca sobre un id que venga del body.
 *
 *  La VALIDACIÓN DE STOCK DINÁMICO que pide el CU012 ya está resuelta
 *  en la Fase 1, dentro de CarritoDeCompras::agregarProducto() y
 *  modificarCantidad(): ambos métodos revisan el stock real del
 *  producto antes de permitir el cambio y lanzan una excepción si no
 *  alcanza. Este controlador solo traduce esa excepción a un HTTP 422.
 * ==========================================================================
 */
class CarritoController
{
    /**
     * ver()
     * GET /api/carrito
     * Devuelve (o crea vacío) el carrito del cliente en sesión.
     */
    public function ver(): void
    {
        $carrito = CarritoDeCompras::obtenerOCrearParaCliente(Sesion::obtenerId());
        Response::exito($this->serializarCarrito($carrito));
    }

    /**
     * agregarProducto()
     * POST /api/carrito/productos
     * Body: { "id_producto": 3, "cantidad": 2 }
     */
    public function agregarProducto(): void
    {
        $datos = Request::jsonBody();
        $idProducto = $datos['id_producto'] ?? null;
        $cantidad = $datos['cantidad'] ?? null;

        if (!is_numeric($idProducto) || !is_numeric($cantidad) || (int) $cantidad <= 0) {
            Response::error("Debes indicar 'id_producto' y una 'cantidad' mayor a 0.", 400);
            return;
        }

        $carrito = CarritoDeCompras::obtenerOCrearParaCliente(Sesion::obtenerId());

        try {
            $carrito->agregarProducto((int) $idProducto, (int) $cantidad);
            Response::exito($this->serializarCarrito($carrito), 'Producto agregado al carrito.');
        } catch (Exception $e) {
            // Ej: "Stock insuficiente" o "producto no disponible".
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * modificarCantidad($idProducto)
     * PUT /api/carrito/productos/{idProducto}
     * Body: { "cantidad": 5 }
     * Si "cantidad" llega en 0 o menos, CarritoDeCompras::modificarCantidad()
     * (Fase 1) automáticamente elimina el producto del carrito.
     */
    public function modificarCantidad(string $idProducto): void
    {
        $datos = Request::jsonBody();
        if (!isset($datos['cantidad']) || !is_numeric($datos['cantidad'])) {
            Response::error("Debes indicar 'cantidad' (numérica).", 400);
            return;
        }

        $carrito = CarritoDeCompras::obtenerOCrearParaCliente(Sesion::obtenerId());

        try {
            $carrito->modificarCantidad((int) $idProducto, (int) $datos['cantidad']);
            Response::exito($this->serializarCarrito($carrito), 'Carrito actualizado.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * eliminarProducto($idProducto)
     * DELETE /api/carrito/productos/{idProducto}
     */
    public function eliminarProducto(string $idProducto): void
    {
        $carrito = CarritoDeCompras::obtenerOCrearParaCliente(Sesion::obtenerId());
        $carrito->eliminarProducto((int) $idProducto);

        Response::exito($this->serializarCarrito($carrito), 'Producto eliminado del carrito.');
    }

    /**
     * vaciar()
     * DELETE /api/carrito
     */
    public function vaciar(): void
    {
        $carrito = CarritoDeCompras::obtenerOCrearParaCliente(Sesion::obtenerId());
        $carrito->vaciarCarrito();

        Response::exito($this->serializarCarrito($carrito), 'Carrito vaciado.');
    }

    /**
     * serializarCarrito()
     * Convierte el carrito y sus líneas en un arreglo plano para JSON.
     */
    private function serializarCarrito(CarritoDeCompras $carrito): array
    {
        return [
            'id_carrito' => $carrito->idCarrito,
            'subtotal'   => $carrito->subtotal,
            'items'      => array_map(fn($item) => [
                'id_producto'     => $item->idProducto,
                'cantidad'        => $item->cantidad,
                'precio_unitario' => $item->precioUnitario,
                'subtotal'        => $item->subtotal(),
            ], $carrito->items),
        ];
    }
}
