<?php
require_once __DIR__ . '/Empleado.php';

/**
 * ==========================================================================
 *  Cajero.php
 * ==========================================================================
 *  "extends Empleado" -> igual que Administrador, hereda todo lo básico
 *  de Empleado y agrega lo propio del rol Cajero.
 *
 *  A nivel de base de datos: fila en `empleado` (rol='Cajero') + fila
 *  relacionada en `cajero` (turno), unidas por id_cajero = id_empleado.
 *
 *  NOTA SOBRE LOS MÉTODOS DE VENTA:
 *  El Diagrama de Clases le asigna a Cajero los métodos crearVenta(),
 *  añadirProductoAVenta(), finalizarVenta() y gestionarPedidoEnLinea().
 *  Aquí se dejan como métodos "puente" que delegan en los modelos
 *  Venta.php y Pedido.php, porque la lógica de negocio PESADA de esos
 *  procesos (como el descuento de stock del CU008) vive en esos modelos
 *  y se terminará de orquestar en el Controlador de Ventas (Fase 3),
 *  para no romper la separación de responsabilidades del patrón MVC.
 * ==========================================================================
 */
class Cajero extends Empleado
{
    /** Propiedad EXTRA que solo tiene Cajero (tabla `cajero`). */
    public ?string $turno;

    /** Propiedad temporal auxiliar usada solo durante crear(), no existe en la BD. */
    public ?string $contrasenaSinHashear = null;

    public function __construct(array $datos = [])
    {
        parent::__construct($datos);
        $this->turno = $datos['turno'] ?? null;
    }

    /**
     * crear()
     * Igual que en Administrador: inserta primero en `empleado` y
     * luego en `cajero`, dentro de una transacción para garantizar
     * que ambas filas se creen juntas o ninguna se cree.
     */
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

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    //  Delegan en Venta.php / Pedido.php, que se construyen más abajo.
    // =================================================================

    /**
     * crearVenta($data)
     * Abre una venta nueva (CU006). $data debe traer al menos
     * 'canal' y 'unidad_negocio'.
     */
    public function crearVenta(array $data): object
    {
        require_once __DIR__ . '/Venta.php';
        $data['id_empleado'] = $this->idEmpleado;
        $venta = new Venta($data);
        $venta->crear();
        return $venta;
    }

    /**
     * consultarVentas()
     * Trae únicamente las ventas realizadas por ESTE cajero
     * (a diferencia de Administrador::consultarVentas(), que ve todas).
     */
    public function consultarVentas(): array
    {
        require_once __DIR__ . '/Venta.php';
        return Venta::obtenerPorEmpleado($this->idEmpleado);
    }

    /**
     * añadirProductoAVenta($idVenta, $idProducto, $cantidad)
     * Implementa el CU007: agrega un producto a la venta activa,
     * validando primero que haya stock suficiente.
     */
    public function añadirProductoAVenta(int $idVenta, int $idProducto, int $cantidad): bool
    {
        require_once __DIR__ . '/Venta.php';
        $venta = Venta::obtenerPorId($idVenta);
        if (!$venta) {
            throw new Exception("La venta #{$idVenta} no existe.");
        }
        return $venta->añadirProducto($idProducto, $cantidad);
    }

    /**
     * finalizarVenta($idVenta)
     * Implementa el CU008: cierra la venta, calcula el total y
     * dispara el descuento automático de stock (producto + materia
     * prima según receta).
     */
    public function finalizarVenta(int $idVenta): bool
    {
        require_once __DIR__ . '/Venta.php';
        $venta = Venta::obtenerPorId($idVenta);
        if (!$venta) {
            throw new Exception("La venta #{$idVenta} no existe.");
        }
        return $venta->finalizar();
    }

    /**
     * gestionarPedidoEnLinea($pedido)
     * Implementa parte del CU017: el cajero puede tomar un pedido
     * en línea confirmado y avanzar su estado de preparación.
     */
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
}
