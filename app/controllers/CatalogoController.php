<?php
require_once __DIR__ . '/../../models/Producto.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';

/**
 * ==========================================================================
 *  CatalogoController.php
 * ==========================================================================
 *  Implementa el CU011: "Ver Catálogo de Productos". A diferencia de
 *  InventarioController (Fase 3), este controlador es PÚBLICO — no
 *  requiere sesión — porque cualquier visitante de la tienda en línea
 *  debe poder ver los productos antes de registrarse o iniciar sesión.
 *
 *  DECISIÓN DE DISEÑO IMPORTANTE:
 *  El CU011 dice textualmente: "el sistema marca automáticamente como
 *  'Agotado' lo que no tiene stock" — es decir, los productos agotados
 *  SE SIGUEN MOSTRANDO en el catálogo, solo que marcados como
 *  agotados, no se ocultan. Por eso este controlador usa
 *  Producto::listarTodos(false) (TODOS los productos) y agrega un
 *  campo calculado 'agotado' en la respuesta, en vez de filtrar por
 *  la columna `disponible` (que en la Fase 1 se apaga automáticamente
 *  cuando el stock llega a 0, vía Producto::marcarAgotado()).
 * ==========================================================================
 */
class CatalogoController
{
    /**
     * listar()
     * GET /api/catalogo/productos?unidad=Pastelería&buscar=torta
     * Público — sin Middleware.
     */
    public function listar(): void
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

        $datos = array_map(fn(Producto $p) => $this->serializarParaCatalogo($p), $productos);
        Response::exito($datos, 'Catálogo obtenido correctamente.');
    }

    /**
     * ver($id)
     * GET /api/catalogo/productos/{id}
     * Público — sin Middleware.
     */
    public function ver(string $id): void
    {
        $producto = Producto::obtenerPorId((int) $id);
        if ($producto === null) {
            Response::error('El producto solicitado no existe.', 404);
            return;
        }

        Response::exito($this->serializarParaCatalogo($producto));
    }

    /**
     * serializarParaCatalogo()
     * ------------------------------------------------------------
     * A diferencia de Producto::obtenerDatos() (usado en el panel
     * interno de InventarioController), esta versión pública agrega
     * el campo 'agotado' explícito y NO expone el campo 'disponible'
     * crudo de la base de datos, para no filtrar detalles internos
     * innecesarios al frontend público.
     */
    private function serializarParaCatalogo(Producto $p): array
    {
        return [
            'id_producto'    => $p->idProducto,
            'nombre'         => $p->nombre,
            'descripcion'    => $p->descripcion,
            'tipo'           => $p->tipo,
            'unidad_negocio' => $p->unidadNegocio,
            'precio'         => $p->precio,
            'foto'           => $p->foto,
            'agotado'        => !$p->disponible || $p->stock <= 0,
        ];
    }
}
