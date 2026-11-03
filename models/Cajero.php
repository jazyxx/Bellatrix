<?php
require_once __DIR__ . '/Empleado.php';

class Cajero extends Empleado
{
    
    public ?string $turno;

    
    public ?string $contrasenaSinHashear = null;

    public function __construct(array $datos = [])
    {
        parent::__construct($datos);
        $this->turno = $datos['turno'] ?? null;
    }

    public function crear(): int
    {
        $this->rol = 'Cajero';
        $hash = password_hash($this->contrasenaSinHashear ?? '', PASSWORD_BCRYPT);

        $this->pdo->beginTransaction();
        try {
            $sql = "INSERT INTO empleado (nombre, apellido, usuario, correo, contraseña, rol, activo, telefono, salario, fecha_contratacion)
                    VALUES (:nombre, :apellido, :usuario, :correo, :contrasena, 'Cajero', 1, :telefono, :salario, :fecha)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':nombre', $this->nombre);
            $stmt->bindValue(':apellido', $this->apellido);
            $stmt->bindValue(':usuario', $this->usuario);
            $stmt->bindValue(':correo', $this->correo);
            $stmt->bindValue(':contrasena', $hash);
            $stmt->bindValue(':telefono', $this->telefono);
            $stmt->bindValue(':salario', $this->salario);
            $stmt->bindValue(':fecha', $this->fechaContratacion);
            $stmt->execute();

            $this->idEmpleado = (int)$this->pdo->lastInsertId();

            $sql2 = "INSERT INTO cajero (id_cajero, turno) VALUES (:id, :turno)";
            $stmt2 = $this->pdo->prepare($sql2);
            $stmt2->bindValue(':id', $this->idEmpleado, PDO::PARAM_INT);
            $stmt2->bindValue(':turno', $this->turno);
            $stmt2->execute();

            $this->pdo->commit();
            return $this->idEmpleado;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function crearVenta(array $data): object
    {
        require_once __DIR__ . '/Venta.php';
        $data['id_empleado'] = $this->idEmpleado;
        $venta = new Venta($data);
        $venta->crear();
        return $venta;
    }

    public function consultarVentas(): array
    {
        require_once __DIR__ . '/Venta.php';
        return Venta::obtenerPorEmpleado($this->idEmpleado);
    }

    public function añadirProductoAVenta(int $idVenta, int $idProducto, int $cantidad): bool
    {
        require_once __DIR__ . '/Venta.php';
        $venta = Venta::obtenerPorId($idVenta);
        if (!$venta) {
            throw new Exception("La venta #{$idVenta} no existe.");
        }
        return $venta->añadirProducto($idProducto, $cantidad);
    }

    public function finalizarVenta(int $idVenta): bool
    {
        require_once __DIR__ . '/Venta.php';
        $venta = Venta::obtenerPorId($idVenta);
        if (!$venta) {
            throw new Exception("La venta #{$idVenta} no existe.");
        }
        return $venta->finalizar();
    }

    public function gestionarPedidoEnLinea(array $datosAccion): bool
    {
        require_once __DIR__ . '/Pedido.php';
        $pedido = Pedido::obtenerPorId((int)$datosAccion['id_pedido']);
        if (!$pedido) return false;

        $pedido->idEmpleadoGestion = $this->idEmpleado;
        $pedido->cambiarEstado($datosAccion['nuevo_estado']);
        return true;
    }

    public static function obtenerPorId(int $idEmpleado): ?Cajero
    {
        $pdo = \Database::getConnection();
        $sql = "SELECT e.*, c.turno
                FROM empleado e
                INNER JOIN cajero c ON c.id_cajero = e.id_empleado
                WHERE e.id_empleado = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $idEmpleado, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Cajero($fila) : null;
    }

    public function obtenerDatos(): array
    {
        $datos = parent::obtenerDatos();
        $datos['turno'] = $this->turno;
        return $datos;
    }
}
