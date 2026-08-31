<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/CarritoItem.php';
require_once __DIR__ . '/Producto.php';

/**
 * ==========================================================================
 *  CarritoDeCompras.php
 * ==========================================================================
 *  Mapea la tabla `carrito` (cabecera) + se apoya en CarritoItem.php
 *  (líneas). Corresponde a la clase "CarritoDeCompras" del Diagrama de
 *  Clases, incluyendo su relación 1 a 1 con Cliente ("carrito" tiene
 *  una restricción UNIQUE sobre id_cliente: cada cliente tiene UN solo
 *  carrito activo, tal como indica la BD).
 * ==========================================================================
 */
class CarritoDeCompras
{
    public ?int $idCarrito;
    public int $idCliente;
    public float $subtotal;
    public ?string $creadoEn;
    public ?string $actualizado;

    /** @var CarritoItem[] Lista de líneas del carrito. */
    public array $items = [];

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idCarrito  = $datos['id_carrito']  ?? null;
        $this->idCliente  = $datos['id_cliente']  ?? 0;
        $this->subtotal   = isset($datos['subtotal']) ? (float)$datos['subtotal'] : 0.0;
        $this->creadoEn   = $datos['creado_en']   ?? null;
        $this->actualizado = $datos['actualizado'] ?? null;

        if ($this->idCarrito !== null) {
            $this->items = CarritoItem::listarPorCarrito($this->idCarrito);
        }
    }

    /**
     * obtenerOCrearParaCliente($idCliente)
     * ------------------------------------------------------------
     * Como cada cliente tiene máximo UN carrito (restricción UNIQUE
     * en la BD), este método busca su carrito existente o, si nunca
     * ha comprado, le crea uno vacío automáticamente.
     */
    public static function obtenerOCrearParaCliente(int $idCliente): CarritoDeCompras
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM carrito WHERE id_cliente = :cliente");
        $stmt->bindValue(':cliente', $idCliente, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        if ($fila) {
            return new CarritoDeCompras($fila);
        }

        // No existía carrito -> se crea uno nuevo vacío.
        $insertar = $pdo->prepare("INSERT INTO carrito (id_cliente, subtotal) VALUES (:cliente, 0)");
        $insertar->bindValue(':cliente', $idCliente, PDO::PARAM_INT);
        $insertar->execute();

        $nuevoCarrito = new CarritoDeCompras();
        $nuevoCarrito->idCarrito = (int)$pdo->lastInsertId();
        $nuevoCarrito->idCliente = $idCliente;
        return $nuevoCarrito;
    }

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * agregarProducto($idProducto, $cantidad)
     * ------------------------------------------------------------
     * Agrega un producto al carrito. Si el producto YA estaba en el
     * carrito, simplemente incrementa la cantidad en lugar de crear
     * una línea duplicada (respetando la restricción UNIQUE de la BD).
     * Valida que haya stock disponible antes de agregar (CU012).
     */
    public function agregarProducto(int $idProducto, int $cantidad): bool
    {
        $producto = Producto::obtenerPorId($idProducto);
        if (!$producto || !$producto->disponible) {
            throw new Exception("El producto no existe o no está disponible.");
        }

        $itemExistente = CarritoItem::obtenerPorCarritoYProducto($this->idCarrito, $idProducto);

        $cantidadTotal = $cantidad + ($itemExistente ? $itemExistente->cantidad : 0);
        if ($cantidadTotal > $producto->stock) {
            throw new Exception("Stock insuficiente. Disponible: {$producto->stock}, solicitado: {$cantidadTotal}.");
        }

        if ($itemExistente) {
            $itemExistente->actualizarCantidad($cantidadTotal);
        } else {
            $nuevoItem = new CarritoItem([
                'id_carrito'      => $this->idCarrito,
                'id_producto'     => $idProducto,
                'cantidad'        => $cantidad,
                'precio_unitario' => $producto->precio, // Se "congela" el precio actual.
            ]);
            $nuevoItem->crear();
        }

        $this->items = CarritoItem::listarPorCarrito($this->idCarrito);
        $this->calcularSubtotal();
        return true;
    }

    /**
     * eliminarProducto($idProducto)
     * Quita por completo un producto del carrito.
     */
    public function eliminarProducto(int $idProducto): bool
    {
        $item = CarritoItem::obtenerPorCarritoYProducto($this->idCarrito, $idProducto);
        if (!$item) {
            return false;
        }

        $ok = $item->eliminar();
        if ($ok) {
            $this->items = CarritoItem::listarPorCarrito($this->idCarrito);
            $this->calcularSubtotal();
        }
        return $ok;
    }

    /**
     * modificarCantidad($idProducto, $cantidad)
     * Cambia la cantidad de un producto ya existente en el carrito
     * (por ejemplo, cuando el cliente usa los botones +/- en la UI).
     */
    public function modificarCantidad(int $idProducto, int $cantidad): void
    {
        if ($cantidad <= 0) {
            $this->eliminarProducto($idProducto);
            return;
        }

        $producto = Producto::obtenerPorId($idProducto);
        if ($producto && $cantidad > $producto->stock) {
            throw new Exception("Stock insuficiente. Disponible: {$producto->stock}.");
        }

        $item = CarritoItem::obtenerPorCarritoYProducto($this->idCarrito, $idProducto);
        if ($item) {
            $item->actualizarCantidad($cantidad);
            $this->items = CarritoItem::listarPorCarrito($this->idCarrito);
            $this->calcularSubtotal();
        }
    }

    /**
     * calcularSubtotal()
     * Suma el subtotal de cada línea (cantidad * precio_unitario) y
     * lo guarda tanto en la propiedad del objeto como en la BD, para
     * que el frontend pueda leerlo directamente sin recalcularlo.
     */
    public function calcularSubtotal(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->subtotal();
        }

        $this->subtotal = $total;

        $sql = "UPDATE carrito SET subtotal = :subtotal WHERE id_carrito = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':subtotal', $this->subtotal);
        $stmt->bindValue(':id', $this->idCarrito, PDO::PARAM_INT);
        $stmt->execute();

        return $this->subtotal;
    }

    /**
     * vaciarCarrito()
     * Elimina todas las líneas del carrito (ej. después de convertirlo
     * exitosamente en un Pedido, o si el cliente lo cancela manualmente).
     */
    public function vaciarCarrito(): bool
    {
        $ok = CarritoItem::vaciarPorCarrito($this->idCarrito);
        if ($ok) {
            $this->items = [];
            $this->calcularSubtotal(); // Queda en 0.
        }
        return $ok;
    }
}
