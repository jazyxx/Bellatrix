<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/DetalleVenta.php';
require_once __DIR__ . '/Producto.php';
require_once __DIR__ . '/Receta.php';
require_once __DIR__ . '/GestorVentas.php';

/**
 * ==========================================================================
 *  Venta.php
 * ==========================================================================
 *  Mapea la tabla `ventas`. Corresponde a la clase "Venta" del Diagrama
 *  de Clases. Representa una venta PRESENCIAL en el punto de venta (POS),
 *  gestionada por un Cajero (CU006, CU007, CU008).
 * ==========================================================================
 */
class Venta
{
    public ?int $idVenta;
    public ?string $fecha;
    public float $total;
    public string $canal;          // ENUM: 'Presencial'|'En línea'
    public string $unidadNegocio;  // ENUM: 'Pastelería'|'Heladería'
    public string $estado;         // ENUM: 'Activa'|'Anulada'
    public ?int $idEmpleado;
    public ?string $nombreEmpleado;   // viene del JOIN con `empleado`
    public ?int $idPedido;            // pedido en línea de origen (NULL en ventas POS)

    /** @var DetalleVenta[] */
    public array $detalles = [];

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idVenta       = $datos['id_venta']       ?? null;
        $this->fecha         = $datos['fecha']          ?? null;
        $this->total         = isset($datos['total']) ? (float)$datos['total'] : 0.0;
        $this->canal         = $datos['canal']          ?? 'Presencial';
        $this->unidadNegocio = $datos['unidad_negocio'] ?? 'Pastelería';
        $this->estado        = $datos['estado']         ?? 'Activa';
        $this->idEmpleado    = $datos['id_empleado']    ?? null;
        $this->nombreEmpleado = $datos['nombre_empleado'] ?? null;
        $this->idPedido      = isset($datos['id_pedido']) ? (int)$datos['id_pedido'] : null;

