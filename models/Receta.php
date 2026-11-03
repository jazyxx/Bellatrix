<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/MateriaPrima.php';

class Receta implements JsonSerializable
{
    public ?int $idReceta;
    public ?int $idProducto;
    public ?int $idMateria;
    public ?float $cantidad; 

    
    public ?string $nombreMateria;
    public ?string $unidadMedida;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idReceta   = $datos['id_receta']   ?? null;
        $this->idProducto = $datos['id_producto'] ?? null;
        $this->idMateria  = $datos['id_materia']  ?? null;
        $this->cantidad   = isset($datos['cantidad']) ? (float)$datos['cantidad'] : null;

                $this->nombreMateria = $datos['nombre_materia'] ?? null;
        $this->unidadMedida  = $datos['unidad_medida'] ?? null;
    }

    
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'id_receta'      => $this->idReceta,
            'id_producto'    => $this->idProducto,
            'id_materia'     => $this->idMateria,
            'cantidad'       => $this->cantidad,
            'nombre_materia' => $this->nombreMateria,
            'unidad_medida'  => $this->unidadMedida
        ];
    }

    public function crear(): int
    {
        $sql = "INSERT INTO recetas (id_producto, id_materia, cantidad)
                VALUES (:id_producto, :id_materia, :cantidad)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id_producto', $this->idProducto, PDO::PARAM_INT);
        $stmt->bindValue(':id_materia', $this->idMateria, PDO::PARAM_INT);
        $stmt->bindValue(':cantidad', $this->cantidad);
        $stmt->execute();

        $this->idReceta = (int)$this->pdo->lastInsertId();
        return $this->idReceta;
    }

    public function actualizar(): bool
    {
        $sql = "UPDATE recetas SET id_producto = :id_producto, id_materia = :id_materia, cantidad = :cantidad
                WHERE id_receta = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id_producto', $this->idProducto, PDO::PARAM_INT);
        $stmt->bindValue(':id_materia', $this->idMateria, PDO::PARAM_INT);
        $stmt->bindValue(':cantidad', $this->cantidad);
        $stmt->bindValue(':id', $this->idReceta, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function eliminar(): bool
    {
        $sql = "DELETE FROM recetas WHERE id_receta = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idReceta, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public static function obtenerPorId(int $id): ?Receta
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM recetas WHERE id_receta = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Receta($fila) : null;
    }

    public static function obtenerPorProducto(int $idProducto): array
    {
        $pdo = Database::getConnection();
        try {
            
            $stmt = $pdo->prepare("
                SELECT r.*, m.nombre AS nombre_materia, m.unidad_medida 
                FROM recetas r
                INNER JOIN materia_prima m ON r.id_materia = m.id_materia
                WHERE r.id_producto = :id
            ");
            $stmt->bindValue(':id', $idProducto, PDO::PARAM_INT);
            $stmt->execute();

            return array_map(fn($fila) => new Receta($fila), $stmt->fetchAll());
        } catch (Exception $e) {
            
            error_log("Error SQL en recetas: " . $e->getMessage());
            return [];
        }
    }

    public static function descontarInsumosPorVenta(int $idProducto, int $cantidadVendida): array
    {
        $recetas = self::obtenerPorProducto($idProducto);
        $materiasAfectadas = [];

        foreach ($recetas as $receta) {
            if ($receta->idMateria === null || $receta->cantidad === null) {
                continue;
            }

            $materia = MateriaPrima::obtenerPorId($receta->idMateria);
            if ($materia === null) {
                continue;
            }

            $cantidadADescontar = $receta->cantidad * $cantidadVendida;
            $materia->descontarStock($cantidadADescontar);

            $materiasAfectadas[] = $materia;
        }

        return $materiasAfectadas;
    }
}