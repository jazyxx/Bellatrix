<?php
require_once __DIR__ . '/../../models/Pedido.php';
require_once __DIR__ . '/../../models/CarritoDeCompras.php';
require_once __DIR__ . '/../../models/Receta.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

class PedidoController
{
  
    public function confirmar(): void
    {
        $datos = Request::jsonBody();
        $direccionEntrega = trim($datos['direccion_entrega'] ?? '');

        if ($direccionEntrega === '') {
            Response::error('Debes indicar una dirección de entrega.', 400);
            return;
        }

        $idCliente = Sesion::obtenerId();
        $carrito = CarritoDeCompras::obtenerOCrearParaCliente($idCliente);

        try {
            $pedido = new Pedido(['direccion_entrega' => $direccionEntrega]);
            $pedido->confirmar($carrito);

            Response::exito($this->serializarPedido($pedido), 'Pedido registrado exitosamente. ¡Gracias por tu compra!', 201);
        } catch (Exception $e) {
            
            Response::error($e->getMessage(), 422);
        }
    }

    public function misPedidos(): void
    {
        $pedidos = Pedido::obtenerPorCliente(Sesion::obtenerId());
        $datos = array_map(fn(Pedido $p) => $this->serializarPedido($p), $pedidos);
        Response::exito($datos, 'Historial de pedidos obtenido correctamente.');
    }

    public function ver(string $id): void
    {
        $pedido = Pedido::obtenerPorId((int) $id);
        if ($pedido === null) {
            Response::error('El pedido solicitado no existe.', 404);
            return;
        }

        if (Sesion::obtenerRol() === 'Cliente' && $pedido->idCliente !== Sesion::obtenerId()) {
            Response::error('No tienes permiso para ver este pedido.', 403);
            return;
        }

        Response::exito($this->serializarPedido($pedido));
    }

    public function listarPorEstado(): void
    {
        $estado = Request::query('estado', 'Confirmado');

        if (!in_array($estado, Pedido::ESTADOS_VALIDOS, true)) {
            Response::error('El estado indicado no es válido.', 400);
            return;
        }

        $pedidos = Pedido::listarPorEstado($estado);
        $datos = array_map(fn(Pedido $p) => $this->serializarPedido($p), $pedidos);
        Response::exito($datos, "Pedidos con estado '{$estado}' obtenidos correctamente.");
    }

    public function actualizarEstado(string $id): void
    {
        $pedido = Pedido::obtenerPorId((int) $id);
        if ($pedido === null) {
            Response::error('El pedido solicitado no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        $nuevoEstado = $datos['estado'] ?? '';

        try {
            
            $estadoAnterior = $pedido->estado;
            
            $pedido->idEmpleadoGestion = Sesion::obtenerId();
            $pedido->cambiarEstado($nuevoEstado);
           
            if ($nuevoEstado === 'Listo para recoger' && $estadoAnterior !== 'Listo para recoger') {
                                
                foreach ($pedido->productos as $detalle) {
                    Receta::descontarInsumosPorVenta($detalle->idProducto, $detalle->cantidad);
                }
            }

            Response::exito($this->serializarPedido($pedido), "El pedido ahora está en estado: '{$nuevoEstado}'.");
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    public function cancelarPorCliente(string $id): void
    {
        $pedido = Pedido::obtenerPorId((int) $id);

                if ($pedido === null) {
            Response::error('El pedido solicitado no existe.', 404);
            return;
        }
     
        if ($pedido->idCliente !== Sesion::obtenerId()) {
            Response::error('No tienes permiso para modificar este pedido.', 403);
            return;
        }
 
        $estadosPermitidos = ['Pendiente de pago', 'Confirmado'];
        if (!in_array($pedido->estado, $estadosPermitidos, true)) {
            Response::error('El pedido ya no se puede cancelar porque su estado es: ' . $pedido->estado, 400);
            return;
        }

        $estadoAnterior = $pedido->estado;

        try {
            $pedido->cambiarEstado('Cancelado');
    
            if ($estadoAnterior === 'Confirmado') {
                $mensajeStaff = "URGENTE: El cliente canceló el pedido #{$id}. DETENER PRODUCCIÓN. Requiere proceso de reembolso (Pedido ya estaba pagado).";
            } else {
                $mensajeStaff = "El cliente canceló el pedido #{$id} (No requiere reembolso, estaba pendiente de pago).";
            }

            
            
            
            

                        Response::exito($this->serializarPedido($pedido), 'Tu pedido ha sido cancelado exitosamente.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    private function serializarPedido(Pedido $pedido): array
    {
        return [
            'id_pedido'           => $pedido->idPedido,
            'id_cliente'          => $pedido->idCliente,
            
            'nombre_cliente'      => $pedido->nombreCliente ?? null,
            'telefono_cliente'    => $pedido->telefonoCliente ?? null,
            'estado'              => $pedido->estado,
            'direccion_entrega'   => $pedido->direccionEntrega,
            'total'               => $pedido->total,
            'fecha_creacion'      => $pedido->fechaCreacion,
            'fecha_actualizacion' => $pedido->fechaActualizacion,
            'id_empleado_gestion' => $pedido->idEmpleadoGestion,
            'productos'           => array_map(fn($d) => [
                'id_producto'     => $d->idProducto,
                
                'nombre_producto' => $d->nombreProducto ?? null,
                'cantidad'        => $d->cantidad,
                'precio_unitario' => $d->precioUnitario,
                'subtotal'        => $d->subtotal(),
            ], $pedido->productos),
        ];
    }
}