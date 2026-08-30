<?php
require_once __DIR__ . '/../../models/Producto.php';
require_once __DIR__ . '/../../models/MateriaPrima.php';
require_once __DIR__ . '/../../models/Receta.php';
require_once __DIR__ . '/../../models/AlertaStock.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';

/**
 * ==========================================================================
 *  InventarioController.php
 * ==========================================================================
 *  Implementa 3 Casos de Uso relacionados con el control de existencias:
 *
 *    - CU002: Gestionar Inventario (crear/actualizar/eliminar productos).
 *    - CU009: Consultar Inventario (listar/ver productos y su stock).
 *    - CU019: Gestionar Materias Primas (insumos, umbrales, alertas).
 *
 *  NOTA: el descuento AUTOMÁTICO de stock al finalizar una venta (CU008)
 *  NO vive aquí, sino en VentaController::finalizar(), porque es ahí
 *  donde ocurre el evento que lo dispara (una venta finalizada). Este
 *  controlador se encarga de la gestión MANUAL del inventario (que un
 *  Administrador/Cajero dan de alta o editan productos e insumos).
 *
 *  Todas las rutas de este controlador están protegidas con
 *  Middleware::rol([...]) en routes.php — ver ese archivo para saber
 *  exactamente qué rol puede hacer qué.
 * ==========================================================================
 */
class InventarioController
{
    // =================================================================
    //  PRODUCTOS (CU002 / CU009)
    // =================================================================

    /**
     * listarProductos()
     * GET /api/inventario/productos?unidad=Pastelería&buscar=torta
     * Soporta filtros opcionales por unidad de negocio o por texto de búsqueda.
     * Trae TODOS los productos (incluso los no disponibles), a diferencia
     * del catálogo público que se construirá en la Fase 5 (CU011).
     */
    public function listarProductos(): void
    {
        $unidad = Request::query('unidad');
        $buscar = Request::query('buscar');

        if ($buscar !== null && trim($buscar) !== '') {
            $productos = Producto::buscarPorNombre(trim($buscar));
        } elseif ($unidad !== null && trim($unidad) !== '') {
            $productos = Producto::listarPorUnidadNegocio(trim($unidad));
        } else {
            $productos = Producto::listarTodos(false);
        }

        $datos = array_map(fn(Producto $p) => $p->obtenerDatos(), $productos);
        Response::exito($datos, 'Productos obtenidos correctamente.');
    }

    /**
     * verProducto($id)
     * GET /api/inventario/productos/{id}
     */
    public function verProducto(string $id): void
    {
        $producto = Producto::obtenerPorId((int) $id);
        if ($producto === null) {
            Response::error('El producto solicitado no existe.', 404);
            return;
        }

        Response::exito($producto->obtenerDatos());
    }

    /**
     * crearProducto()
     * POST /api/inventario/productos
     * Body: { nombre, descripcion?, tipo?, unidad_negocio, precio, stock?, foto? }
     */
    public function crearProducto(): void
    {
        $datos = Request::jsonBody();
        $errores = $this->validarProducto($datos);
        if (!empty($errores)) {
            Response::error(implode(' ', $errores), 422);
            return;
        }

        try {
            $producto = new Producto($datos);
            $producto->crear();
            Response::exito($producto->obtenerDatos(), 'Producto creado exitosamente.', 201);
        } catch (Exception $e) {
            Response::error('No se pudo crear el producto: ' . $e->getMessage(), 500);
        }
    }

