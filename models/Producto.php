<?php
require_once __DIR__ . '/../config/Database.php';

class Producto
{
  
    public ?int $idProducto;
    public string $nombre;
    public ?string $descripcion;
    public ?string $tipo;
    public string $unidadNegocio;   
    public float $precio;
    public int $stock;
  
    public bool $tieneFoto;
    
    public ?string $fotoBinaria = null;
    public bool $disponible;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idProducto   = $datos['id_producto']   ?? null;
        $this->nombre       = $datos['nombre']        ?? '';
        $this->descripcion  = $datos['descripcion']   ?? null;
        $this->tipo         = $datos['tipo']          ?? null;
        $this->unidadNegocio = $datos['unidad_negocio'] ?? 'Pastelería';
        $this->precio       = isset($datos['precio']) ? (float)$datos['precio'] : 0.0;
        $this->stock        = isset($datos['stock']) ? (int)$datos['stock'] : 0;
        
        $this->tieneFoto    = !empty($datos['tiene_foto']);
        
        $this->disponible   = isset($datos['disponible']) ? (bool)$datos['disponible'] : true;
    }

    public function actualizarStock(int $cantidad): bool
    {
        $nuevoStock = $this->stock + $cantidad;

        
        if ($nuevoStock < 0) {
            throw new Exception("Stock insuficiente para el producto '{$this->nombre}'. Stock actual: {$this->stock}, se intentó restar: " . abs($cantidad));
        }

        $sql = "UPDATE productos SET stock = :stock WHERE id_producto = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':stock', $nuevoStock, PDO::PARAM_INT);
        $stmt->bindValue(':id', $this->idProducto, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->stock = $nuevoStock;
            
            if ($this->stock === 0) {
                $this->marcarAgotado();
            }
        }

        return $ok;
    }

    public function modificarPrecio(float $nuevoPrecio): bool
    {
        if ($nuevoPrecio < 0) {
            throw new Exception("El precio no puede ser negativo.");
        }

        $sql = "UPDATE productos SET precio = :precio WHERE id_producto = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':precio', $nuevoPrecio);
        $stmt->bindValue(':id', $this->idProducto, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->precio = $nuevoPrecio;
        }

        return $ok;
    }

    public function obtenerDatos(): array
    {
        return [
            'id_producto'    => $this->idProducto,
            'nombre'         => $this->nombre,
            'descripcion'    => $this->descripcion,
            'tipo'           => $this->tipo,
            'unidad_negocio' => $this->unidadNegocio,
            'precio'         => $this->precio,
            'stock'          => $this->stock,
            'tiene_foto'     => $this->tieneFoto,
            'disponible'     => $this->disponible,
        ];
    }

    public function marcarAgotado(): void
    {
        $sql = "UPDATE productos SET disponible = 0 WHERE id_producto = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idProducto, PDO::PARAM_INT);
        $stmt->execute();

        $this->disponible = false;
    }

    public function crear(): int
    {
        $sql = "INSERT INTO productos
                    (nombre, descripcion, tipo, unidad_negocio, precio, stock, foto, disponible)
                VALUES
                    (:nombre, :descripcion, :tipo, :unidad_negocio, :precio, :stock, :foto, :disponible)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':nombre', $this->nombre);
        $stmt->bindValue(':descripcion', $this->descripcion);
        $stmt->bindValue(':tipo', $this->tipo);
        $stmt->bindValue(':unidad_negocio', $this->unidadNegocio);
        $stmt->bindValue(':precio', $this->precio);
        $stmt->bindValue(':stock', $this->stock, PDO::PARAM_INT);
        
        $stmt->bindValue(':foto', $this->fotoBinaria, $this->fotoBinaria !== null ? PDO::PARAM_LOB : PDO::PARAM_NULL);
        $stmt->bindValue(':disponible', $this->disponible ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        $this->idProducto = (int)$this->pdo->lastInsertId();
        $this->tieneFoto = $this->fotoBinaria !== null;
        return $this->idProducto;
    }

    
    public function actualizar(): bool
    {
        $sql = "UPDATE productos SET
                    nombre = :nombre,
                    descripcion = :descripcion,
                    tipo = :tipo,
                    unidad_negocio = :unidad_negocio,
                    precio = :precio,
                    stock = :stock,
                    disponible = :disponible";

        if ($this->fotoBinaria !== null) {
            $sql .= ", foto = :foto";
        }

        $sql .= " WHERE id_producto = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':nombre', $this->nombre);
        $stmt->bindValue(':descripcion', $this->descripcion);
        $stmt->bindValue(':tipo', $this->tipo);
        $stmt->bindValue(':unidad_negocio', $this->unidadNegocio);
        $stmt->bindValue(':precio', $this->precio);
        $stmt->bindValue(':stock', $this->stock, PDO::PARAM_INT);
        $stmt->bindValue(':disponible', $this->disponible ? 1 : 0, PDO::PARAM_INT);
        if ($this->fotoBinaria !== null) {
            $stmt->bindValue(':foto', $this->fotoBinaria, PDO::PARAM_LOB);
        }
        $stmt->bindValue(':id', $this->idProducto, PDO::PARAM_INT);

        $ok = $stmt->execute();
        if ($ok && $this->fotoBinaria !== null) {
            $this->tieneFoto = true;
        }
        return $ok;
    }

    public function eliminar(): bool
{
    $sql = "UPDATE productos SET disponible = 0, stock = 0 WHERE id_producto = :id";
    $stmt = $this->pdo->prepare($sql);
    $stmt->bindValue(':id', $this->idProducto, PDO::PARAM_INT);
    $ok = $stmt->execute();

    if ($ok) {
        $this->disponible = false;
        $this->stock = 0;
    }
    return $ok;
}

   
    private const COLUMNAS_SIN_FOTO = "id_producto, nombre, descripcion, tipo, unidad_negocio,
                                        precio, stock, disponible, (foto IS NOT NULL) AS tiene_foto";

    

    public static function obtenerPorId(int $id): ?Producto
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT " . self::COLUMNAS_SIN_FOTO . " FROM productos WHERE id_producto = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Producto($fila) : null;
    }

    public static function obtenerFotoBinaria(int $id): ?string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT foto FROM productos WHERE id_producto = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $foto = $stmt->fetchColumn();
        return ($foto !== false && $foto !== null) ? $foto : null;
    }

    public static function listarTodos(bool $soloDisponibles = false): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT " . self::COLUMNAS_SIN_FOTO . " FROM productos";
        if ($soloDisponibles) {
            $sql .= " WHERE disponible = 1";
        }
        $sql .= " ORDER BY nombre ASC";

        $stmt = $pdo->query($sql);
        $filas = $stmt->fetchAll();

        
        return array_map(fn($fila) => new Producto($fila), $filas);
    }

    public static function listarPorUnidadNegocio(string $unidad, bool $soloDisponibles = false): array
{
    $pdo = Database::getConnection();

    $sql = "SELECT " . self::COLUMNAS_SIN_FOTO . " FROM productos WHERE unidad_negocio = :unidad";
    if ($soloDisponibles) {
        $sql .= " AND disponible = 1";
    }
    $sql .= " ORDER BY nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':unidad', $unidad);
    $stmt->execute();

    return array_map(fn($fila) => new Producto($fila), $stmt->fetchAll());
}

    public static function buscarPorNombre(string $texto, bool $soloDisponibles = false): array
{
    $pdo = Database::getConnection();

    $sql = "SELECT " . self::COLUMNAS_SIN_FOTO . " FROM productos WHERE nombre LIKE :texto";
    if ($soloDisponibles) {
        $sql .= " AND disponible = 1";
    }
    $sql .= " ORDER BY nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':texto', '%' . $texto . '%');
    $stmt->execute();

    return array_map(fn($fila) => new Producto($fila), $stmt->fetchAll());
}
}
