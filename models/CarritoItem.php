<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  CarritoItem.php
 * ==========================================================================
 *  Mapea la tabla `carrito_items`. Cada fila es UN producto dentro de UN
 *  carrito, con su cantidad y el precio unitario "congelado" en el
 *  momento en que se agregó (así, si el precio del producto cambia
 *  después, el carrito no se ve afectado retroactivamente).
 *
 *  En el Diagrama de Clases, esto corresponde a la lista "items" que
 *  tiene la clase CarritoDeCompras. Se modela como una clase aparte
 *  porque en la base de datos es una tabla independiente.
 * ==========================================================================
 */
class CarritoItem
{
    public ?int $idItem;
    public int $idCarrito;
    public int $idProducto;
    public int $cantidad;
    public float $precioUnitario;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idItem         = $datos['id_item']         ?? null;
        $this->idCarrito      = $datos['id_carrito']      ?? 0;
        $this->idProducto     = $datos['id_producto']     ?? 0;
        $this->cantidad       = isset($datos['cantidad']) ? (int)$datos['cantidad'] : 1;
        $this->precioUnitario = isset($datos['precio_unitario']) ? (float)$datos['precio_unitario'] : 0.0;
    }

    /** Subtotal de esta línea = cantidad * precio unitario. */
    public function subtotal(): float
    {
        return $this->cantidad * $this->precioUnitario;
    }

    public function crear(): int
    {
        $sql = "INSERT INTO carrito_items (id_carrito, id_producto, cantidad, precio_unitario)
                VALUES (:carrito, :producto, :cantidad, :precio)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':carrito', $this->idCarrito, PDO::PARAM_INT);
        $stmt->bindValue(':producto', $this->idProducto, PDO::PARAM_INT);
        $stmt->bindValue(':cantidad', $this->cantidad, PDO::PARAM_INT);
        $stmt->bindValue(':precio', $this->precioUnitario);
        $stmt->execute();

        $this->idItem = (int)$this->pdo->lastInsertId();
        return $this->idItem;
    }

    public function actualizarCantidad(int $cantidad): bool
    {
        $sql = "UPDATE carrito_items SET cantidad = :cantidad WHERE id_item = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cantidad', $cantidad, PDO::PARAM_INT);
        $stmt->bindValue(':id', $this->idItem, PDO::PARAM_INT);
        $ok = $stmt->execute();
        if ($ok) $this->cantidad = $cantidad;
        return $ok;
    }

    public function eliminar(): bool
    {
        $sql = "DELETE FROM carrito_items WHERE id_item = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idItem, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * obtenerPorCarritoYProducto()
     * Como la tabla tiene una restricción UNIQUE (id_carrito, id_producto),
     * este método sirve para saber si un producto YA está en el carrito
     * antes de decidir si se inserta una fila nueva o se actualiza la cantidad.
     */
    public static function obtenerPorCarritoYProducto(int $idCarrito, int $idProducto): ?CarritoItem
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM carrito_items WHERE id_carrito = :carrito AND id_producto = :producto");
        $stmt->bindValue(':carrito', $idCarrito, PDO::PARAM_INT);
        $stmt->bindValue(':producto', $idProducto, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new CarritoItem($fila) : null;
    }

    public static function listarPorCarrito(int $idCarrito): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM carrito_items WHERE id_carrito = :carrito");
        $stmt->bindValue(':carrito', $idCarrito, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new CarritoItem($fila), $stmt->fetchAll());
    }

    public static function vaciarPorCarrito(int $idCarrito): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM carrito_items WHERE id_carrito = :carrito");
        $stmt->bindValue(':carrito', $idCarrito, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