    /**
     * actualizarProducto($id)
     * PUT /api/inventario/productos/{id}
     * Actualiza TODOS los campos del producto a la vez. Para cambios
     * puntuales de solo el stock existe un endpoint más específico
     * abajo (ajustarStockProducto), que reutiliza la validación de
     * negocio de Producto::actualizarStock() de la Fase 1.
     */
    public function actualizarProducto(string $id): void
    {
        $producto = Producto::obtenerPorId((int) $id);
        if ($producto === null) {
            Response::error('El producto solicitado no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        $errores = $this->validarProducto($datos);
        if (!empty($errores)) {
            Response::error(implode(' ', $errores), 422);
            return;
        }

        $producto->nombre        = trim($datos['nombre']);
        $producto->descripcion   = $datos['descripcion'] ?? null;
        $producto->tipo          = $datos['tipo'] ?? null;
        $producto->unidadNegocio = $datos['unidad_negocio'];
        $producto->precio        = (float) $datos['precio'];
        $producto->stock         = isset($datos['stock']) ? (int) $datos['stock'] : $producto->stock;
        $producto->foto          = $datos['foto'] ?? $producto->foto;
        $producto->disponible    = isset($datos['disponible']) ? (bool) $datos['disponible'] : $producto->disponible;

        try {
            $producto->actualizar();
            Response::exito($producto->obtenerDatos(), 'Producto actualizado exitosamente.');
        } catch (Exception $e) {
            Response::error('No se pudo actualizar el producto: ' . $e->getMessage(), 500);
        }
    }

    /**
     * eliminarProducto($id)
     * DELETE /api/inventario/productos/{id}
     */
    public function eliminarProducto(string $id): void
    {
        $producto = Producto::obtenerPorId((int) $id);
        if ($producto === null) {
            Response::error('El producto solicitado no existe.', 404);
            return;
        }

        try {
            $producto->eliminar();
            Response::exito([], 'Producto eliminado exitosamente.');
        } catch (Exception $e) {
            // Si el producto ya tiene ventas/pedidos asociados, la BD
            // rechaza el borrado por llave foránea. Se traduce a un
            // mensaje amigable en vez del error crudo de SQL.
            Response::error('No se pudo eliminar: es posible que el producto ya tenga ventas o pedidos asociados.', 409);
        }
    }

    /**
     * ajustarStockProducto($id)
     * POST /api/inventario/productos/{id}/ajustar-stock
     * Body: { "cantidad": 10 }  (positivo para sumar, negativo para restar)
     * Reutiliza Producto::actualizarStock() de la Fase 1, que ya valida
     * que el stock nunca pueda quedar negativo.
     */
    public function ajustarStockProducto(string $id): void
    {
        $producto = Producto::obtenerPorId((int) $id);
        if ($producto === null) {
            Response::error('El producto solicitado no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        if (!isset($datos['cantidad']) || !is_numeric($datos['cantidad'])) {
            Response::error("Debes indicar 'cantidad' (numérica, positiva o negativa).", 400);
            return;
        }

        try {
            $producto->actualizarStock((int) $datos['cantidad']);
            Response::exito($producto->obtenerDatos(), 'Stock actualizado exitosamente.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    private function validarProducto(array $datos): array
    {
        $errores = [];
        if (trim($datos['nombre'] ?? '') === '') {
            $errores[] = 'El nombre del producto es obligatorio.';
        }
        if (!in_array($datos['unidad_negocio'] ?? '', ['Pastelería', 'Heladería'], true)) {
            $errores[] = "La unidad de negocio debe ser 'Pastelería' o 'Heladería'.";
        }
        if (!isset($datos['precio']) || !is_numeric($datos['precio']) || (float) $datos['precio'] < 0) {
            $errores[] = 'El precio debe ser un número mayor o igual a 0.';
        }
        return $errores;
    }

    // =================================================================
    //  MATERIAS PRIMAS (CU019)
    // =================================================================

    /** GET /api/inventario/materias-primas */
    public function listarMateriasPrimas(): void
    {
        $materias = MateriaPrima::listarTodas();
        $datos = array_map(fn(MateriaPrima $m) => $this->serializarMateria($m), $materias);
        Response::exito($datos);
    }

    /** GET /api/inventario/materias-primas/bajo-stock */
    public function listarMateriasBajoStock(): void
    {
        $materias = MateriaPrima::listarConStockBajo();
        $datos = array_map(fn(MateriaPrima $m) => $this->serializarMateria($m), $materias);
        Response::exito($datos, 'Insumos con stock igual o por debajo del mínimo.');
    }

    /** POST /api/inventario/materias-primas — Body: { nombre, unidad_medida?, stock_actual?, stock_minimo } */
    public function crearMateriaPrima(): void
    {
        $datos = Request::jsonBody();
        if (trim($datos['nombre'] ?? '') === '') {
            Response::error('El nombre del insumo es obligatorio.', 422);
            return;
        }
        if (!isset($datos['stock_minimo']) || !is_numeric($datos['stock_minimo'])) {
            Response::error('Debes indicar el stock mínimo (umbral de alerta) para este insumo.', 422);
            return;
        }

        $materia = new MateriaPrima($datos);
        $materia->crear();
        Response::exito($this->serializarMateria($materia), 'Materia prima registrada exitosamente.', 201);
    }

    /** PUT /api/inventario/materias-primas/{id} */
    public function actualizarMateriaPrima(string $id): void
    {
        $materia = MateriaPrima::obtenerPorId((int) $id);
        if ($materia === null) {
            Response::error('El insumo solicitado no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        $materia->nombre       = trim($datos['nombre'] ?? $materia->nombre);
        $materia->unidadMedida = $datos['unidad_medida'] ?? $materia->unidadMedida;
        $materia->stockMinimo  = isset($datos['stock_minimo']) ? (float) $datos['stock_minimo'] : $materia->stockMinimo;

        $materia->actualizar();
        Response::exito($this->serializarMateria($materia), 'Materia prima actualizada exitosamente.');
    }

    /** DELETE /api/inventario/materias-primas/{id} */
    public function eliminarMateriaPrima(string $id): void
    {
        $materia = MateriaPrima::obtenerPorId((int) $id);
        if ($materia === null) {
            Response::error('El insumo solicitado no existe.', 404);
            return;
        }

        try {
            $materia->eliminar();
            Response::exito([], 'Materia prima eliminada exitosamente.');
        } catch (Exception $e) {
            Response::error('No se pudo eliminar: es posible que el insumo esté siendo usado en una o más recetas.', 409);
        }
    }

    /**
     * ajustarStockMateria($id)
     * POST /api/inventario/materias-primas/{id}/ajustar-stock
     * Body: { "tipo": "aumentar"|"descontar", "cantidad": 5 }
     *
     * Además de ajustar el stock, implementa la parte "activa" del
     * CU019: si tras el ajuste el insumo queda en stock bajo, se
     * genera automáticamente una AlertaStock (evitando duplicados si
     * ya existe una alerta activa para ese mismo insumo).
     */
    public function ajustarStockMateria(string $id): void
    {
        $materia = MateriaPrima::obtenerPorId((int) $id);
        if ($materia === null) {
            Response::error('El insumo solicitado no existe.', 404);
            return;
        }

        $datos = Request::jsonBody();
        $tipo = $datos['tipo'] ?? '';
        $cantidad = isset($datos['cantidad']) ? (float) $datos['cantidad'] : null;

        if (!in_array($tipo, ['aumentar', 'descontar'], true) || $cantidad === null || $cantidad <= 0) {
            Response::error("Debes indicar 'tipo' ('aumentar' o 'descontar') y una 'cantidad' positiva.", 400);
            return;
        }

        try {
            if ($tipo === 'aumentar') {
                $materia->aumentarStock($cantidad);
            } else {
                $materia->descontarStock($cantidad);
            }

            AlertaStock::generarSiAplica($materia);

            Response::exito($this->serializarMateria($materia), 'Stock de materia prima actualizado exitosamente.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    private function serializarMateria(MateriaPrima $m): array
    {
        return [
            'id_materia'    => $m->idMateria,
            'nombre'        => $m->nombre,
            'unidad_medida' => $m->unidadMedida,
            'stock_actual'  => $m->stockActual,
            'stock_minimo'  => $m->stockMinimo,
            'stock_bajo'    => $m->tieneStockBajo(),
        ];
    }

    // =================================================================
    //  RECETAS (vínculo Producto <-> MateriaPrima, soporte del CU008)
    // =================================================================

    /** GET /api/inventario/productos/{id}/receta */
    public function verRecetaDeProducto(string $idProducto): void
    {
        $lineas = Receta::obtenerPorProducto((int) $idProducto);

        $datos = array_map(fn(Receta $r) => [
            'id_receta'  => $r->idReceta,
            'id_materia' => $r->idMateria,
            'cantidad'   => $r->cantidad,
        ], $lineas);

        Response::exito($datos, 'Receta obtenida correctamente.');
    }

    /**
     * agregarLineaReceta()
     * POST /api/inventario/recetas
     * Body: { "id_producto": 3, "id_materia": 1, "cantidad": 0.5 }
     * Define cuánta materia prima consume 1 unidad del producto. Esta
     * tabla es la que usará VentaController::finalizar() (CU008) para
     * saber qué insumos descontar cuando se vende ese producto.
     */
    public function agregarLineaReceta(): void
    {
        $datos = Request::jsonBody();

        if (!isset($datos['id_producto'], $datos['id_materia'], $datos['cantidad'])) {
            Response::error("Debes indicar 'id_producto', 'id_materia' y 'cantidad'.", 400);
            return;
        }
        if (!is_numeric($datos['cantidad']) || (float) $datos['cantidad'] <= 0) {
            Response::error('La cantidad debe ser un número mayor a 0.', 422);
            return;
        }

        $producto = Producto::obtenerPorId((int) $datos['id_producto']);
        $materia = MateriaPrima::obtenerPorId((int) $datos['id_materia']);
        if ($producto === null || $materia === null) {
            Response::error('El producto o la materia prima indicados no existen.', 404);
            return;
        }

        $receta = new Receta($datos);
        $receta->crear();

        Response::exito(['id_receta' => $receta->idReceta], 'Línea de receta agregada exitosamente.', 201);
    }

    /** DELETE /api/inventario/recetas/{id} */
    public function eliminarLineaReceta(string $id): void
    {
        $receta = Receta::obtenerPorId((int) $id);
        if ($receta === null) {
            Response::error('La línea de receta solicitada no existe.', 404);
            return;
        }

        $receta->eliminar();
        Response::exito([], 'Línea de receta eliminada exitosamente.');
    }

    // =================================================================
    //  ALERTAS DE STOCK (parte visible del CU019)
    // =================================================================

    /** GET /api/inventario/alertas */
    public function listarAlertas(): void
    {
        $alertas = AlertaStock::listarActivas();

        $datos = array_map(fn(AlertaStock $a) => [
            'id_alerta'      => $a->idAlerta,
            'id_materia'     => $a->idMateria,
            'id_producto'    => $a->idProducto,
            'umbral'         => $a->umbral,
            'fecha_generada' => $a->fechaGenerada,
        ], $alertas);

        Response::exito($datos, 'Alertas activas obtenidas correctamente.');
    }

    /** POST /api/inventario/alertas/{id}/atender */
    public function atenderAlerta(string $id): void
    {
        $alerta = AlertaStock::obtenerPorId((int) $id);
        if ($alerta === null) {
            Response::error('La alerta solicitada no existe.', 404);
            return;
        }

        $alerta->marcarComoAtendida();
        Response::exito([], 'Alerta marcada como atendida.');
    }
}