        if ($this->idVenta !== null) {
            $this->detalles = DetalleVenta::listarPorVenta($this->idVenta);
        }
    }

    /**
     * crear()
     * Abre una venta nueva en estado 'Activa' y total en 0 (CU006).
     * Los productos se agregan después con añadirProducto().
     */
    public function crear(): int
    {
        $sql = "INSERT INTO ventas (total, canal, unidad_negocio, estado, id_empleado)
                VALUES (0, :canal, :unidad, 'Activa', :empleado)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':canal', $this->canal);
        $stmt->bindValue(':unidad', $this->unidadNegocio);
        $stmt->bindValue(':empleado', $this->idEmpleado, PDO::PARAM_INT);
        $stmt->execute();

        $this->idVenta = (int)$this->pdo->lastInsertId();
        return $this->idVenta;
    }

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * añadirProducto($idProducto, $cantidad)
     * ------------------------------------------------------------
     * Implementa el CU007: agrega una línea de detalle a la venta,
     * validando primero que exista stock suficiente del producto.
     * NOTA: aquí todavía NO se descuenta el stock; eso solo ocurre
     * al llamar a finalizar() (CU008), para poder anular la venta
     * sin haber afectado el inventario si el cliente se arrepiente.
     */
    public function añadirProducto(int $idProducto, int $cantidad): bool
    {
        if ($this->estado !== 'Activa') {
            throw new Exception("No se pueden agregar productos a una venta que no está activa.");
        }

        $producto = Producto::obtenerPorId($idProducto);
        if (!$producto) {
            throw new Exception("El producto #{$idProducto} no existe.");
        }
        if ($cantidad > $producto->stock) {
            throw new Exception("Stock insuficiente para '{$producto->nombre}'. Disponible: {$producto->stock}.");
        }

        $detalle = new DetalleVenta([
            'id_venta'        => $this->idVenta,
            'id_producto'     => $idProducto,
            'cantidad'        => $cantidad,
            'precio_unitario' => $producto->precio,
        ]);
        $detalle->crear();
        $this->detalles[] = $detalle;

        $this->calcularTotal();
        return true;
    }

    /**
     * calcularTotal()
     * Suma el subtotal de cada línea de detalle y actualiza el total
     * de la venta tanto en el objeto como en la base de datos.
     */
    public function calcularTotal(): float
    {
        $total = 0.0;
        foreach ($this->detalles as $detalle) {
            $total += $detalle->subtotal();
        }
        $this->total = $total;

        $sql = "UPDATE ventas SET total = :total WHERE id_venta = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':total', $this->total);
        $stmt->bindValue(':id', $this->idVenta, PDO::PARAM_INT);
        $stmt->execute();

        return $this->total;
    }

    /**
     * finalizar()
     * ------------------------------------------------------------
     * IMPLEMENTA EL CU008 COMPLETO: "Actualización automatizada de
     * stock y descuento proporcional de materia prima al finalizar venta".
     *
     * Por cada línea de la venta:
     *   1. Descuenta el stock del PRODUCTO terminado.
     *   2. Descuenta, de forma proporcional, la MATERIA PRIMA usada
     *      según la receta de ese producto (Receta::descontarInsumosPorVenta).
     * Todo se hace dentro de una transacción para que, si algo falla
     * a mitad de camino, ningún stock quede descontado a medias.
     */
    public function finalizar(): bool
    {
        if ($this->estado !== 'Activa') {
            throw new Exception("Esta venta ya fue finalizada o anulada.");
        }
        if (empty($this->detalles)) {
            throw new Exception("No se puede finalizar una venta sin productos.");
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($this->detalles as $detalle) {
                // Paso 1: descontar el stock del producto terminado.
                $producto = Producto::obtenerPorId($detalle->idProducto);
                $producto->actualizarStock(-$detalle->cantidad);

                // Paso 2: descontar proporcionalmente la materia prima (receta).
                Receta::descontarInsumosPorVenta($detalle->idProducto, $detalle->cantidad);
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * anularVenta()
     * ------------------------------------------------------------
     * Cambia el estado de la venta a 'Anulada' y devuelve
     * automáticamente el stock de la materia prima al inventario
     * basándose en la receta de cada producto vendido.
     */
    public function anularVenta(): bool
    {
        // Inicia la transacción para proteger la base de datos
        $this->pdo->beginTransaction();

        try {
            // Cambia el estado de la venta a 'Anulada'
            $sql = "UPDATE ventas SET estado = 'Anulada' WHERE id_venta = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $this->idVenta, PDO::PARAM_INT);
            $stmt->execute();

            // Devuelve la materia prima al inventario leyendo las recetas
            foreach ($this->detalles as $detalle) {
                $idProducto = $detalle->idProducto;
                $cantidadVendida = $detalle->cantidad;

                // Busca los ingredientes de este producto
                $lineasReceta = Receta::obtenerPorProducto($idProducto);

                foreach ($lineasReceta as $linea) {
                    if ($linea->idMateria !== null && $linea->cantidad !== null) {
                        
                        // Calcula la cantidad exacta a devolver re matemático
                        $cantidadADevolver = $linea->cantidad * $cantidadVendida;

                        // Suma el stock en la tabla materia_prima
                        $sqlMP = "UPDATE materia_prima SET stock_actual = stock_actual + :cantidad WHERE id_materia = :id_materia";
                        $stmtMP = $this->pdo->prepare($sqlMP);
                        
                        // PARAM_STR funciona bien para decimales en PDO
                        $stmtMP->bindValue(':cantidad', $cantidadADevolver, PDO::PARAM_STR); 
                        $stmtMP->bindValue(':id_materia', $linea->idMateria, PDO::PARAM_INT);
                        $stmtMP->execute();
                    }
                }
            }

            // Confirma todos los cambios esova
            $this->pdo->commit();
            $this->estado = 'Anulada';
            
            return true;

        } catch (Exception $e) {
            // Si hay un error, echa todo para atrás re triste
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * registrarDesdePedido($pedido, $idEmpleado)
     * ------------------------------------------------------------
     * Issue #37: cuando un pedido en línea se PAGA (sale de 'Pendiente
     * de pago') se convierte en venta: aparece en el historial de
     * ventas y su ingreso se suma a la caja 'En línea' del día.
     *
     *  - Descuenta el stock del PRODUCTO TERMINADO. (La materia prima
     *    se sigue descontando al pasar a 'Listo para recoger'.)
     *  - Como `ventas.unidad_negocio` y la caja diaria son por unidad,
     *    si el pedido mezcla Pastelería y Heladería se crea UNA venta
     *    por unidad (cada una con su ingreso en su caja).
     *  - Es idempotente: si el pedido ya tiene venta, no hace nada.
     *  - Si ya hay una transacción abierta (Pedido::cambiarEstado),
     *    se une a ella; si el stock no alcanza lanza excepción y todo
     *    se revierte.
     *
     * @return int[] ids de las ventas creadas (vacío si ya existían)
     */
    public static function registrarDesdePedido($pedido, ?int $idEmpleado = null): array
    {
        $pdo = Database::getConnection();

        $chk = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE id_pedido = :p");
        $chk->bindValue(':p', $pedido->idPedido, PDO::PARAM_INT);
        $chk->execute();
        if ((int)$chk->fetchColumn() > 0) {
            return [];
        }

        // Agrupar las líneas del pedido por unidad de negocio del producto.
        $grupos = [];
        foreach ($pedido->productos as $linea) {
            $producto = Producto::obtenerPorId((int)$linea->idProducto);
            $unidad = $producto ? $producto->unidadNegocio : 'Pastelería';
            $grupos[$unidad][] = $linea;
        }
        if (empty($grupos)) {
            throw new Exception("El pedido no tiene productos para registrar como venta.");
        }

        $propia = !$pdo->inTransaction();
        if ($propia) {
            $pdo->beginTransaction();
        }
        try {
            $ids = [];
            foreach ($grupos as $unidad => $lineas) {
                $total = 0.0;
                foreach ($lineas as $l) {
                    $total += $l->subtotal();
                }

                $ins = $pdo->prepare("INSERT INTO ventas (total, canal, unidad_negocio, estado, id_empleado, id_pedido)
                                      VALUES (:total, 'En línea', :unidad, 'Activa', :empleado, :pedido)");
                $ins->bindValue(':total', $total);
                $ins->bindValue(':unidad', $unidad);
                $ins->bindValue(':empleado', $idEmpleado, PDO::PARAM_INT);
                $ins->bindValue(':pedido', $pedido->idPedido, PDO::PARAM_INT);
                $ins->execute();
                $idVenta = (int)$pdo->lastInsertId();

                foreach ($lineas as $l) {
                    $det = $pdo->prepare("INSERT INTO detalle_venta (id_venta, id_producto, cantidad, precio_unitario)
                                          VALUES (:venta, :producto, :cantidad, :precio)");
                    $det->bindValue(':venta', $idVenta, PDO::PARAM_INT);
                    $det->bindValue(':producto', $l->idProducto, PDO::PARAM_INT);
                    $det->bindValue(':cantidad', $l->cantidad, PDO::PARAM_INT);
                    $det->bindValue(':precio', $l->precioUnitario);
                    $det->execute();

                    // Stock del producto terminado (lanza excepción si no alcanza).
                    $prod = Producto::obtenerPorId((int)$l->idProducto);
                    if ($prod) {
                        $prod->actualizarStock(-(int)$l->cantidad);
                    }
                }

                // Ingreso en la caja del día (canal En línea + unidad).
                $caja = GestorVentas::obtenerOCrearCajaDelDia('En línea', $unidad, date('Y-m-d'), $idEmpleado);
                $caja->registrarIngreso($total);

                $ids[] = $idVenta;
            }
            if ($propia) {
                $pdo->commit();
            }
            return $ids;
        } catch (Exception $e) {
            if ($propia && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * anularDesdePedido($pedido, $estadoAnterior)
     * ------------------------------------------------------------
     * Issue #37: si un pedido ya pagado se CANCELA (hay reembolso),
     * se revierte todo lo que registrarDesdePedido() hizo:
     *   - las ventas del pedido pasan a 'Anulada',
     *   - se devuelve el stock del producto terminado,
     *   - se devuelve la materia prima solo si ya se había descontado
     *     (el pedido llegó a 'Listo para recoger' o 'Entregado'),
     *   - se resta el ingreso de la caja donde se registró.
     */
    public static function anularDesdePedido($pedido, string $estadoAnterior): void
    {
        $pdo = Database::getConnection();

        $q = $pdo->prepare("SELECT id_venta, total, canal, unidad_negocio, fecha
                            FROM ventas WHERE id_pedido = :p AND estado = 'Activa'");
        $q->bindValue(':p', $pedido->idPedido, PDO::PARAM_INT);
        $q->execute();
        $ventas = $q->fetchAll();
        if (empty($ventas)) {
            return;
        }

        $materiaDescontada = in_array($estadoAnterior, ['Listo para recoger', 'Entregado'], true);

        $propia = !$pdo->inTransaction();
        if ($propia) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($ventas as $v) {
                $up = $pdo->prepare("UPDATE ventas SET estado = 'Anulada' WHERE id_venta = :id");
                $up->bindValue(':id', $v['id_venta'], PDO::PARAM_INT);
                $up->execute();

                foreach (DetalleVenta::listarPorVenta((int)$v['id_venta']) as $d) {
                    // Devolver stock del producto terminado.
                    $prod = Producto::obtenerPorId((int)$d->idProducto);
                    if ($prod) {
                        $prod->actualizarStock((int)$d->cantidad);
                    }

                    // Devolver materia prima (solo si ya se había descontado).
                    if ($materiaDescontada) {
                        foreach (Receta::obtenerPorProducto((int)$d->idProducto) as $linea) {
                            if ($linea->idMateria !== null && $linea->cantidad !== null) {
                                $mp = $pdo->prepare("UPDATE materia_prima SET stock_actual = stock_actual + :c WHERE id_materia = :m");
                                $mp->bindValue(':c', $linea->cantidad * $d->cantidad, PDO::PARAM_STR);
                                $mp->bindValue(':m', $linea->idMateria, PDO::PARAM_INT);
                                $mp->execute();
                            }
                        }
                    }
                }

                // Restar el ingreso de la caja del día en que se registró.
                $fecha = date('Y-m-d', strtotime($v['fecha']));
                $caja = GestorVentas::obtenerOCrearCajaDelDia($v['canal'], $v['unidad_negocio'], $fecha, null);
                $caja->revertirIngreso((float)$v['total']);
            }
            if ($propia) {
                $pdo->commit();
            }
        } catch (Exception $e) {
            if ($propia && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // =================================================================
    //  Consultas
    // =================================================================

    public static function obtenerPorId(int $id): ?Venta
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT v.*, e.nombre AS nombre_empleado FROM ventas v LEFT JOIN empleado e ON v.id_empleado = e.id_empleado WHERE v.id_venta = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Venta($fila) : null;
    }

    public static function listarTodas(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT v.*, e.nombre AS nombre_empleado FROM ventas v LEFT JOIN empleado e ON v.id_empleado = e.id_empleado ORDER BY v.fecha DESC");
        return array_map(fn($fila) => new Venta($fila), $stmt->fetchAll());
    }

    public static function obtenerPorEmpleado(int $idEmpleado): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT v.*, e.nombre AS nombre_empleado FROM ventas v LEFT JOIN empleado e ON v.id_empleado = e.id_empleado WHERE v.id_empleado = :empleado ORDER BY v.fecha DESC");
        $stmt->bindValue(':empleado', $idEmpleado, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new Venta($fila), $stmt->fetchAll());
    }
}
