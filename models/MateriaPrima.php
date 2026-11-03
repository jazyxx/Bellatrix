<?php
require_once __DIR__ . '/../config/Database.php';

class MateriaPrima
{
    public ?int $idMateria;
    public string $nombre;
    public ?string $unidadMedida; 
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
                
            require_once __DIR__ . '/AlertaStock.php';
            AlertaStock::generarSiAplica($this);
        }

        return $ok;
    }

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

    public function tieneStockBajo(): bool
    {
        return $this->stockActual <= $this->stockMinimo;
    }

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

                $ok = $stmt->execute();

        if ($ok) {
            require_once __DIR__ . '/AlertaStock.php';

                        if ($this->tieneStockBajo()) {
                
                AlertaStock::generarSiAplica($this);
            } else {
                
                AlertaStock::desactivarPorMateria($this->idMateria);
            }
        }

        return $ok;
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

    public static function listarConStockBajo(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM materia_prima WHERE stock_actual <= stock_minimo ORDER BY nombre ASC");
        return array_map(fn($fila) => new MateriaPrima($fila), $stmt->fetchAll());
    }
}
