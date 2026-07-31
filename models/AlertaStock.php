<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/MateriaPrima.php';

/**
 * ==========================================================================
 *  AlertaStock.php
 * ==========================================================================
 *  Mapea la tabla `alerta_stock`. Guarda un registro cada vez que un
 *  producto o una materia prima cruza su umbral mínimo de existencias,
 *  para que el Administrador pueda ver un panel de "Alertas" (CU019).
 * ==========================================================================
 */
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

    /**
     * crear()
     * Registra una nueva alerta en la base de datos. Se usará desde
     * el controlador de inventario cuando MateriaPrima::tieneStockBajo()
     * devuelva true.
     */
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

    /**
     * marcarComoAtendida()
     * El Administrador la usa cuando ya resolvió la alerta (por
     * ejemplo, ya compró más harina).
     */
    public function marcarComoAtendida(): bool
    {
        $sql = "UPDATE alerta_stock SET atendida = 1, activa = 0 WHERE id_alerta = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idAlerta, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->atendida = true;
            $this->activa = false;
        }

        return $ok;
    }

    /**
     * obtenerPorId($id)
     * Añadido en la Fase 3: lo usa InventarioController para cargar
     * una alerta puntual antes de marcarla como atendida.
     */
    public static function obtenerPorId(int $id): ?AlertaStock
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM alerta_stock WHERE id_alerta = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new AlertaStock($fila) : null;
    }

    /**
     * obtenerActivaPorMateria($idMateria)
     * ------------------------------------------------------------
     * Añadido en la Fase 3: antes de crear una alerta nueva por
     * stock bajo, InventarioController usa este método para revisar
     * si YA existe una alerta activa para esa misma materia prima,
     * y así evitar generar alertas duplicadas cada vez que se
     * consulta el inventario.
     */
    public static function obtenerActivaPorMateria(int $idMateria): ?AlertaStock
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM alerta_stock WHERE id_materia = :materia AND activa = 1 AND atendida = 0 LIMIT 1");
        $stmt->bindValue(':materia', $idMateria, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new AlertaStock($fila) : null;
    }

    /**
     * generarSiAplica($materia)
     * ------------------------------------------------------------
     * Añadido en la Fase 4. Centraliza la regla de negocio de "¿hay
     * que generar una alerta para este insumo?" en un solo lugar del
     * sistema, para que la puedan reutilizar TANTO el ajuste manual
     * de stock (InventarioController, Fase 3) COMO el descuento
     * automático que ocurre al finalizar una venta (VentaController,
     * CU008, Fase 4) — antes esta lógica solo vivía duplicada dentro
     * de InventarioController; ahora vive una única vez aquí.
     *
     * Revisa si el insumo quedó en stock bajo y, si no existe ya una
     * alerta activa para él, crea una nueva. Si no hace falta crear
     * ninguna, devuelve null (para que el que llama pueda ignorarlo).
     */
    public static function generarSiAplica(MateriaPrima $materia): ?AlertaStock
    {
        if (!$materia->tieneStockBajo()) {
            return null;
        }

        $alertaExistente = self::obtenerActivaPorMateria($materia->idMateria);
        if ($alertaExistente !== null) {
            return null; // Ya hay una alerta activa, no se duplica.
        }

        $nuevaAlerta = new AlertaStock([
            'id_materia' => $materia->idMateria,
            'umbral'     => $materia->stockMinimo,
        ]);
        $nuevaAlerta->crear();

        return $nuevaAlerta;
    }

    /**
     * listarActivas()
     * Devuelve todas las alertas que siguen activas y sin atender,
     * para mostrarlas en el panel de administración.
     */
    public static function listarActivas(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM alerta_stock WHERE activa = 1 AND atendida = 0 ORDER BY fecha_generada DESC");
        return array_map(fn($fila) => new AlertaStock($fila), $stmt->fetchAll());
    }
}
