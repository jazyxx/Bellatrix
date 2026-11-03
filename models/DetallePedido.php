<?php
require_once __DIR__ . '/../config/Database.php';

class DetallePedido
{
    public ?int $idDetallePedido;
    public int $idPedido;
    public int $idProducto;
    public int $cantidad;
    public float $precioUnitario;
  
    public ?string $nombreProducto;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idDetallePedido = $datos['id_detalle_pedido'] ?? null;
        $this->idPedido        = $datos['id_pedido']         ?? 0;
        $this->idProducto      = $datos['id_producto']       ?? 0;
        $this->cantidad        = isset($datos['cantidad']) ? (int)$datos['cantidad'] : 1;
        $this->precioUnitario  = isset($datos['precio_unitario']) ? (float)$datos['precio_unitario'] : 0.0;

                
        $this->nombreProducto  = $datos['nombre_producto'] ?? null;
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

                $sql = "
            SELECT d.*, p.nombre AS nombre_producto
            FROM detalle_pedido d
            LEFT JOIN productos p ON d.id_producto = p.id_producto
            WHERE d.id_pedido = :pedido
        ";

                $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':pedido', $idPedido, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new DetallePedido($fila), $stmt->fetchAll());
    }
}
