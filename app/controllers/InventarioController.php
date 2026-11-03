<?php
require_once __DIR__ . '/../../models/Producto.php';
require_once __DIR__ . '/../../models/MateriaPrima.php';
require_once __DIR__ . '/../../models/Receta.php';
require_once __DIR__ . '/../../models/AlertaStock.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';

class InventarioController
{
   
    public function listarProductos(): void
    {
        $unidad = Request::query('unidad');
        $buscar = Request::query('buscar');

        if ($buscar !== null && trim($buscar) !== '') {
            $productos = Producto::buscarPorNombre(trim($buscar));
        } elseif ($unidad !== null && trim($unidad) !== '') {
            $productos = Producto::listarPorUnidadNegocio(trim($unidad));
        } else {
    $productos = Producto::listarTodos(true);  
}

        $datos = array_map(fn(Producto $p) => $p->obtenerDatos(), $productos);
        Response::exito($datos, 'Productos obtenidos correctamente.');
    }

    public function verProducto(string $id): void
    {
        $producto = Producto::obtenerPorId((int) $id);
        if ($producto === null) {
            Response::error('El producto solicitado no existe.', 404);
            return;
        }

        Response::exito($producto->obtenerDatos());
    }

    public function crearProducto(): void
    {
        $datos = Request::jsonBody();
        $errores = $this->validarProducto($datos);
        if (!empty($errores)) {
            Response::error(implode(' ', $errores), 422);
            return;
        }

        [$fotoBinaria, $errorFoto] = $this->decodificarFotoBase64($datos['foto_base64'] ?? null);
        if ($errorFoto !== null) {
            Response::error($errorFoto, 422);
            return;
        }

        try {
            $producto = new Producto($datos);
            $producto->fotoBinaria = $fotoBinaria;
            $producto->crear();
            Response::exito($producto->obtenerDatos(), 'Producto creado exitosamente.', 201);
        } catch (Exception $e) {
            Response::error('No se pudo crear el producto: ' . $e->getMessage(), 500);
        }
    }

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

        [$fotoBinaria, $errorFoto] = $this->decodificarFotoBase64($datos['foto_base64'] ?? null);
        if ($errorFoto !== null) {
            Response::error($errorFoto, 422);
            return;
        }

        $producto->nombre        = trim($datos['nombre']);
        $producto->descripcion   = $datos['descripcion'] ?? null;
        $producto->tipo          = $datos['tipo'] ?? null;
        $producto->unidadNegocio = $datos['unidad_negocio'];
        $producto->precio        = (float) $datos['precio'];
        $producto->stock         = isset($datos['stock']) ? (int) $datos['stock'] : $producto->stock;
        $producto->fotoBinaria   = $fotoBinaria; 
        $producto->disponible    = isset($datos['disponible']) ? (bool) $datos['disponible'] : $producto->disponible;

        try {
            $producto->actualizar();
            Response::exito($producto->obtenerDatos(), 'Producto actualizado exitosamente.');
        } catch (Exception $e) {
            Response::error('No se pudo actualizar el producto: ' . $e->getMessage(), 500);
        }
    }

    public function fotoProducto(string $id): void
    {
        $bytes = Producto::obtenerFotoBinaria((int) $id);

        if (ob_get_level() > 0) {
            ob_clean();
        }

        if ($bytes === null) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Este producto no tiene una foto guardada.';
            return;
        }

        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $info = finfo_open(FILEINFO_MIME_TYPE);
            $detectado = finfo_buffer($info, $bytes);
            finfo_close($info);
            if ($detectado) {
                $mime = $detectado;
            }
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: public, max-age=86400'); 
        echo $bytes;
    }

    private function decodificarFotoBase64(?string $valor): array
    {
        if ($valor === null || trim($valor) === '') {
            return [null, null]; 
        }

        
        if (str_contains($valor, ',') && str_starts_with($valor, 'data:')) {
            $valor = substr($valor, strpos($valor, ',') + 1);
        }

        $bytes = base64_decode($valor, true);
        if ($bytes === false) {
            return [null, 'La imagen enviada no tiene un formato base64 válido.'];
        }

        $maxBytes = 4 * 1024 * 1024; 
        if (strlen($bytes) > $maxBytes) {
            return [null, 'La imagen es demasiado pesada (máximo 4MB).'];
        }

        return [$bytes, null];
    }

    public function eliminarProducto(string $id): void
    {
        $producto = Producto::obtenerPorId((int) $id);
        if ($producto === null) {
            Response::error('No se pudo eliminar el producto.', 409);
            return;
        }

        try {
            $producto->eliminar();
            Response::exito([], 'Producto eliminado exitosamente.');
        } catch (Exception $e) {
            
            
            
            Response::error('No se pudo eliminar: es posible que el producto ya tenga ventas o pedidos asociados.', 409);
        }
    }

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

    public function listarMateriasPrimas(): void
    {
        $materias = MateriaPrima::listarTodas();
        $datos = array_map(fn(MateriaPrima $m) => $this->serializarMateria($m), $materias);
        Response::exito($datos);
    }

    
    public function listarMateriasBajoStock(): void
    {
        $materias = MateriaPrima::listarConStockBajo();
        $datos = array_map(fn(MateriaPrima $m) => $this->serializarMateria($m), $materias);
        Response::exito($datos, 'Insumos con stock igual o por debajo del mínimo.');
    }

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

            
            if ($materia->tieneStockBajo()) {
                
                AlertaStock::generarSiAplica($materia);
            } else {
                
                AlertaStock::desactivarPorMateria($materia->idMateria);
            }
            

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
  
    public function verRecetaDeProducto(string $idProducto): void
    {
        $lineas = Receta::obtenerPorProducto((int) $idProducto);

        $datos = array_map(fn(Receta $r) => [
            'id_receta'      => $r->idReceta,
            'id_materia'     => $r->idMateria,
            'cantidad'       => $r->cantidad,
            'nombre_materia' => $r->nombreMateria, 
            'unidad_medida'  => $r->unidadMedida   
        ], $lineas);

        Response::exito($datos, 'Receta obtenida correctamente.');
    }
 
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
 
    public function listarAlertas(): void
    {
        $alertas = AlertaStock::listarActivas();

        $datos = array_map(fn(AlertaStock $a) => [
            'id_alerta'      => $a->idAlerta,
            'id_materia'     => $a->idMateria,
            'id_producto'    => $a->idProducto,
            'umbral'         => $a->umbral,
            'fecha_generada' => $a->fechaGenerada,
            'nombre_materia' => $a->nombreMateria, 
            'mensaje'        => $a->mensaje,
            'estado'         => $a->estado
        ], $alertas);

        Response::exito($datos, 'Alertas activas obtenidas correctamente.');
    }

    
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
