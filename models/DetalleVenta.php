<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  DetalleVenta.php
 * ==========================================================================
 *  Mapea la tabla `detalle_venta`. Cada fila es un producto dentro de
 *  una venta presencial (punto de venta / POS). Corresponde a la
 *  relación "Venta incluye 1..* Producto" del Diagrama de Clases.
 * ==========================================================================
 */
class DetalleVenta
{
    public ?int $idDetalle;
    public ?int $idVenta;
    public ?int $idProducto;
    public int $cantidad;
    public float $precioUnitario;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idDetalle      = $datos['id_detalle']      ?? null;
        $this->idVenta        = $datos['id_venta']        ?? null;
        $this->idProducto     = $datos['id_producto']     ?? null;
        $this->cantidad       = isset($datos['cantidad']) ? (int)$datos['cantidad'] : 1;
        $this->precioUnitario = isset($datos['precio_unitario']) ? (float)$datos['precio_unitario'] : 0.0;
    }

    public function subtotal(): float
    {
        return $this->cantidad * $this->precioUnitario;
    }

    public function crear(): int
    {
        $sql = "INSERT INTO detalle_venta (id_venta, id_producto, cantidad, precio_unitario)
                VALUES (:venta, :producto, :cantidad, :precio)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':venta', $this->idVenta, PDO::PARAM_INT);
        $stmt->bindValue(':producto', $this->idProducto, PDO::PARAM_INT);
        $stmt->bindValue(':cantidad', $this->cantidad, PDO::PARAM_INT);
        $stmt->bindValue(':precio', $this->precioUnitario);
        $stmt->execute();

        $this->idDetalle = (int)$this->pdo->lastInsertId();
        return $this->idDetalle;
    }

    public static function listarPorVenta(int $idVenta): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM detalle_venta WHERE id_venta = :venta");
        $stmt->bindValue(':venta', $idVenta, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new DetalleVenta($fila), $stmt->fetchAll());
    }
}
