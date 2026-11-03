<?php
require_once __DIR__ . '/Empleado.php';
require_once __DIR__ . '/Producto.php';
require_once __DIR__ . '/Cliente.php';

class Administrador extends Empleado
{
    
    public int $nivelAcceso;

    public function __construct(array $datos = [])
    {
        
        parent::__construct($datos);

        $this->nivelAcceso = isset($datos['nivel_acceso']) ? (int)$datos['nivel_acceso'] : 1;
    }

    public function crear(): int
    {
        $this->rol = 'Administrador';
        $hash = password_hash($this->contrasenaSinHashear ?? '', PASSWORD_BCRYPT);

        $this->pdo->beginTransaction();
        try {
            $sql = "INSERT INTO empleado (nombre, apellido, usuario, correo, contraseña, rol, activo, telefono, salario, fecha_contratacion)
                    VALUES (:nombre, :apellido, :usuario, :correo, :contrasena, 'Administrador', 1, :telefono, :salario, :fecha)";
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

            $sql2 = "INSERT INTO administrador (id_admin, nivel_acceso) VALUES (:id, :nivel)";
            $stmt2 = $this->pdo->prepare($sql2);
            $stmt2->bindValue(':id', $this->idEmpleado, PDO::PARAM_INT);
            $stmt2->bindValue(':nivel', $this->nivelAcceso, PDO::PARAM_INT);
            $stmt2->execute();

            $this->pdo->commit();
            return $this->idEmpleado;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function gestionarEmpleado(array $datosAccion): bool
    {
        $accion = $datosAccion['accion'] ?? '';

        switch ($accion) {
            case 'crear':
                $cajero = new Cajero($datosAccion);
                $cajero->contrasenaSinHashear = $datosAccion['contraseña'] ?? '';
                return (bool) $cajero->crear();

            case 'actualizar':
                $empleado = Empleado::obtenerPorId((int)$datosAccion['id_empleado']);
                if (!$empleado) return false;
                $empleado->nombre = $datosAccion['nombre'] ?? $empleado->nombre;
                $empleado->telefono = $datosAccion['telefono'] ?? $empleado->telefono;
                return $empleado->actualizar();

            case 'desactivar':
                $empleado = Empleado::obtenerPorId((int)$datosAccion['id_empleado']);
                if (!$empleado) return false;
                return $empleado->desactivar();

            default:
                throw new Exception("Acción no reconocida para gestionar empleado: '{$accion}'.");
        }
    }

    public function consultarInventario(): array
    {
        return Producto::listarTodos(false);
    }

    public function consultarVentas(): array
    {
        require_once __DIR__ . '/Venta.php';
        return Venta::listarTodas();
    }

    public function generarReporte(string $fechaInicio, string $fechaFin): array
    {
        require_once __DIR__ . '/GestorVentas.php';
        return GestorVentas::generarReportePorRango($fechaInicio, $fechaFin);
    }

    public function gestionarCliente(array $datosAccion): bool
    {
        $accion = $datosAccion['accion'] ?? '';
        $cliente = Cliente::obtenerPorId((int)($datosAccion['id_cliente'] ?? 0));

        if (!$cliente) return false;

        switch ($accion) {
            case 'desactivar':
                return $cliente->desactivar();
            case 'actualizar':
                $cliente->nombre = $datosAccion['nombre'] ?? $cliente->nombre;
                $cliente->telefono = $datosAccion['telefono'] ?? $cliente->telefono;
                $cliente->direccionEntrega = $datosAccion['direccion_entrega'] ?? $cliente->direccionEntrega;
                return $cliente->actualizar();
            default:
                throw new Exception("Acción no reconocida para gestionar cliente: '{$accion}'.");
        }
    }
  
    public ?string $contrasenaSinHashear = null;

    public function obtenerDatos(): array
    {
        $datos = parent::obtenerDatos();
        $datos['nivel_acceso'] = $this->nivelAcceso;
        return $datos;
    }

    public static function obtenerPorId(int $idEmpleado): ?Administrador
    {
        $pdo = \Database::getConnection();
        $sql = "SELECT e.*, a.nivel_acceso
                FROM empleado e
                INNER JOIN administrador a ON a.id_admin = e.id_empleado
                WHERE e.id_empleado = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $idEmpleado, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Administrador($fila) : null;
    }
}
