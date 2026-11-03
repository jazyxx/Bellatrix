<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/DetallePedido.php';
require_once __DIR__ . '/Venta.php';
require_once __DIR__ . '/CarritoDeCompras.php';

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

        
    public ?string $nombreCliente;
    public ?string $telefonoCliente;

    
    public array $productos = [];

    private PDO $pdo;

    
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

        $this->fechaActualizacion = $datos['fecha_actualizacion']  ?? null;
        $this->idEmpleadoGestion  = $datos['id_empleado_gestion']  ?? null;

        
        $this->nombreCliente   = $datos['nombre_cliente'] ?? null;
        $this->telefonoCliente = $datos['telefono_cliente'] ?? null;

        if ($this->idPedido !== null) {
            $this->productos = DetallePedido::listarPorPedido($this->idPedido);
        }
    }

    
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

            
            $carrito->vaciarCarrito();

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function cambiarEstado(string $nuevoEstado): bool
    {
        if (!in_array($nuevoEstado, self::ESTADOS_VALIDOS, true)) {
            throw new Exception("Estado de pedido no válido: '{$nuevoEstado}'.");
        }

        $estadoAnterior = $this->estado;
        if ($estadoAnterior === 'Cancelado' && $nuevoEstado !== 'Cancelado') {
            throw new Exception("Un pedido cancelado no puede reactivarse.");
        }

        
        $propia = !$this->pdo->inTransaction();
        if ($propia) {
            $this->pdo->beginTransaction();
        }
        try {
            $sql = "UPDATE pedido SET estado = :estado, id_empleado_gestion = :empleado WHERE id_pedido = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':estado', $nuevoEstado);
            $stmt->bindValue(':empleado', $this->idEmpleadoGestion, PDO::PARAM_INT);
            $stmt->bindValue(':id', $this->idPedido, PDO::PARAM_INT);
            $ok = $stmt->execute();

            if ($ok) {
                if (!in_array($nuevoEstado, ['Pendiente de pago', 'Cancelado'], true)) {
                  
                    Venta::registrarDesdePedido($this, $this->idEmpleadoGestion !== null ? (int)$this->idEmpleadoGestion : null);
                } elseif ($nuevoEstado === 'Cancelado'
                    && !in_array($estadoAnterior, ['Pendiente de pago', 'Cancelado'], true)) {
                    
                    Venta::anularDesdePedido($this, $estadoAnterior);
                }
            }
            if ($propia) {
                $this->pdo->commit();
            }
        } catch (Exception $e) {
            if ($propia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($ok) {
            $this->estado = $nuevoEstado;
            
            
            
            if ($estadoAnterior !== $nuevoEstado) {
                $this->notificarCliente();
            }
        }

        return $ok;
    }

    
    public function notificarCliente(): bool
    {
        require_once __DIR__ . '/Notificacion.php';
        require_once __DIR__ . '/Cliente.php';

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

        
        $cliente = Cliente::obtenerPorId($this->idCliente);
        if ($cliente === null || !filter_var($cliente->correo, FILTER_VALIDATE_EMAIL)) {
            error_log("[NOTIFICACIÓN] Pedido #{$this->idPedido}: el cliente no tiene un correo válido; no se envió el aviso.");
            return true;
        }

        
        if (empty($this->productos) && $this->idPedido !== null) {
            $this->productos = DetallePedido::listarPorPedido($this->idPedido);
        }

        [$asunto, $html, $texto] = $notificacion->armarCorreoPedido($this, $cliente->nombre);

        if ($this->pdo->inTransaction()) {
            Notificacion::encolarCorreo($notificacion, $cliente->correo, $asunto, $html, $texto);
        } else {
            $notificacion->enviarCorreo($cliente->correo, $texto, $asunto, $html);
        }

        return true;
    }
 

    public static function obtenerPorId(int $id): ?Pedido
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM pedido WHERE id_pedido = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Pedido($fila) : null;
    }

    
    public static function obtenerPorCliente(int $idCliente): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM pedido WHERE id_cliente = :cliente ORDER BY fecha_creacion DESC");
        $stmt->bindValue(':cliente', $idCliente, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new Pedido($fila), $stmt->fetchAll());
    }

    
    public static function listarPorEstado(string $estado): array
    {
        $pdo = Database::getConnection();
        
        $sql = "
            SELECT p.*, u.nombre AS nombre_cliente, u.telefono AS telefono_cliente 
            FROM pedido p
            LEFT JOIN cliente u ON p.id_cliente = u.id_cliente
            WHERE p.estado = :estado 
            ORDER BY p.fecha_creacion ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':estado', $estado);
        $stmt->execute();

        return array_map(fn($fila) => new Pedido($fila), $stmt->fetchAll());
    }
}
