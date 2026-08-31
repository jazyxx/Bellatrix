<?php
require_once __DIR__ . '/../../models/Venta.php';
require_once __DIR__ . '/../../models/Producto.php';
require_once __DIR__ . '/../../models/GestorVentas.php';
require_once __DIR__ . '/../../models/Receta.php';
require_once __DIR__ . '/../../models/MateriaPrima.php';
require_once __DIR__ . '/../../models/AlertaStock.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

/**
 * ==========================================================================
 *  VentaController.php
 * ==========================================================================
 *  Implementa el flujo completo del Punto de Venta (POS) presencial:
 *
 *    - CU005: Gestionar Ventas (consultar historial).
 *    - CU006: Registrar Venta Presencial (abrir una venta nueva).
 *    - CU007: Añadir Producto a Venta Presencial.
 *    - CU008: Finalizar/Anular Venta — INCLUYE la actualización
 *             automatizada de stock y el descuento proporcional de
 *             materia prima (ya implementado como lógica de negocio
 *             en Venta::finalizar() y Receta::descontarInsumosPorVenta()
 *             durante la Fase 1). Este controlador es quien DISPARA
 *             esa lógica y, además, la conecta con el CU018: cada venta
 *             finalizada también registra su ingreso en la Caja del
 *             día correspondiente (GestorVentas).
 *
 *  Todas las rutas de este controlador requieren sesión de Cajero o
 *  Administrador (ver Middleware::rol(['Administrador','Cajero']) en
 *  routes.php).
 * ==========================================================================
 */
class VentaController
{
    /**
     * crear()
     * ------------------------------------------------------------
     * POST /api/ventas
     * Body: { "canal": "Presencial", "unidad_negocio": "Pastelería" }
     * Implementa el CU006: abre una venta nueva en estado 'Activa'.
     * El empleado que la registra se toma automáticamente de la
     * sesión activa (no se le pide al frontend que lo envíe, para
     * evitar que alguien pueda registrar una venta a nombre de otro).
     */
    public function crear(): void
    {
        $datos = Request::jsonBody();
        $canal = $datos['canal'] ?? 'Presencial';
        $unidad = $datos['unidad_negocio'] ?? '';

        if (!in_array($unidad, ['Pastelería', 'Heladería'], true)) {
            Response::error("Debes indicar 'unidad_negocio': 'Pastelería' o 'Heladería'.", 400);
            return;
        }

        try {
            $venta = new Venta([
                'canal'          => $canal,
                'unidad_negocio' => $unidad,
                'id_empleado'    => Sesion::obtenerId(),
            ]);
            $venta->crear();

            Response::exito($this->serializarVenta($venta), 'Venta abierta exitosamente.', 201);
        } catch (Exception $e) {
            Response::error('No se pudo abrir la venta: ' . $e->getMessage(), 500);
        }
    }

