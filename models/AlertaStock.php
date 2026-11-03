<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/MateriaPrima.php';

class AlertaStock
{
    public ?int $idAlerta;
    public ?int $idMateria;
    public ?int $idProducto;
    public float $umbral;
    public bool $activa;
    public bool $atendida;
    public ?string $fechaGenerada;
    public ?string $creadoEn;
    public ?string $nombreMateria = null;
    public ?string $mensaje = null;
    public ?string $estado = null;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idAlerta      = $datos['id_alerta']      ?? null;
        $this->idMateria     = $datos['id_materia']     ?? null;
        $this->idProducto    = $datos['id_producto']    ?? null;
        $this->umbral        = isset($datos['umbral']) ? (float)$datos['umbral'] : 0.0;
        $this->activa        = isset($datos['activa']) ? (bool)$datos['activa'] : true;
        $this->atendida      = isset($datos['atendida']) ? (bool)$datos['atendida'] : false;
        $this->fechaGenerada = $datos['fecha_generada'] ?? null;
        $this->creadoEn      = $datos['creado_en']      ?? null;
    }

    public function crear(): int
    {
        $sql = "INSERT INTO alerta_stock (id_materia, id_producto, umbral, activa, atendida, fecha_generada)
                VALUES (:id_materia, :id_producto, :umbral, :activa, :atendida, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id_materia', $this->idMateria, PDO::PARAM_INT);
        $stmt->bindValue(':id_producto', $this->idProducto, PDO::PARAM_INT);
        $stmt->bindValue(':umbral', $this->umbral);
        $stmt->bindValue(':activa', $this->activa ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':atendida', $this->atendida ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        $this->idAlerta = (int)$this->pdo->lastInsertId();
        return $this->idAlerta;
    }

    public static function obtenerActivaPorMateria(int $idMateria): ?AlertaStock
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM alerta_stock WHERE id_materia = :materia AND activa = 1 AND atendida = 0 LIMIT 1");
        $stmt->bindValue(':materia', $idMateria, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new AlertaStock($fila) : null;
    }

    public static function generarSiAplica(MateriaPrima $materia): ?AlertaStock
    {
        if (!$materia->tieneStockBajo()) {
            return null;
        }

        $alertaExistente = self::obtenerActivaPorMateria($materia->idMateria);
        if ($alertaExistente !== null) {
            return null; 
        }

        $nuevaAlerta = new AlertaStock([
            'id_materia' => $materia->idMateria,
            'umbral'     => $materia->stockMinimo,
        ]);
        $nuevaAlerta->crear();

        return $nuevaAlerta;
    }

    public static function listarActivas(): array
    {
        $pdo = Database::getConnection();
           
        $sql = "SELECT a.*, m.nombre AS nombre_materia 
                FROM alerta_stock a
                INNER JOIN materia_prima m ON a.id_materia = m.id_materia
                WHERE a.activa = 1 AND a.atendida = 0 
                ORDER BY a.fecha_generada DESC";

                        $stmt = $pdo->query($sql);

                $alertas = [];
        foreach ($stmt->fetchAll() as $fila) {
            $alerta = new AlertaStock($fila);
                    
            $alerta->nombreMateria = $fila['nombre_materia'] ?? 'Insumo desconocido';
            $alerta->estado = 'Crítico'; 
            $alerta->mensaje = 'Stock por debajo del mínimo (' . $alerta->umbral . ')';

                        $alertas[] = $alerta;
        }

                return $alertas;
    }

    public static function desactivarPorMateria(int $idMateria): void
    {
        $pdo = Database::getConnection();
        $sql = "UPDATE alerta_stock 
                SET activa = 0, atendida = 1 
                WHERE id_materia = :id AND atendida = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $idMateria, PDO::PARAM_INT);
        $stmt->execute();
    }
}
