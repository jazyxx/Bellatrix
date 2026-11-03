<?php
require_once __DIR__ . '/../config/Database.php';

class Cliente
{
    public ?int $idCliente;
    public string $nombre;
    public string $correo;
    public string $contrasena; 
    public ?string $telefono;
    public ?string $direccionEntrega;
    public bool $activo;
    public ?string $fechaRegistro;
    public ?string $tokenRecuperacion;
    public ?string $tokenExpiracion;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idCliente         = $datos['id_cliente']         ?? null;
        $this->nombre             = $datos['nombre']             ?? '';
        $this->correo             = $datos['correo']             ?? '';
        $this->contrasena         = $datos['contraseña']         ?? '';
        $this->telefono           = $datos['telefono']           ?? null;
        $this->direccionEntrega   = $datos['direccion_entrega']  ?? null;
        $this->activo             = isset($datos['activo']) ? (bool)$datos['activo'] : true;
        $this->fechaRegistro      = $datos['fecha_registro']     ?? null;
        $this->tokenRecuperacion  = $datos['token_recuperacion'] ?? null;
        $this->tokenExpiracion    = $datos['token_expiracion']   ?? null;
    }

    public function registrarse(): bool
    {
        
        $hash = password_hash($this->contrasena, PASSWORD_BCRYPT);

        $sql = "INSERT INTO cliente (nombre, correo, contraseña, telefono, direccion_entrega, activo)
                VALUES (:nombre, :correo, :contrasena, :telefono, :direccion, 1)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':nombre', $this->nombre);
            $stmt->bindValue(':correo', $this->correo);
            $stmt->bindValue(':contrasena', $hash);
            $stmt->bindValue(':telefono', $this->telefono);
            $stmt->bindValue(':direccion', $this->direccionEntrega);
            $stmt->execute();

            $this->idCliente = (int)$this->pdo->lastInsertId();
            $this->contrasena = $hash;
            $this->activo = true;
            return true;
        } catch (PDOException $e) {
            
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public static function iniciarSesion(string $correo, string $contrasenaPlano): ?Cliente
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM cliente WHERE correo = :correo AND activo = 1");
        $stmt->bindValue(':correo', $correo);
        $stmt->execute();

        $fila = $stmt->fetch();
        if (!$fila) {
            return null; 
        }

        if (!password_verify($contrasenaPlano, $fila['contraseña'])) {
            return null; 
        }

        return new Cliente($fila);
    }

    public function recuperarContrasena(): string
    {
        $token = bin2hex(random_bytes(32)); 
        $expiracion = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $sql = "UPDATE cliente SET token_recuperacion = :token, token_expiracion = :expira WHERE id_cliente = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':token', $token);
        $stmt->bindValue(':expira', $expiracion);
        $stmt->bindValue(':id', $this->idCliente, PDO::PARAM_INT);
        $stmt->execute();

        $this->tokenRecuperacion = $token;
        $this->tokenExpiracion = $expiracion;

        return $token;
    }

    public static function restablecerContrasena(string $token, string $nuevaContrasena): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM cliente WHERE token_recuperacion = :token AND token_expiracion >= NOW()");
        $stmt->bindValue(':token', $token);
        $stmt->execute();

        $fila = $stmt->fetch();
        if (!$fila) {
            return false; 
        }

        $hash = password_hash($nuevaContrasena, PASSWORD_BCRYPT);
        $update = $pdo->prepare("UPDATE cliente SET contraseña = :hash, token_recuperacion = NULL, token_expiracion = NULL WHERE id_cliente = :id");
        $update->bindValue(':hash', $hash);
        $update->bindValue(':id', $fila['id_cliente'], PDO::PARAM_INT);

        return $update->execute();
    }

    public function consultarPedidos(): array
    {
        require_once __DIR__ . '/Pedido.php';
        return Pedido::obtenerPorCliente($this->idCliente);
    }

    
    public function actualizar(): bool
    {
        $sql = "UPDATE cliente SET nombre = :nombre, telefono = :telefono, direccion_entrega = :direccion
                WHERE id_cliente = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':nombre', $this->nombre);
        $stmt->bindValue(':telefono', $this->telefono);
        $stmt->bindValue(':direccion', $this->direccionEntrega);
        $stmt->bindValue(':id', $this->idCliente, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function desactivar(): bool
    {
        $sql = "UPDATE cliente SET activo = 0 WHERE id_cliente = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idCliente, PDO::PARAM_INT);
        $ok = $stmt->execute();
        if ($ok) $this->activo = false;
        return $ok;
    }

    public static function obtenerPorId(int $id): ?Cliente
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM cliente WHERE id_cliente = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Cliente($fila) : null;
    }

    public static function obtenerPorCorreo(string $correo): ?Cliente
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM cliente WHERE correo = :correo");
        $stmt->bindValue(':correo', $correo);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Cliente($fila) : null;
    }

    public static function listarTodos(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM cliente ORDER BY nombre ASC");
        return array_map(fn($fila) => new Cliente($fila), $stmt->fetchAll());
    }

    public function activar(): bool
    {
        $sql = "UPDATE cliente SET activo = 1 WHERE id_cliente = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idCliente, PDO::PARAM_INT);
        $ok = $stmt->execute();
        if ($ok) $this->activo = true;
        return $ok;
    }

    public function obtenerDatos(): array
    {
        return [
            'id_cliente'        => $this->idCliente,
            'nombre'            => $this->nombre,
            'correo'            => $this->correo,
            'telefono'          => $this->telefono,
            'direccion_entrega' => $this->direccionEntrega,
            'activo'            => $this->activo,
            'fecha_registro'    => $this->fechaRegistro,
        ];
    }
}