    /**
     * agregarProducto($idVenta)
     * ------------------------------------------------------------
     * POST /api/ventas/{id}/productos
     * Body: { "id_producto": 3, "cantidad": 2 }
     * Implementa el CU007. Reutiliza Venta::añadirProducto() de la
     * Fase 1, que ya valida que haya stock suficiente antes de agregar.
     */
    public function agregarProducto(string $idVenta): void
    {
        $venta = Venta::obtenerPorId((int) $idVenta);
        if ($venta === null) {
            Response::error('La venta indicada no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        $idProducto = $datos['id_producto'] ?? null;
        $cantidad = $datos['cantidad'] ?? null;

        if (!is_numeric($idProducto) || !is_numeric($cantidad) || (int) $cantidad <= 0) {
            Response::error("Debes indicar 'id_producto' y una 'cantidad' mayor a 0.", 400);
            return;
        }

        try {
            $venta->añadirProducto((int) $idProducto, (int) $cantidad);
            Response::exito($this->serializarVenta($venta), 'Producto agregado a la venta exitosamente.');
        } catch (Exception $e) {
            // Aquí caen los errores esperados de negocio (stock
            // insuficiente, venta no activa, producto inexistente).
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * finalizar($idVenta)
     * ------------------------------------------------------------
     * POST /api/ventas/{id}/finalizar
     *
     * ESTE ES EL MÉTODO QUE IMPLEMENTA EL CU008 DE PUNTA A PUNTA:
     *   1. Venta::finalizar() (Fase 1) descuenta el stock de cada
     *      producto vendido Y descuenta proporcionalmente la materia
     *      prima de cada uno, según su receta. Todo en una transacción.
     *   2. Una vez la venta quedó finalizada con éxito, este controlador
     *      registra el INGRESO correspondiente en la Caja del día de
     *      la unidad de negocio y canal de esa venta (CU018), usando
     *      GestorVentas::obtenerOCrearCajaDelDia() + registrarIngreso().
     *
     * Si el paso 1 falla (ej. no hay suficiente materia prima), la
     * transacción interna de Venta::finalizar() revierte todo y este
     * método NUNCA llega a tocar la Caja, evitando registrar un
     * ingreso de una venta que en realidad no se pudo completar.
     */
    public function finalizar(string $idVenta): void
    {
        $venta = Venta::obtenerPorId((int) $idVenta);
        if ($venta === null) {
            Response::error('La venta indicada no existe.', 404);
            return;
        }

        try {
            // Paso 1 (CU008): descuenta stock de productos + materia prima.
            $venta->finalizar();

            // Paso 1.5 (Fase 4): revisa si alguna materia prima usada en
            // esta venta quedó en stock bajo, y genera su alerta si aplica.
            $this->generarAlertasPorVenta($venta);

            // Paso 2 (CU018): registra el ingreso en la caja del día
            // correspondiente a la unidad de negocio y canal de la venta.
            $caja = GestorVentas::obtenerOCrearCajaDelDia(
                $venta->canal,
                $venta->unidadNegocio,
                date('Y-m-d'),
                $venta->idEmpleado
            );
            $caja->registrarIngreso($venta->total);

            Response::exito([
                'venta' => $this->serializarVenta($venta),
                'caja'  => [
                    'id_gestor'    => $caja->idGestor,
                    'canal'        => $caja->canal,
                    'unidad'       => $caja->unidadNegocio,
                    'total_ventas' => $caja->totalVentas,
                    'saldo'        => $caja->saldo,
                ],
            ], 'Venta finalizada exitosamente. Stock actualizado y venta registrada en caja.');
        } catch (Exception $e) {
            Response::error('No se pudo finalizar la venta: ' . $e->getMessage(), 422);
        }
    }

    /**
     * generarAlertasPorVenta()
     * ------------------------------------------------------------
     * Añadido en la Fase 4. Después de que Venta::finalizar() descontó
     * la materia prima de cada producto vendido (según su receta), este
     * método revisa CADA insumo afectado y, si quedó en stock bajo,
     * genera su alerta automáticamente — reutilizando la misma regla
     * de negocio que ya usa InventarioController (AlertaStock::
     * generarSiAplica(), Fase 3/4), sin duplicar código.
     *
     * $materiasRevisadas evita revisar el mismo insumo dos veces si
     * dos productos distintos de la venta comparten un mismo insumo
     * en su receta (ej. dos postres que ambos usan harina).
     */
    private function generarAlertasPorVenta(Venta $venta): void
    {
        $materiasRevisadas = [];

        foreach ($venta->detalles as $detalle) {
            $lineasReceta = Receta::obtenerPorProducto($detalle->idProducto);

            foreach ($lineasReceta as $linea) {
                if ($linea->idMateria === null || in_array($linea->idMateria, $materiasRevisadas, true)) {
                    continue;
                }
                $materiasRevisadas[] = $linea->idMateria;

                $materia = MateriaPrima::obtenerPorId($linea->idMateria);
                if ($materia !== null) {
                    AlertaStock::generarSiAplica($materia);
                }
            }
        }
    }

    /**
     * anular($idVenta)
     * POST /api/ventas/{id}/anular
     * Implementa la mitad "Anular" del CU008.
     */
    public function anular(string $idVenta): void
    {
        $venta = Venta::obtenerPorId((int) $idVenta);
        if ($venta === null) {
            Response::error('La venta indicada no existe.', 404);
            return;
        }

        try {
            $venta->anularVenta();
            Response::exito($this->serializarVenta($venta), 'Venta anulada exitosamente.');
        } catch (Exception $e) {
            Response::error('No se pudo anular la venta: ' . $e->getMessage(), 500);
        }
    }

    /**
     * listar()
     * ------------------------------------------------------------
     * GET /api/ventas
     * Implementa el CU005 (historial). El Administrador ve TODAS las
     * ventas del sistema; un Cajero solo ve las que él mismo registró
     * (regla de negocio razonable: un cajero no necesita ver ventas
     * de otros turnos para hacer seguimiento del suyo).
     */
    public function listar(): void
    {
        $rol = Sesion::obtenerRol();

        if ($rol === 'Administrador') {
            $ventas = Venta::listarTodas();
        } else {
            $ventas = Venta::obtenerPorEmpleado(Sesion::obtenerId());
        }

        $datos = array_map(fn(Venta $v) => $this->serializarVenta($v), $ventas);
        Response::exito($datos, 'Historial de ventas obtenido correctamente.');
    }

    /**
     * ver($idVenta)
     * GET /api/ventas/{id}
     */
    public function ver(string $idVenta): void
    {
        $venta = Venta::obtenerPorId((int) $idVenta);
        if ($venta === null) {
            Response::error('La venta indicada no existe.', 404);
            return;
        }

        Response::exito($this->serializarVenta($venta));
    }

    /**
     * serializarVenta()
     * Convierte un objeto Venta (con sus DetalleVenta) en un arreglo
     * plano listo para JSON, incluyendo el subtotal de cada línea.
     */
    private function serializarVenta(Venta $venta): array
    {
        return [
            'id_venta'       => $venta->idVenta,
            'fecha'          => $venta->fecha,
            'total'          => $venta->total,
            'canal'          => $venta->canal,
            'unidad_negocio' => $venta->unidadNegocio,
            'estado'         => $venta->estado,
            'id_empleado'    => $venta->idEmpleado,
            'detalles'       => array_map(fn($d) => [
                'id_producto'     => $d->idProducto,
                'cantidad'        => $d->cantidad,
                'precio_unitario' => $d->precioUnitario,
                'subtotal'        => $d->subtotal(),
            ], $venta->detalles),
        ];
    }
}
