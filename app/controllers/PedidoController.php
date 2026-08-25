<?php
require_once __DIR__ . '/../../models/Pedido.php';
require_once __DIR__ . '/../../models/CarritoDeCompras.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

/**
 * ==========================================================================
 *  PedidoController.php
 * ==========================================================================
 *  Implementa 3 Casos de Uso del ciclo de e-commerce:
 *
 *    - CU013: Realizar Pedido en Línea (el Cliente confirma su carrito).
 *    - CU015: Consultar Estado del Pedido (el Cliente hace seguimiento).
 *    - CU017: Gestionar Pedidos en Línea (Administrador/Cajero procesan
 *             los pedidos confirmados y actualizan su estado).
 *
 *  El envío de notificaciones al cliente (CU016) NO se gestiona aquí de
 *  forma manual: ya ocurre AUTOMÁTICAMENTE dentro de
 *  Pedido::cambiarEstado() (construido en la Fase 1), que internamente
 *  llama a notificarCliente() cada vez que el estado cambia. Este
 *  controlador simplemente dispara esos cambios de estado.
 * ==========================================================================
 */
class PedidoController
{
    /**
     * confirmar()
     * ------------------------------------------------------------
     * POST /api/pedidos  (rol: Cliente)
     * Body: { "direccion_entrega": "..." }
     *
     * Implementa el CU013: toma el carrito ACTUAL del cliente en
     * sesión y lo convierte en un Pedido formal, usando
     * Pedido::confirmar() de la Fase 1 (que ya valida que el carrito
     * no esté vacío y vacía el carrito al terminar).
     */
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
            // Ej: "No se puede confirmar un pedido con el carrito vacío."
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * misPedidos()
     * ------------------------------------------------------------
     * GET /api/pedidos  (rol: Cliente)
     * Implementa el CU015 (listado, para que el cliente vea todo su
     * historial y pueda entrar a cualquiera a ver su estado actual).
     */
    public function misPedidos(): void
    {
        $pedidos = Pedido::obtenerPorCliente(Sesion::obtenerId());
        $datos = array_map(fn(Pedido $p) => $this->serializarPedido($p), $pedidos);
        Response::exito($datos, 'Historial de pedidos obtenido correctamente.');
    }

    /**
     * ver($id)
     * ------------------------------------------------------------
     * GET /api/pedidos/{id}  (rol: cualquiera autenticado)
     * Implementa el CU015 (detalle). Si quien consulta es un Cliente,
     * se valida que el pedido sea SUYO (para que un cliente no pueda
     * espiar el pedido de otro cambiando el id en la URL). Administrador
     * y Cajero pueden ver cualquier pedido (lo necesitan para el CU017).
     */
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

    /**
     * listarPorEstado()
     * ------------------------------------------------------------
     * GET /api/pedidos/gestion?estado=Confirmado  (rol: Administrador, Cajero)
     * Implementa la parte de "bandeja de trabajo" del CU017: el
     * Administrador/Cajero ve los pedidos pendientes de procesar,
     * filtrados por estado (por defecto, los recién 'Confirmado's,
     * que son los que ya se pagaron y están listos para prepararse).
     */
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

    /**
     * actualizarEstado($id)
     * ------------------------------------------------------------
     * PUT /api/pedidos/{id}/estado  (rol: Administrador, Cajero)
     * Body: { "estado": "En preparación" }
     *
     * Implementa el corazón del CU017. Al llamar a
     * Pedido::cambiarEstado() (Fase 1), automáticamente:
     *   1. Se valida que el nuevo estado sea uno de los permitidos.
     *   2. Se actualiza en la base de datos.
     *   3. Se dispara notificarCliente() -> se crea y "envía"
     *      (simulado) la notificación correspondiente al cliente (CU016).
     */
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
            // Se registra qué empleado gestionó este cambio de estado.
            $pedido->idEmpleadoGestion = Sesion::obtenerId();
            $pedido->cambiarEstado($nuevoEstado);

            Response::exito($this->serializarPedido($pedido), "El pedido ahora está en estado: '{$nuevoEstado}'.");
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * serializarPedido()
     * Convierte el Pedido y sus líneas de detalle en un arreglo plano.
     */
    private function serializarPedido(Pedido $pedido): array
    {
        return [
            'id_pedido'            => $pedido->idPedido,
            'id_cliente'           => $pedido->idCliente,
            'estado'               => $pedido->estado,
            'direccion_entrega'    => $pedido->direccionEntrega,
            'total'                => $pedido->total,
            'fecha_creacion'       => $pedido->fechaCreacion,
            'fecha_actualizacion'  => $pedido->fechaActualizacion,
            'id_empleado_gestion'  => $pedido->idEmpleadoGestion,
            'productos'            => array_map(fn($d) => [
                'id_producto'     => $d->idProducto,
                'cantidad'        => $d->cantidad,
                'precio_unitario' => $d->precioUnitario,
                'subtotal'        => $d->subtotal(),
            ], $pedido->productos),
        ];
    }
}
