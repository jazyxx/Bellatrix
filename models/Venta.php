<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/DetalleVenta.php';
require_once __DIR__ . '/Producto.php';
require_once __DIR__ . '/Receta.php';

/**
 * ==========================================================================
 *  Venta.php
 * ==========================================================================
 *  Mapea la tabla `ventas`. Corresponde a la clase "Venta" del Diagrama
 *  de Clases. Representa una venta PRESENCIAL en el punto de venta (POS),
 *  gestionada por un Cajero (CU006, CU007, CU008).
 * ==========================================================================
 */
class Venta
{
    public ?int $idVenta;
    public ?string $fecha;
    public float $total;
    public string $canal;          // ENUM: 'Presencial'|'En línea'
    public string $unidadNegocio;  // ENUM: 'Pastelería'|'Heladería'
    public string $estado;         // ENUM: 'Activa'|'Anulada'
    public ?int $idEmpleado;

    /** @var DetalleVenta[] */
    public array $detalles = [];

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idVenta       = $datos['id_venta']       ?? null;
        $this->fecha         = $datos['fecha']          ?? null;
        $this->total         = isset($datos['total']) ? (float)$datos['total'] : 0.0;
        $this->canal         = $datos['canal']          ?? 'Presencial';
        $this->unidadNegocio = $datos['unidad_negocio'] ?? 'Pastelería';
        $this->estado        = $datos['estado']         ?? 'Activa';
        $this->idEmpleado    = $datos['id_empleado']    ?? null;

        if ($this->idVenta !== null) {
            $this->detalles = DetalleVenta::listarPorVenta($this->idVenta);
        }
    }

    /**
     * crear()
     * Abre una venta nueva en estado 'Activa' y total en 0 (CU006).
     * Los productos se agregan después con añadirProducto().
     */
    public function crear(): int
    {
        $sql = "INSERT INTO ventas (total, canal, unidad_negocio, estado, id_empleado)
                VALUES (0, :canal, :unidad, 'Activa', :empleado)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':canal', $this->canal);
        $stmt->bindValue(':unidad', $this->unidadNegocio);
        $stmt->bindValue(':empleado', $this->idEmpleado, PDO::PARAM_INT);
        $stmt->execute();

        $this->idVenta = (int)$this->pdo->lastInsertId();
        return $this->idVenta;
    }

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * añadirProducto($idProducto, $cantidad)
     * ------------------------------------------------------------
     * Implementa el CU007: agrega una línea de detalle a la venta,
     * validando primero que exista stock suficiente del producto.
     * NOTA: aquí todavía NO se descuenta el stock; eso solo ocurre
     * al llamar a finalizar() (CU008), para poder anular la venta
     * sin haber afectado el inventario si el cliente se arrepiente.
     */
    public function añadirProducto(int $idProducto, int $cantidad): bool
    {
        if ($this->estado !== 'Activa') {
            throw new Exception("No se pueden agregar productos a una venta que no está activa.");
        }

        $producto = Producto::obtenerPorId($idProducto);
        if (!$producto) {
            throw new Exception("El producto #{$idProducto} no existe.");
        }
        if ($cantidad > $producto->stock) {
            throw new Exception("Stock insuficiente para '{$producto->nombre}'. Disponible: {$producto->stock}.");
        }

        $detalle = new DetalleVenta([
            'id_venta'        => $this->idVenta,
            'id_producto'     => $idProducto,
            'cantidad'        => $cantidad,
            'precio_unitario' => $producto->precio,
        ]);
        $detalle->crear();
        $this->detalles[] = $detalle;

        $this->calcularTotal();
        return true;
    }

    /**
     * calcularTotal()
     * Suma el subtotal de cada línea de detalle y actualiza el total
     * de la venta tanto en el objeto como en la base de datos.
     */
    public function calcularTotal(): float
    {
        $total = 0.0;
        foreach ($this->detalles as $detalle) {
            $total += $detalle->subtotal();
        }
        $this->total = $total;

        $sql = "UPDATE ventas SET total = :total WHERE id_venta = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':total', $this->total);
        $stmt->bindValue(':id', $this->idVenta, PDO::PARAM_INT);
        $stmt->execute();

        return $this->total;
    }

    /**
     * finalizar()
     * ------------------------------------------------------------
     * IMPLEMENTA EL CU008 COMPLETO: "Actualización automatizada de
     * stock y descuento proporcional de materia prima al finalizar venta".
     *
     * Por cada línea de la venta:
     *   1. Descuenta el stock del PRODUCTO terminado.
     *   2. Descuenta, de forma proporcional, la MATERIA PRIMA usada
     *      según la receta de ese producto (Receta::descontarInsumosPorVenta).
     * Todo se hace dentro de una transacción para que, si algo falla
     * a mitad de camino, ningún stock quede descontado a medias.
     */
    public function finalizar(): bool
    {
        if ($this->estado !== 'Activa') {
            throw new Exception("Esta venta ya fue finalizada o anulada.");
        }
        if (empty($this->detalles)) {
            throw new Exception("No se puede finalizar una venta sin productos.");
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($this->detalles as $detalle) {
                // Paso 1: descontar el stock del producto terminado.
                $producto = Producto::obtenerPorId($detalle->idProducto);
                $producto->actualizarStock(-$detalle->cantidad);

                // Paso 2: descontar proporcionalmente la materia prima (receta).
                Receta::descontarInsumosPorVenta($detalle->idProducto, $detalle->cantidad);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * anularVenta()
     * ------------------------------------------------------------
     * Cambia el estado de la venta a 'Anulada'. Nota: si la venta ya
     * fue finalizada (stock ya descontado), este método NO revierte
     * automáticamente el stock; esa regla de "devolución de inventario"
     * se define con más detalle en la Fase 3 según la política de
     * negocio exacta que definas para devoluciones.
     */
    public function anularVenta(): bool
    {
        $sql = "UPDATE ventas SET estado = 'Anulada' WHERE id_venta = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idVenta, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->estado = 'Anulada';
        }
        return $ok;
    }

    // =================================================================
    //  Consultas
    // =================================================================

    public static function obtenerPorId(int $id): ?Venta
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM ventas WHERE id_venta = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Venta($fila) : null;
    }

    public static function listarTodas(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM ventas ORDER BY fecha DESC");
        return array_map(fn($fila) => new Venta($fila), $stmt->fetchAll());
    }

    public static function obtenerPorEmpleado(int $idEmpleado): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM ventas WHERE id_empleado = :empleado ORDER BY fecha DESC");
        $stmt->bindValue(':empleado', $idEmpleado, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new Venta($fila), $stmt->fetchAll());
    }
}
