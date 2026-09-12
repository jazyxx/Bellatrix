<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  Producto.php
 * ==========================================================================
 *  Representa un registro de la tabla `productos`.
 *  Corresponde a la clase "Producto" del Diagrama de Clases.
 *
 *  Cada PROPIEDAD de esta clase es una COLUMNA de la tabla `productos`.
 *  Cada MÉTODO "de negocio" (actualizarStock, modificarPrecio, etc.) es
 *  una acción que el Diagrama de Clases exige que el Producto sepa hacer.
 *  Además, se agregan métodos CRUD (Crear/Leer/Actualizar/Eliminar) que
 *  son el "puente" real hacia la base de datos usando PDO.
 * ==========================================================================
 */
class Producto
{
    // ---------------------------------------------------------------
    // PROPIEDADES -> coinciden 1 a 1 con las columnas de `productos`
    // ---------------------------------------------------------------
    public ?int $idProducto;
    public string $nombre;
    public ?string $descripcion;
    public ?string $tipo;
    public string $unidadNegocio;   // ENUM: 'Pastelería' | 'Heladería'
    public float $precio;
    public int $stock;
    /** Solo indica SI existe una foto guardada — nunca trae los bytes
     *  (evita cargar imágenes completas en cada listado del catálogo). */
    public bool $tieneFoto;
    /** Bytes crudos de una foto NUEVA a guardar. Se llena desde el
     *  controlador (tras decodificar el base64 que manda el formulario)
     *  justo antes de llamar a crear()/actualizar() — nunca se llena
     *  leyendo de la base de datos. */
    public ?string $fotoBinaria = null;
    public bool $disponible;

    /** Conexión PDO reutilizada en todos los métodos de esta clase. */
    private PDO $pdo;

