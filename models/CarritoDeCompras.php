<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/CarritoItem.php';
require_once __DIR__ . '/Producto.php';

class CarritoDeCompras
{
    public ?int $idCarrito;
    public int $idCliente;
    public float $subtotal;
    public ?string $creadoEn;
    public ?string $actualizado;

    
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

        
        $insertar = $pdo->prepare("INSERT INTO carrito (id_cliente, subtotal) VALUES (:cliente, 0)");
        $insertar->bindValue(':cliente', $idCliente, PDO::PARAM_INT);
        $insertar->execute();

        $nuevoCarrito = new CarritoDeCompras();
        $nuevoCarrito->idCarrito = (int)$pdo->lastInsertId();
        $nuevoCarrito->idCliente = $idCliente;
        return $nuevoCarrito;
    }

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
                'precio_unitario' => $producto->precio, 
            ]);
            $nuevoItem->crear();
        }

        $this->items = CarritoItem::listarPorCarrito($this->idCarrito);
        $this->calcularSubtotal();
        return true;
    }

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

    
    public function vaciarCarrito(): bool
    {
        $ok = CarritoItem::vaciarPorCarrito($this->idCarrito);
        if ($ok) {
            $this->items = [];
            $this->calcularSubtotal(); 
        }
        return $ok;
    }
}
