<?php
require_once __DIR__ . '/../../models/CarritoDeCompras.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

class CarritoController
{
   
    public function ver(): void
    {
        $carrito = CarritoDeCompras::obtenerOCrearParaCliente(Sesion::obtenerId());
        Response::exito($this->serializarCarrito($carrito));
    }

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
            
            Response::error($e->getMessage(), 422);
        }
    }

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

    public function eliminarProducto(string $idProducto): void
    {
        $carrito = CarritoDeCompras::obtenerOCrearParaCliente(Sesion::obtenerId());
        $carrito->eliminarProducto((int) $idProducto);

        Response::exito($this->serializarCarrito($carrito), 'Producto eliminado del carrito.');
    }

    public function vaciar(): void
    {
        $carrito = CarritoDeCompras::obtenerOCrearParaCliente(Sesion::obtenerId());
        $carrito->vaciarCarrito();

        Response::exito($this->serializarCarrito($carrito), 'Carrito vaciado.');
    }

    private function serializarCarrito(CarritoDeCompras $carrito): array
    {
        return [
            'id_carrito' => $carrito->idCarrito,
            'subtotal'   => $carrito->subtotal,
            'items'      => array_map(fn($item) => [
                'id_producto'     => $item->idProducto,
                'nombre_producto' => $item->nombreProducto,
                'cantidad'        => $item->cantidad,
                'precio_unitario' => $item->precioUnitario,
                'subtotal'        => $item->subtotal(),
            ], $carrito->items),
        ];
    }
}
