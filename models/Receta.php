<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/MateriaPrima.php';

/**
 * ==========================================================================
 *  Receta.php
 * ==========================================================================
 *  Mapea la tabla `recetas`, la cual es una tabla "puente" que dice:
 *  "para hacer 1 unidad del producto X, se necesitan Y cantidad del
 *  insumo (materia prima) Z".
 *
 *  Ejemplo: Producto "Torta de chocolate" -> necesita 0.5 kg de harina,
 *  0.2 litros de leche, 3 huevos, etc. Cada una de esas líneas es UNA
 *  fila en `recetas`.
 *
 *  Esta tabla es el corazón del CU008: cuando se vende un producto,
 *  el sistema debe leer TODAS sus recetas y descontar proporcionalmente
 *  el stock de cada materia prima involucrada.
 * ==========================================================================
 */
class Receta
{
    public ?int $idReceta;
    public ?int $idProducto;
    public ?int $idMateria;
    public ?float $cantidad; // Cantidad de materia prima necesaria por 1 unidad de producto

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idReceta   = $datos['id_receta']   ?? null;
        $this->idProducto = $datos['id_producto'] ?? null;
        $this->idMateria  = $datos['id_materia']  ?? null;
        $this->cantidad   = isset($datos['cantidad']) ? (float)$datos['cantidad'] : null;
    }

    // =================================================================
    //  CRUD básico
    // =================================================================

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

    /**
     * obtenerPorId($id)
     * ------------------------------------------------------------
     * Añadido en la Fase 3: lo usa InventarioController para poder
     * cargar una línea de receta puntual antes de eliminarla
     * (Receta::eliminar() es un método de instancia, así que primero
     * hay que tener el objeto cargado con sus datos).
     */
    public static function obtenerPorId(int $id): ?Receta
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM recetas WHERE id_receta = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Receta($fila) : null;
    }

    /**
     * obtenerPorProducto($idProducto)
     * Devuelve TODAS las líneas de receta (insumos necesarios) de
     * un producto específico. Esta es la consulta clave que usará
     * el futuro Controlador de Ventas para el CU008.
     */
    public static function obtenerPorProducto(int $idProducto): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM recetas WHERE id_producto = :id");
        $stmt->bindValue(':id', $idProducto, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new Receta($fila), $stmt->fetchAll());
    }

    /**
     * descontarInsumosPorVenta($idProducto, $cantidadVendida)
     * ------------------------------------------------------------
     * MÉTODO CLAVE PARA EL CU008.
     *
     * Cuando se vende, por ejemplo, 3 unidades del producto "Torta de
     * chocolate", este método:
     *   1. Busca todas las recetas de ese producto (sus insumos).
     *   2. Por cada insumo, calcula: cantidad_necesaria_por_unidad * 3.
     *   3. Descuenta esa cantidad del stock de la materia prima
     *      correspondiente (usando MateriaPrima::descontarStock()).
     *
     * Este método NO se encarga de actualizar el stock del PRODUCTO
     * terminado (eso lo hace Producto::actualizarStock() por separado).
     * Aquí solo se maneja el descuento "proporcional" de la materia
     * prima consumida, tal como pide el CU008.
     *
     * @return array Lista de materias primas afectadas (útil para logs/alertas).
     */
    public static function descontarInsumosPorVenta(int $idProducto, int $cantidadVendida): array
    {
        $recetas = self::obtenerPorProducto($idProducto);
        $materiasAfectadas = [];

        foreach ($recetas as $receta) {
            if ($receta->idMateria === null || $receta->cantidad === null) {
                continue; // Fila de receta incompleta, se ignora por seguridad.
            }

            $materia = MateriaPrima::obtenerPorId($receta->idMateria);
            if ($materia === null) {
                continue; // La materia prima referenciada ya no existe.
            }

            // Regla de negocio central del CU008: descuento PROPORCIONAL.
            $cantidadADescontar = $receta->cantidad * $cantidadVendida;
            $materia->descontarStock($cantidadADescontar);

            $materiasAfectadas[] = $materia;
        }

        return $materiasAfectadas;
    }
}
