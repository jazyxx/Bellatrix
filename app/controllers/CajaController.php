<?php
require_once __DIR__ . '/../../models/GestorVentas.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

class CajaController
{

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
            
            
            
            Response::error($e->getMessage(), 422);
        }
    }

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

    
    public function reporteDiario(): void
    {
        $fecha = Request::query('fecha', date('Y-m-d'));
        $cajas = GestorVentas::generarReporteDiario($fecha);
        Response::exito(array_map(fn(GestorVentas $c) => $this->serializarCaja($c), $cajas));
    }

    
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
