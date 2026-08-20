<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  DetallePedido.php
 * ==========================================================================
 *  Mapea la tabla `detalle_pedido`. Cada fila es un producto dentro de
 *  un pedido ya confirmado (a diferencia de carrito_items, que es
 *  mientras el cliente TODAVÍA está comprando).
 *  Corresponde a la lista "productos" de la clase Pedido en el Diagrama.
 * ==========================================================================
 */
class DetallePedido
{
    public ?int $idDetallePedido;
    public int $idPedido;
    public int $idProducto;
    public int $cantidad;
    public float $precioUnitario;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idDetallePedido = $datos['id_detalle_pedido'] ?? null;
        $this->idPedido        = $datos['id_pedido']         ?? 0;
        $this->idProducto      = $datos['id_producto']       ?? 0;
        $this->cantidad        = isset($datos['cantidad']) ? (int)$datos['cantidad'] : 1;
        $this->precioUnitario  = isset($datos['precio_unitario']) ? (float)$datos['precio_unitario'] : 0.0;
    }

    public function subtotal(): float
    {
        return $this->cantidad * $this->precioUnitario;
    }

    public function crear(): int
    {
        $sql = "INSERT INTO detalle_pedido (id_pedido, id_producto, cantidad, precio_unitario)
                VALUES (:pedido, :producto, :cantidad, :precio)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':pedido', $this->idPedido, PDO::PARAM_INT);
        $stmt->bindValue(':producto', $this->idProducto, PDO::PARAM_INT);
        $stmt->bindValue(':cantidad', $this->cantidad, PDO::PARAM_INT);
        $stmt->bindValue(':precio', $this->precioUnitario);
        $stmt->execute();

        $this->idDetallePedido = (int)$this->pdo->lastInsertId();
        return $this->idDetallePedido;
    }

    public static function listarPorPedido(int $idPedido): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM detalle_pedido WHERE id_pedido = :pedido");
        $stmt->bindValue(':pedido', $idPedido, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new DetallePedido($fila), $stmt->fetchAll());
    }
}
