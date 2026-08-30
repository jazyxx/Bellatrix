<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  Cliente.php
 * ==========================================================================
 *  Mapea la tabla `cliente`. Corresponde a la clase "Cliente" del
 *  Diagrama de Clases.
 *
 *  NOTA IMPORTANTE SOBRE SEGURIDAD:
 *  En esta Fase 1 (solo Modelos) los métodos registrarse() e
 *  iniciarSesion() manejan directamente la contraseña. El HASHING
 *  real con bcrypt (password_hash / password_verify) y el manejo de
 *  sesiones ($_SESSION) se implementará formalmente en la FASE 2
 *  (AuthController), que es donde vive la lógica de autenticación
 *  del sistema. Aun así, dejamos aquí ya el uso de password_hash()
 *  para que el modelo nunca guarde contraseñas en texto plano.
 * ==========================================================================
 */
class Cliente
{
    public ?int $idCliente;
    public string $nombre;
    public string $correo;
    public string $contrasena; // Al leer de BD, aquí llega el HASH, nunca la contraseña real.
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

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * registrarse()
     * ------------------------------------------------------------
     * Inserta este cliente como una fila nueva en `cliente`, guardando
     * la contraseña de forma segura con password_hash() (bcrypt).
     * Devuelve false si el correo ya existe (columna UNIQUE en la BD).
     */
    public function registrarse(): bool
    {
        // Nunca se guarda la contraseña "cruda": siempre se hashea antes.
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
            // Código 23000 = violación de restricción única (correo duplicado).
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * iniciarSesion($correo, $contrasenaPlano)
     * ------------------------------------------------------------
     * Busca al cliente por correo y compara la contraseña ingresada
     * con el hash guardado en la BD usando password_verify().
     * Devuelve el objeto Cliente si las credenciales son correctas,
     * o null si no lo son.
     *
     * (El manejo completo de sesión con $_SESSION se hará en la Fase 2,
     * dentro de AuthController; aquí solo se valida la identidad).
     */
    public static function iniciarSesion(string $correo, string $contrasenaPlano): ?Cliente
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM cliente WHERE correo = :correo AND activo = 1");
        $stmt->bindValue(':correo', $correo);
        $stmt->execute();

        $fila = $stmt->fetch();
        if (!$fila) {
            return null; // No existe ese correo o el cliente está inactivo.
        }

        if (!password_verify($contrasenaPlano, $fila['contraseña'])) {
            return null; // Contraseña incorrecta.
        }

        return new Cliente($fila);
    }

    /**
     * recuperarContrasena()
     * ------------------------------------------------------------
     * Genera un token aleatorio y seguro, lo guarda en la BD junto
     * con su fecha de expiración (1 hora), para poder enviarlo luego
     * por correo (esto se conectará con Notificacion.php en la Fase 4).
     *
     * @return string El token generado (para incluirlo en el link del correo).
     */
    public function recuperarContrasena(): string
    {
        $token = bin2hex(random_bytes(32)); // 64 caracteres hexadecimales, seguros.
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

    /**
     * restablecerContrasena($token, $nuevaContrasena)
     * Complemento lógico de recuperarContrasena(): valida el token
     * (que exista y no haya expirado) y, si es válido, guarda la
     * nueva contraseña ya hasheada.
     */
    public static function restablecerContrasena(string $token, string $nuevaContrasena): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM cliente WHERE token_recuperacion = :token AND token_expiracion >= NOW()");
        $stmt->bindValue(':token', $token);
        $stmt->execute();

        $fila = $stmt->fetch();
        if (!$fila) {
            return false; // Token inválido o expirado.
        }

        $hash = password_hash($nuevaContrasena, PASSWORD_BCRYPT);
        $update = $pdo->prepare("UPDATE cliente SET contraseña = :hash, token_recuperacion = NULL, token_expiracion = NULL WHERE id_cliente = :id");
        $update->bindValue(':hash', $hash);
        $update->bindValue(':id', $fila['id_cliente'], PDO::PARAM_INT);

        return $update->execute();
    }

    /**
     * consultarPedidos()
     * ------------------------------------------------------------
     * Devuelve todos los pedidos hechos por este cliente. Se apoya
     * en el modelo Pedido (definido en Pedido.php) para no repetir
     * la consulta SQL aquí.
     */
    public function consultarPedidos(): array
    {
        require_once __DIR__ . '/Pedido.php';
        return Pedido::obtenerPorCliente($this->idCliente);
    }

    // =================================================================
    //  CRUD adicional
    // =================================================================

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

    /**
     * desactivar()
     * En vez de borrar físicamente al cliente (lo cual rompería el
     * historial de pedidos por las llaves foráneas), se le pone
     * activo = 0. Esto es "borrado lógico" (soft delete).
     */
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

    /**
     * obtenerPorCorreo($correo)
     * ------------------------------------------------------------
     * Añadido en la Fase 2: lo usa AuthController::recuperar() para
     * ubicar la cuenta del cliente por su correo de forma directa
     * (con una consulta indexada, ya que `correo` es UNIQUE en la BD),
     * en vez de recorrer todos los clientes uno por uno.
     */
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
}
