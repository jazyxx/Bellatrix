<?php
require_once __DIR__ . '/../../models/GestorVentas.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

/**
 * ==========================================================================
 *  CajaController.php
 * ==========================================================================
 *  Implementa el CU018: "Gestionar Cajas por Unidad" y su regla más
 *  importante, "Bloqueo de egresos si superan el saldo disponible en
 *  la caja de la unidad seleccionada".
 *
 *  RECORDATORIO CLAVE (ya explicado en la Fase 1, GestorVentas.php):
 *  cada combinación de (canal, unidad_negocio, fecha) es una CAJA
 *  independiente. Esto es lo que garantiza que el dinero de Pastelería
 *  nunca se mezcle con el de Heladería, ni el de ventas Presenciales
 *  con las de En línea, tal como pediste.
 *
 *  Los INGRESOS por ventas se registran automáticamente desde
 *  VentaController::finalizar() (CU008). Este controlador se encarga
 *  de: consultar el estado de una caja, registrar EGRESOS (con el
 *  bloqueo de saldo), ingresos manuales (ajustes), y los reportes.
 * ==========================================================================
 */
class CajaController
{
    /**
     * hoy()
     * ------------------------------------------------------------
     * GET /api/cajas/hoy?canal=Presencial&unidad=Pastelería
     * Devuelve (o crea en 0 si es la primera consulta del día) la
     * caja de HOY para la combinación canal + unidad indicada.
     */
    public function hoy(): void
    {
        [$canal, $unidad, $error] = $this->leerCanalYUnidad();
        if ($error !== null) {
            Response::error($error, 400);
            return;
        }

        $caja = GestorVentas::obtenerOCrearCajaDelDia($canal, $unidad, date('Y-m-d'), Sesion::obtenerId());
        Response::exito($this->serializarCaja($caja));
    }

