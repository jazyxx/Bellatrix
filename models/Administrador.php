<?php
require_once __DIR__ . '/Empleado.php';
require_once __DIR__ . '/Producto.php';
require_once __DIR__ . '/Cliente.php';

/**
 * ==========================================================================
 *  Administrador.php
 * ==========================================================================
 *  "extends Empleado" -> Administrador HEREDA todo lo de Empleado
 *  (nombre, usuario, contraseña, validarDatos(), ingresarSistema(), etc.)
 *  y le AGREGA sus propias capacidades, tal como pide el Diagrama de Clases.
 *
 *  A nivel de base de datos, un Administrador es:
 *    1) Una fila en la tabla `empleado` (datos generales, rol = 'Administrador')
 *    2) Una fila en la tabla `administrador` (datos extra: nivel_acceso),
 *       relacionada 1 a 1 mediante id_admin = id_empleado (FK con CASCADE).
 * ==========================================================================
 */
class Administrador extends Empleado
{
    /** Propiedad EXTRA que solo tiene Administrador (tabla `administrador`). */
    public int $nivelAcceso;

    public function __construct(array $datos = [])
    {
        // Reutiliza todo el constructor del padre (llena nombre, usuario, etc.)
        parent::__construct($datos);

        $this->nivelAcceso = isset($datos['nivel_acceso']) ? (int)$datos['nivel_acceso'] : 1;
    }

    /**
     * crear()
     * Inserta al administrador en AMBAS tablas: primero en `empleado`
     * (fila general) y luego en `administrador` (fila específica),
     * reutilizando el mismo id_empleado como id_admin.
     */
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

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * gestionarEmpleado($empleado)
     * ------------------------------------------------------------
     * Punto de entrada único para crear/actualizar/desactivar un
     * Cajero desde el panel de Administrador (CU003). Recibe un
     * arreglo con los datos y una "accion" a realizar.
     *
     * @param array $datosAccion Debe incluir la clave 'accion' = 'crear'|'actualizar'|'desactivar'
     */
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

    /**
     * consultarInventario()
     * Atajo para que el Administrador vea el catálogo completo,
     * incluyendo productos no disponibles (a diferencia del catálogo
     * público, que solo muestra los disponibles).
     */
    public function consultarInventario(): array
    {
        return Producto::listarTodos(false);
    }

    /**
     * consultarVentas()
     * ------------------------------------------------------------
     * Se deja preparado aquí como "puente" hacia el modelo Venta.
     * La lógica de filtros avanzados (por fecha, canal, unidad) se
     * construye en detalle sobre el modelo Venta en la Fase 3
     * (Controladores de Casos de Uso).
     */
    public function consultarVentas(): array
    {
        require_once __DIR__ . '/Venta.php';
        return Venta::listarTodas();
    }

    /**
     * generarReporte($fechaInicio, $fechaFin)
     * Delega en GestorVentas, que es la clase especializada en
     * reportes según el Diagrama de Clases.
     */
    public function generarReporte(string $fechaInicio, string $fechaFin): array
    {
        require_once __DIR__ . '/GestorVentas.php';
        return GestorVentas::generarReportePorRango($fechaInicio, $fechaFin);
    }

    /**
     * gestionarCliente($accionDatos)
     * Permite al Administrador consultar o desactivar clientes (CU003).
     */
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

    /** Propiedad temporal auxiliar usada solo durante crear(), no existe en la BD. */
    public ?string $contrasenaSinHashear = null;

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
