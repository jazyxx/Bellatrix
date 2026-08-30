<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  Empleado.php
 * ==========================================================================
 *  Mapea la tabla `empleado`. Es la clase BASE (padre) de la que heredan
 *  Administrador y Cajero, exactamente como lo indica tu Diagrama de
 *  Clases con la flecha de herencia:
 *
 *      Empleado <|-- Administrador
 *      Empleado <|-- Cajero
 *
 *  En PHP esto se traduce en que Administrador.php y Cajero.php usan
 *  "extends Empleado" y automáticamente heredan todas las propiedades
 *  y métodos que se definen aquí (nombre, usuario, validarDatos, etc.).
 * ==========================================================================
 */
class Empleado
{
    public ?int $idEmpleado;
    public string $nombre;
    public ?string $apellido;
    public string $usuario;
    public string $correo;
    protected string $contrasena; // protected: solo esta clase y sus hijas la tocan directamente.
    public string $rol; // ENUM: 'Administrador' | 'Cajero'
    public bool $activo;
    public ?string $telefono;
    public ?float $salario;
    public ?string $fechaContratacion;

    /**
     * protected: PDO también se hereda a Administrador y Cajero.
     */
    protected PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idEmpleado        = $datos['id_empleado']        ?? null;
        $this->nombre            = $datos['nombre']             ?? '';
        $this->apellido          = $datos['apellido']           ?? null;
        $this->usuario           = $datos['usuario']            ?? '';
        $this->correo            = $datos['correo']             ?? '';
        $this->contrasena        = $datos['contraseña']         ?? '';
        $this->rol               = $datos['rol']                ?? '';
        $this->activo            = isset($datos['activo']) ? (bool)$datos['activo'] : true;
        $this->telefono          = $datos['telefono']           ?? null;
        $this->salario           = isset($datos['salario']) ? (float)$datos['salario'] : null;
        $this->fechaContratacion = $datos['fecha_contratacion'] ?? null;
    }

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * validarDatos()
     * ------------------------------------------------------------
     * Verifica que los datos mínimos obligatorios del empleado estén
     * completos antes de guardarlo (nombre, usuario, correo, rol).
     * Devuelve un arreglo de errores; si está vacío, los datos son válidos.
     */
    public function validarDatos(): array
    {
        $errores = [];

        if (trim($this->nombre) === '') {
            $errores[] = "El nombre es obligatorio.";
        }
        if (trim($this->usuario) === '') {
            $errores[] = "El usuario es obligatorio.";
        }
        if (!filter_var($this->correo, FILTER_VALIDATE_EMAIL)) {
            $errores[] = "El correo electrónico no es válido.";
        }
        if (!in_array($this->rol, ['Administrador', 'Cajero'], true)) {
            $errores[] = "El rol debe ser 'Administrador' o 'Cajero'.";
        }

        return $errores;
    }

    /**
     * ingresarSistema($usuario, $contrasenaPlano)
     * ------------------------------------------------------------
     * Autentica a un empleado (Administrador o Cajero) por su nombre
     * de usuario y contraseña. Devuelve la fila cruda de la BD (no un
     * objeto todavía) porque quien llama a este método necesita saber
     * el "rol" para decidir si debe construir un objeto Administrador
     * o un objeto Cajero (esto se resuelve en AuthController, Fase 2).
     */
    public static function ingresarSistema(string $usuario, string $contrasenaPlano): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM empleado WHERE usuario = :usuario AND activo = 1");
        $stmt->bindValue(':usuario', $usuario);
        $stmt->execute();

        $fila = $stmt->fetch();
        if (!$fila) {
            return null;
        }

        // Soporta tanto contraseñas ya hasheadas con bcrypt como los
        // datos de prueba en texto plano que trae tu script SQL
        // (ej. 'angel123'), para que puedas iniciar sesión de inmediato
        // en desarrollo. En Fase 2 se recomienda re-hashear esos datos.
        $esValida = password_verify($contrasenaPlano, $fila['contraseña'])
                    || $contrasenaPlano === $fila['contraseña'];

        return $esValida ? $fila : null;
    }

    // =================================================================
    //  CRUD (compartido por Administrador y Cajero al heredar)
    // =================================================================

    public function actualizar(): bool
    {
        $sql = "UPDATE empleado SET
                    nombre = :nombre, apellido = :apellido, usuario = :usuario,
                    correo = :correo, telefono = :telefono, salario = :salario
                WHERE id_empleado = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':nombre', $this->nombre);
        $stmt->bindValue(':apellido', $this->apellido);
        $stmt->bindValue(':usuario', $this->usuario);
        $stmt->bindValue(':correo', $this->correo);
        $stmt->bindValue(':telefono', $this->telefono);
        $stmt->bindValue(':salario', $this->salario);
        $stmt->bindValue(':id', $this->idEmpleado, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * desactivar()
     * Borrado lógico: en vez de eliminar al empleado (lo que rompería
     * el historial de ventas/pedidos que gestionó), se marca inactivo.
     */
    public function desactivar(): bool
    {
        $sql = "UPDATE empleado SET activo = 0 WHERE id_empleado = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idEmpleado, PDO::PARAM_INT);
        $ok = $stmt->execute();
        if ($ok) $this->activo = false;
        return $ok;
    }

    public static function obtenerPorId(int $id): ?Empleado
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM empleado WHERE id_empleado = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Empleado($fila) : null;
    }

    public static function listarTodos(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM empleado ORDER BY nombre ASC");
        return array_map(fn($fila) => new Empleado($fila), $stmt->fetchAll());
    }
}