    /**
     * Constructor.
     * Recibe los datos del producto como un arreglo asociativo (por
     * ejemplo, una fila que vino de la base de datos) y los asigna
     * a las propiedades de PHP. Todos los parámetros son opcionales
     * para poder hacer "new Producto()" vacío y llenarlo a mano.
     */
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
        // 'tiene_foto' llega calculado desde el SQL como (foto IS NOT NULL) — ver más abajo.
        $this->tieneFoto    = !empty($datos['tiene_foto']);
        // MySQL guarda booleanos como 0/1 (tinyint), aquí los convertimos a true/false de PHP.
        $this->disponible   = isset($datos['disponible']) ? (bool)$datos['disponible'] : true;
    }

    // =================================================================
    //  MÉTODOS DE NEGOCIO (definidos en el Diagrama de Clases)
    // =================================================================

    /**
     * actualizarStock(cantidad)
     * ------------------------------------------------------------
     * Suma (o resta, si $cantidad es negativa) unidades al stock
     * actual del producto y guarda el cambio en la base de datos.
     * Este método es la pieza clave que usará el CU008 (descuento
     * automático de stock al finalizar una venta).
     *
     * @param int $cantidad Positivo para sumar, negativo para restar.
     * @return bool true si se actualizó correctamente.
     */
    public function actualizarStock(int $cantidad): bool
    {
        $nuevoStock = $this->stock + $cantidad;

        // Regla de negocio: el stock nunca puede quedar en negativo.
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
            // Si el stock llegó a 0, lo marcamos automáticamente como agotado.
            if ($this->stock === 0) {
                $this->marcarAgotado();
            }
        }

        return $ok;
    }

    /**
     * modificarPrecio(nuevoPrecio)
     * ------------------------------------------------------------
     * Cambia el precio de venta del producto.
     */
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

    /**
     * obtenerDatos()
     * ------------------------------------------------------------
     * Devuelve todas las propiedades del producto como un arreglo
     * asociativo. Muy útil más adelante para transformarlo a JSON
     * y enviarlo al frontend con la Fetch API.
     */
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

    /**
     * marcarAgotado()
     * ------------------------------------------------------------
     * Marca el producto como NO disponible (disponible = 0).
     * El Catálogo público (CU011) usa esta bandera para mostrar
     * la etiqueta "Agotado" en la tienda en línea.
     */
    public function marcarAgotado(): void
    {
        $sql = "UPDATE productos SET disponible = 0 WHERE id_producto = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idProducto, PDO::PARAM_INT);
        $stmt->execute();

        $this->disponible = false;
    }

    // =================================================================
    //  MÉTODOS CRUD (conexión directa con la tabla `productos`)
    // =================================================================

    /**
     * crear()
     * Inserta este producto (los datos que están en las propiedades
     * del objeto) como una fila nueva en la tabla `productos`.
     *
     * @return int El id_producto recién generado por MySQL (AUTO_INCREMENT).
     */
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
        // PARAM_LOB es el tipo correcto para escribir en una columna BLOB.
        $stmt->bindValue(':foto', $this->fotoBinaria, $this->fotoBinaria !== null ? PDO::PARAM_LOB : PDO::PARAM_NULL);
        $stmt->bindValue(':disponible', $this->disponible ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        $this->idProducto = (int)$this->pdo->lastInsertId();
        $this->tieneFoto = $this->fotoBinaria !== null;
        return $this->idProducto;
    }

    /**
     * actualizar()
     * Guarda en la base de datos TODOS los cambios hechos sobre las
     * propiedades del objeto (a diferencia de actualizarStock() o
     * modificarPrecio(), que solo tocan un campo puntual).
     *
     * La columna `foto` es la ÚNICA que se actualiza de forma
     * condicional: si el Administrador no seleccionó una foto nueva
     * ($fotoBinaria sigue en null), la foto que ya estaba guardada NO
     * se toca — de lo contrario, cada vez que se editara el precio o
     * el stock se borraría la imagen sin querer.
     */
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

    /**
     * eliminar()
     * Elimina este producto de la base de datos.
     */
    public function eliminar(): bool
    {
        $sql = "DELETE FROM productos WHERE id_producto = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idProducto, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // =================================================================
    //  MÉTODOS ESTÁTICOS DE CONSULTA
    //  (Se llaman así: Producto::obtenerPorId(5), sin necesidad de
    //   tener antes un objeto Producto creado)
    // =================================================================

    /**
     * COLUMNAS_SIN_FOTO
     * ------------------------------------------------------------
     * Lista de columnas reutilizada en TODAS las consultas de abajo.
     * A propósito nunca seleccionamos `foto` completa aquí: en vez de
     * eso pedimos "(foto IS NOT NULL) AS tiene_foto" — así un listado
     * de 50 productos no arrastra 50 imágenes completas a PHP solo
     * para mostrar una tabla o una grilla. Los bytes reales de la foto
     * se piden aparte, solo cuando hacen falta, con
     * obtenerFotoBinaria().
     */
    private const COLUMNAS_SIN_FOTO = "id_producto, nombre, descripcion, tipo, unidad_negocio,
                                        precio, stock, disponible, (foto IS NOT NULL) AS tiene_foto";

    /**
     * obtenerPorId($id)
     * Busca un producto por su llave primaria y devuelve un objeto
     * Producto ya "armado", o null si no existe.
     */
    public static function obtenerPorId(int $id): ?Producto
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT " . self::COLUMNAS_SIN_FOTO . " FROM productos WHERE id_producto = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Producto($fila) : null;
    }

    /**
     * obtenerFotoBinaria($id)
     * ------------------------------------------------------------
     * ÚNICO método de esta clase que trae los bytes reales de la foto.
     * Lo usa InventarioController::fotoProducto() para servir la
     * imagen como un archivo binario (no como JSON). Devuelve null si
     * el producto no existe o no tiene foto guardada.
     */
    public static function obtenerFotoBinaria(int $id): ?string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT foto FROM productos WHERE id_producto = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $foto = $stmt->fetchColumn();
        return ($foto !== false && $foto !== null) ? $foto : null;
    }

    /**
     * listarTodos()
     * Devuelve un arreglo con TODOS los productos de la tabla,
     * cada uno ya convertido en un objeto Producto.
     *
     * @param bool $soloDisponibles Si es true, trae solo disponible = 1
     *             (útil para el catálogo público, CU011).
     */
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

        // array_map convierte cada fila (arreglo) en un objeto Producto.
        return array_map(fn($fila) => new Producto($fila), $filas);
    }

    /**
     * listarPorUnidadNegocio($unidad)
     * Filtra productos por 'Pastelería' o 'Heladería'.
     */
    public static function listarPorUnidadNegocio(string $unidad): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT " . self::COLUMNAS_SIN_FOTO . " FROM productos WHERE unidad_negocio = :unidad ORDER BY nombre ASC");
        $stmt->bindValue(':unidad', $unidad);
        $stmt->execute();

        return array_map(fn($fila) => new Producto($fila), $stmt->fetchAll());
    }

    /**
     * buscarPorNombre($texto)
     * Búsqueda tipo "LIKE %texto%" para la barra de búsqueda del catálogo.
     */
    public static function buscarPorNombre(string $texto): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT " . self::COLUMNAS_SIN_FOTO . " FROM productos WHERE nombre LIKE :texto ORDER BY nombre ASC");
        $stmt->bindValue(':texto', '%' . $texto . '%');
        $stmt->execute();

        return array_map(fn($fila) => new Producto($fila), $stmt->fetchAll());
    }
}
