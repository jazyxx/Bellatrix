<?php
require_once __DIR__ . '/../config/Database.php';

class Empleado
{
    public ?int $idEmpleado;
    public string $nombre;
    public ?string $apellido;
    public string $usuario;
    public string $correo;
    protected string $contrasena; 
    public string $rol; 
    public bool $activo;
    public ?string $telefono;
    public ?float $salario;
    public ?string $fechaContratacion;

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
 
        $esValida = password_verify($contrasenaPlano, $fila['contraseña'])
                    || $contrasenaPlano === $fila['contraseña'];

        return $esValida ? $fila : null;
    }

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

    public function activar(): bool
    {
        $sql = "UPDATE empleado SET activo = 1 WHERE id_empleado = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idEmpleado, PDO::PARAM_INT);
        $ok = $stmt->execute();
        if ($ok) $this->activo = true;
        return $ok;
    }

    public function cambiarContrasena(string $nuevaContrasenaPlano): bool
    {
        $hash = password_hash($nuevaContrasenaPlano, PASSWORD_BCRYPT);
        $sql = "UPDATE empleado SET contraseña = :hash WHERE id_empleado = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':hash', $hash);
        $stmt->bindValue(':id', $this->idEmpleado, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function obtenerDatos(): array
    {
        return [
            'id_empleado'        => $this->idEmpleado,
            'nombre'             => $this->nombre,
            'apellido'           => $this->apellido,
            'usuario'            => $this->usuario,
            'correo'             => $this->correo,
            'rol'                => $this->rol,
            'activo'             => $this->activo,
            'telefono'           => $this->telefono,
            'salario'            => $this->salario,
            'fecha_contratacion' => $this->fechaContratacion,
        ];
    }
}
