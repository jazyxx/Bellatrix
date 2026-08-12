<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  MateriaPrima.php
 * ==========================================================================
 *  Representa un insumo de cocina (harina, leche, azúcar, etc.), mapeado
 *  desde la tabla `materia_prima`.
 *
 *  Esta clase no aparece con ese nombre exacto en el Diagrama de Clases,
 *  pero es INDISPENSABLE porque:
 *    - CU019 (Gestionar Materias Primas) pide administrar estos insumos
 *      y definir un stock mínimo para alertas.
 *    - CU008 (Actualización automatizada de stock) necesita descontar
 *      materia prima según la "receta" de cada producto vendido.
 *  Trabaja de la mano con Receta.php y AlertaStock.php.
 * ==========================================================================
 */
class MateriaPrima
{
    public ?int $idMateria;
    public string $nombre;
    public ?string $unidadMedida; // ej: "kg", "litros", "unidades"
    public float $stockActual;
    public float $stockMinimo;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idMateria    = $datos['id_materia']    ?? null;
        $this->nombre       = $datos['nombre']        ?? '';
        $this->unidadMedida = $datos['unidad_medida'] ?? null;
        $this->stockActual  = isset($datos['stock_actual']) ? (float)$datos['stock_actual'] : 0.0;
        $this->stockMinimo  = isset($datos['stock_minimo']) ? (float)$datos['stock_minimo'] : 0.0;
    }

    /**
     * descontarStock($cantidad)
     * ------------------------------------------------------------
     * Resta una cantidad del stock actual del insumo. Se usa cuando
     * se vende un producto que "consume" esta materia prima según
     * su receta (ver Receta.php -> descontarInsumosPorVenta()).
     *
     * Si el nuevo stock queda por debajo del stock mínimo, devuelve
     * información para que el controlador (en la Fase 3) pueda
     * disparar una alerta (tabla `alerta_stock`).
     */
    public function descontarStock(float $cantidad): bool
    {
        $nuevoStock = $this->stockActual - $cantidad;

        if ($nuevoStock < 0) {
            throw new Exception("Stock insuficiente de materia prima '{$this->nombre}'.");
        }

        $sql = "UPDATE materia_prima SET stock_actual = :stock WHERE id_materia = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':stock', $nuevoStock);
        $stmt->bindValue(':id', $this->idMateria, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->stockActual = $nuevoStock;
        }

        return $ok;
    }

    /**
     * aumentarStock($cantidad)
     * Suma cantidad al stock (por ejemplo, al registrar una compra
     * de insumos a un proveedor).
     */
    public function aumentarStock(float $cantidad): bool
    {
        $nuevoStock = $this->stockActual + $cantidad;

        $sql = "UPDATE materia_prima SET stock_actual = :stock WHERE id_materia = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':stock', $nuevoStock);
        $stmt->bindValue(':id', $this->idMateria, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->stockActual = $nuevoStock;
        }

        return $ok;
    }

    /**
     * tieneStockBajo()
     * Devuelve true si el stock actual ya llegó (o bajó) del umbral
     * mínimo definido para este insumo. Este booleano es el que usará
     * el futuro controlador de alertas para decidir si debe crear
     * una fila nueva en `alerta_stock`.
     */
    public function tieneStockBajo(): bool
    {
        return $this->stockActual <= $this->stockMinimo;
    }

    // =================================================================
    //  CRUD
    // =================================================================

    public function crear(): int
    {
        $sql = "INSERT INTO materia_prima (nombre, unidad_medida, stock_actual, stock_minimo)
                VALUES (:nombre, :unidad_medida, :stock_actual, :stock_minimo)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':nombre', $this->nombre);
        $stmt->bindValue(':unidad_medida', $this->unidadMedida);
        $stmt->bindValue(':stock_actual', $this->stockActual);
        $stmt->bindValue(':stock_minimo', $this->stockMinimo);
        $stmt->execute();

        $this->idMateria = (int)$this->pdo->lastInsertId();
        return $this->idMateria;
    }

    public function actualizar(): bool
    {
        $sql = "UPDATE materia_prima SET
                    nombre = :nombre,
                    unidad_medida = :unidad_medida,
                    stock_actual = :stock_actual,
                    stock_minimo = :stock_minimo
                WHERE id_materia = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':nombre', $this->nombre);
        $stmt->bindValue(':unidad_medida', $this->unidadMedida);
        $stmt->bindValue(':stock_actual', $this->stockActual);
        $stmt->bindValue(':stock_minimo', $this->stockMinimo);
        $stmt->bindValue(':id', $this->idMateria, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function eliminar(): bool
    {
        $sql = "DELETE FROM materia_prima WHERE id_materia = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idMateria, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public static function obtenerPorId(int $id): ?MateriaPrima
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM materia_prima WHERE id_materia = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new MateriaPrima($fila) : null;
    }

    public static function listarTodas(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM materia_prima ORDER BY nombre ASC");
        return array_map(fn($fila) => new MateriaPrima($fila), $stmt->fetchAll());
    }

    /**
     * listarConStockBajo()
     * Trae directamente de la BD (con SQL) solo los insumos cuyo
     * stock actual ya está en el umbral mínimo o por debajo.
     * Útil para el panel de alertas del Administrador.
     */
    public static function listarConStockBajo(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM materia_prima WHERE stock_actual <= stock_minimo ORDER BY nombre ASC");
        return array_map(fn($fila) => new MateriaPrima($fila), $stmt->fetchAll());
    }
}
