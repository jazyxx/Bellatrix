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

class VentaController
{
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
                
            Response::error($e->getMessage(), 422);
        }
    }

    public function finalizar(string $idVenta): void
    {
        $venta = Venta::obtenerPorId((int) $idVenta);
        if ($venta === null) {
            Response::error('La venta indicada no existe.', 404);
            return;
        }
        try {
            $venta->finalizar();

            $this->generarAlertasPorVenta($venta);

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

    public function anular(string $idVenta): void
    {
        $venta = Venta::obtenerPorId((int) $idVenta);
        if ($venta === null) {
            Response::error('La venta indicada no existe.', 404);
            return;
        }

        if ($venta->idPedido !== null) {
            Response::error('Esta venta proviene de un pedido en línea. Para revertirla cancela el pedido desde Gestión de pedidos.', 409);
            return;
        }

        try {
            $venta->anularVenta();
            Response::exito($this->serializarVenta($venta), 'Venta anulada exitosamente.');
        } catch (Exception $e) {
            Response::error('No se pudo anular la venta: ' . $e->getMessage(), 500);
        }
    }

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

    public function ver(string $idVenta): void
    {
        $venta = Venta::obtenerPorId((int) $idVenta);
        if ($venta === null) {
            Response::error('La venta indicada no existe.', 404);
            return;
        }

        Response::exito($this->serializarVenta($venta));
    }

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
            'nombre_empleado' => $venta->nombreEmpleado ?? null,
            'id_pedido'      => $venta->idPedido ?? null,
            'detalles'       => array_map(fn($d) => [
                'id_producto'     => $d->idProducto,
                'nombre_producto' => $d->nombreProducto ?? null,
                'cantidad'        => $d->cantidad,
                'precio_unitario' => $d->precioUnitario,
                'subtotal'        => $d->subtotal(),
            ], $venta->detalles),
        ];
    }
}
