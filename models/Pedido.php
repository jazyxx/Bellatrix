<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/DetallePedido.php';
require_once __DIR__ . '/CarritoDeCompras.php';

/**
 * ==========================================================================
 *  Pedido.php
 * ==========================================================================
 *  Mapea la tabla `pedido`. Corresponde a la clase "Pedido" del
 *  Diagrama de Clases. Un Pedido nace cuando el Cliente confirma su
 *  CarritoDeCompras (CU013), y su ciclo de vida completo está definido
 *  por la columna ENUM `estado`:
 *
 *   'Pendiente de pago' -> 'Confirmado' -> 'En preparación'
 *      -> 'Listo para recoger' -> 'Entregado'   (o 'Cancelado' en cualquier punto)
 * ==========================================================================
 */
class Pedido
{
    public ?int $idPedido;
    public int $idCliente;
    public string $estado;
    public string $direccionEntrega;
    public float $total;
    public ?string $fechaCreacion;
    public ?string $fechaActualizacion;
    public ?int $idEmpleadoGestion;

    /** @var DetallePedido[] */
    public array $productos = [];

    private PDO $pdo;

    /** Estados válidos, en el mismo orden que el ENUM de la base de datos. */
    public const ESTADOS_VALIDOS = [
        'Pendiente de pago', 'Confirmado', 'En preparación',
        'Listo para recoger', 'Entregado', 'Cancelado',
    ];

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idPedido           = $datos['id_pedido']            ?? null;
        $this->idCliente          = $datos['id_cliente']           ?? 0;
        $this->estado             = $datos['estado']               ?? 'Pendiente de pago';
        $this->direccionEntrega   = $datos['direccion_entrega']    ?? '';
        $this->total              = isset($datos['total']) ? (float)$datos['total'] : 0.0;
        $this->fechaCreacion      = $datos['fecha_creacion']       ?? null;
        $this->fechaActualizacion = $datos['fecha_actualizacion']  ?? null;
        $this->idEmpleadoGestion  = $datos['id_empleado_gestion']  ?? null;

        if ($this->idPedido !== null) {
            $this->productos = DetallePedido::listarPorPedido($this->idPedido);
        }
    }

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * confirmar()
     * ------------------------------------------------------------
     * Implementa el núcleo del CU013 (Realizar Pedido en Línea):
     * convierte un CarritoDeCompras en un Pedido formal.
     *   1. Crea la fila en `pedido`.
     *   2. Copia cada línea del carrito a `detalle_pedido`.
     *   3. Vacía el carrito de origen.
     * Todo dentro de una transacción: o se hace completo, o no se hace nada.
     *
     * @param CarritoDeCompras $carrito El carrito que se va a confirmar.
     * @return bool true si el pedido se creó correctamente.
     */
    public function confirmar(CarritoDeCompras $carrito): bool
    {
        if (empty($carrito->items)) {
            throw new Exception("No se puede confirmar un pedido con el carrito vacío.");
        }

        $this->pdo->beginTransaction();
        try {
            $sql = "INSERT INTO pedido (id_cliente, estado, direccion_entrega, total)
                    VALUES (:cliente, 'Pendiente de pago', :direccion, :total)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':cliente', $carrito->idCliente, PDO::PARAM_INT);
            $stmt->bindValue(':direccion', $this->direccionEntrega);
            $stmt->bindValue(':total', $carrito->subtotal);
            $stmt->execute();

            $this->idPedido = (int)$this->pdo->lastInsertId();
            $this->idCliente = $carrito->idCliente;
            $this->total = $carrito->subtotal;
            $this->estado = 'Pendiente de pago';

            // Se copia cada línea del carrito al detalle_pedido.
            foreach ($carrito->items as $item) {
                $detalle = new DetallePedido([
                    'id_pedido'       => $this->idPedido,
                    'id_producto'     => $item->idProducto,
                    'cantidad'        => $item->cantidad,
                    'precio_unitario' => $item->precioUnitario,
                ]);
                $detalle->crear();
                $this->productos[] = $detalle;
            }

            // El carrito ya se convirtió en pedido, se vacía.
            $carrito->vaciarCarrito();

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * cambiarEstado($nuevoEstado)
     * ------------------------------------------------------------
     * Avanza el pedido en su ciclo de vida (usado por CU015 y CU017).
     * Valida que el estado sea uno de los permitidos por el ENUM y,
     * al cambiarlo, dispara automáticamente notificarCliente().
     */
    public function cambiarEstado(string $nuevoEstado): bool
    {
        if (!in_array($nuevoEstado, self::ESTADOS_VALIDOS, true)) {
            throw new Exception("Estado de pedido no válido: '{$nuevoEstado}'.");
        }

        $sql = "UPDATE pedido SET estado = :estado, id_empleado_gestion = :empleado WHERE id_pedido = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':estado', $nuevoEstado);
        $stmt->bindValue(':empleado', $this->idEmpleadoGestion, PDO::PARAM_INT);
        $stmt->bindValue(':id', $this->idPedido, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->estado = $nuevoEstado;
            // CU016: cada cambio de estado genera una notificación automática.
            $this->notificarCliente();
        }

        return $ok;
    }

    /**
     * notificarCliente()
     * ------------------------------------------------------------
     * Crea el registro de Notificación correspondiente al estado
     * actual del pedido. El ENVÍO real del correo (con PHPMailer o
     * la librería que se elija) se conecta en la Fase 4, pero el
     * modelo ya deja preparada la creación del registro en la BD.
     */
    public function notificarCliente(): bool
    {
        require_once __DIR__ . '/Notificacion.php';

        $tipo = ($this->estado === 'Pendiente de pago')
            ? 'Confirmación de pedido'
            : 'Cambio de estado';

        $notificacion = new Notificacion([
            'id_cliente' => $this->idCliente,
            'id_pedido'  => $this->idPedido,
            'tipo'       => $tipo,
        ]);

        $mensaje = ($tipo === 'Confirmación de pedido')
            ? $notificacion->generarMensajeConfirmacion($this)
            : $notificacion->generarMensajeCambioEstado($this->estado);

        $notificacion->mensaje = $mensaje;
        $notificacion->crear();

        return true;
    }

    // =================================================================
    //  Consultas
    // =================================================================

    public static function obtenerPorId(int $id): ?Pedido
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM pedido WHERE id_pedido = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Pedido($fila) : null;
    }

    /** consultarPedidos() del Cliente usa este método. */
    public static function obtenerPorCliente(int $idCliente): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM pedido WHERE id_cliente = :cliente ORDER BY fecha_creacion DESC");
        $stmt->bindValue(':cliente', $idCliente, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new Pedido($fila), $stmt->fetchAll());
    }

    /** Para el panel de Administrador/Cajero (CU017): pedidos pendientes de gestión. */
    public static function listarPorEstado(string $estado): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM pedido WHERE estado = :estado ORDER BY fecha_creacion ASC");
        $stmt->bindValue(':estado', $estado);
        $stmt->execute();

        return array_map(fn($fila) => new Pedido($fila), $stmt->fetchAll());
    }
}