    /**
     * registrarEgreso()
     * ------------------------------------------------------------
     * POST /api/cajas/egreso
     * Body: { "canal", "unidad_negocio", "monto", "fecha"? }
     *
     * IMPLEMENTA LA REGLA CENTRAL DEL CU018: el egreso se RECHAZA
     * automáticamente si supera el saldo disponible en esa caja
     * específica. Esta validación en realidad ya vive dentro de
     * GestorVentas::registrarEgreso() (Fase 1) — aquí solo se traduce
     * la excepción que lanza ese método en una respuesta HTTP 422
     * clara para el frontend.
     */
    public function registrarEgreso(): void
    {
        $datos = Request::jsonBody();
        [$canal, $unidad, $error] = $this->leerCanalYUnidad($datos);
        if ($error !== null) {
            Response::error($error, 400);
            return;
        }

        $monto = $datos['monto'] ?? null;
        if (!is_numeric($monto) || (float) $monto <= 0) {
            Response::error('Debes indicar un monto de egreso mayor a 0.', 400);
            return;
        }

        $fecha = $datos['fecha'] ?? date('Y-m-d');

        $caja = GestorVentas::obtenerOCrearCajaDelDia($canal, $unidad, $fecha, Sesion::obtenerId());

        try {
            $caja->registrarEgreso((float) $monto);
            Response::exito($this->serializarCaja($caja), 'Egreso registrado exitosamente.');
        } catch (Exception $e) {
            // Este es EXACTAMENTE el caso del CU018: el egreso superó
            // el saldo disponible. GestorVentas::registrarEgreso() ya
            // armó un mensaje claro indicando el monto y el saldo real.
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * registrarIngresoManual()
     * ------------------------------------------------------------
     * POST /api/cajas/ingreso
     * Body: { "canal", "unidad_negocio", "monto", "fecha"? }
     *
     * Los ingresos por ventas se registran SOLOS (ver VentaController::
     * finalizar()). Este endpoint es para ajustes manuales excepcionales
     * que el Administrador necesite hacer (ej. un ingreso que no vino
     * de una venta registrada en el sistema, como un abono externo).
     */
    public function registrarIngresoManual(): void
    {
        $datos = Request::jsonBody();
        [$canal, $unidad, $error] = $this->leerCanalYUnidad($datos);
        if ($error !== null) {
            Response::error($error, 400);
            return;
        }

        $monto = $datos['monto'] ?? null;
        if (!is_numeric($monto) || (float) $monto <= 0) {
            Response::error('Debes indicar un monto de ingreso mayor a 0.', 400);
            return;
        }

        $fecha = $datos['fecha'] ?? date('Y-m-d');
        $caja = GestorVentas::obtenerOCrearCajaDelDia($canal, $unidad, $fecha, Sesion::obtenerId());
        $caja->registrarIngreso((float) $monto);

        Response::exito($this->serializarCaja($caja), 'Ingreso manual registrado exitosamente.');
    }

    /**
     * porUnidad($unidad)
     * GET /api/cajas/unidad/{unidad}
     * Implementa GestorVentas::separarPorUnidad() del Diagrama de Clases:
     * historial completo de cajas de UNA unidad de negocio.
     */
    public function porUnidad(string $unidad): void
    {
        if (!in_array($unidad, ['Pastelería', 'Heladería'], true)) {
            Response::error("La unidad debe ser 'Pastelería' o 'Heladería'.", 400);
            return;
        }

        $cajas = GestorVentas::separarPorUnidad($unidad);
        $datos = array_map(fn(GestorVentas $c) => $this->serializarCaja($c), $cajas);
        Response::exito($datos);
    }

    /**
     * porCanal($canal)
     * GET /api/cajas/canal/{canal}
     * Implementa GestorVentas::separarPorCanal().
     */
    public function porCanal(string $canal): void
    {
        if (!in_array($canal, ['Presencial', 'En línea'], true)) {
            Response::error("El canal debe ser 'Presencial' o 'En línea'.", 400);
            return;
        }

        $cajas = GestorVentas::separarPorCanal($canal);
        $datos = array_map(fn(GestorVentas $c) => $this->serializarCaja($c), $cajas);
        Response::exito($datos);
    }

    /** GET /api/cajas/reportes/diario?fecha=2026-07-29 */
    public function reporteDiario(): void
    {
        $fecha = Request::query('fecha', date('Y-m-d'));
        $cajas = GestorVentas::generarReporteDiario($fecha);
        Response::exito(array_map(fn(GestorVentas $c) => $this->serializarCaja($c), $cajas));
    }

    /** GET /api/cajas/reportes/semanal?inicio=2026-07-27 */
    public function reporteSemanal(): void
    {
        $inicio = Request::query('inicio');
        if ($inicio === null) {
            Response::error("Debes indicar la fecha de 'inicio' de la semana (formato AAAA-MM-DD).", 400);
            return;
        }

        $cajas = GestorVentas::generarReporteSemanal($inicio);
        Response::exito(array_map(fn(GestorVentas $c) => $this->serializarCaja($c), $cajas));
    }

    /** GET /api/cajas/reportes/mensual?anio=2026&mes=7 */
    public function reporteMensual(): void
    {
        $anio = Request::query('anio');
        $mes = Request::query('mes');
        if (!is_numeric($anio) || !is_numeric($mes)) {
            Response::error("Debes indicar 'anio' y 'mes' como números.", 400);
            return;
        }

        $cajas = GestorVentas::generarReporteMensual((int) $anio, (int) $mes);
        Response::exito(array_map(fn(GestorVentas $c) => $this->serializarCaja($c), $cajas));
    }

    /** GET /api/cajas/reportes/rango?inicio=2026-07-01&fin=2026-07-29 */
    public function reporteRango(): void
    {
        $inicio = Request::query('inicio');
        $fin = Request::query('fin');
        if ($inicio === null || $fin === null) {
            Response::error("Debes indicar 'inicio' y 'fin' (formato AAAA-MM-DD).", 400);
            return;
        }

        $cajas = GestorVentas::generarReportePorRango($inicio, $fin);
        Response::exito(array_map(fn(GestorVentas $c) => $this->serializarCaja($c), $cajas));
    }

    /**
     * leerCanalYUnidad()
     * ------------------------------------------------------------
     * Método auxiliar privado: lee y valida 'canal' y 'unidad_negocio',
     * ya sea desde el query string (GET) o desde el body JSON (POST),
     * según cuál venga disponible. Devuelve [canal, unidad, error].
     * Si $error es distinto de null, los otros dos valores deben
     * ignorarse porque la validación falló.
     */
    private function leerCanalYUnidad(?array $datosBody = null): array
    {
        $canal = $datosBody['canal'] ?? Request::query('canal');
        $unidad = $datosBody['unidad_negocio'] ?? Request::query('unidad');

        if (!in_array($canal, ['Presencial', 'En línea'], true)) {
            return [null, null, "Debes indicar 'canal': 'Presencial' o 'En línea'."];
        }
        if (!in_array($unidad, ['Pastelería', 'Heladería'], true)) {
            return [null, null, "Debes indicar 'unidad_negocio': 'Pastelería' o 'Heladería'."];
        }

        return [$canal, $unidad, null];
    }

    private function serializarCaja(GestorVentas $c): array
    {
        return [
            'id_gestor'      => $c->idGestor,
            'canal'          => $c->canal,
            'unidad_negocio' => $c->unidadNegocio,
            'fecha'          => $c->fecha,
            'total_ventas'   => $c->totalVentas,
            'total_egresos'  => $c->totalEgresos,
            'saldo'          => $c->saldo,
        ];
    }
}
