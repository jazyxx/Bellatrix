<?php
require_once __DIR__ . '/../../models/Producto.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';

class CatalogoController
{
 
public function listar(): void
    {
        $unidad = Request::query('unidad');
        $buscar = Request::query('buscar');

        if ($buscar !== null && trim($buscar) !== '') {
        $productos = Producto::buscarPorNombre(trim($buscar), true);   
        } elseif ($unidad !== null && trim($unidad) !== '') {
         $productos = Producto::listarPorUnidadNegocio(trim($unidad), true);  /
        } else {
         $productos = Producto::listarTodos(true);  
        }

        
        $productosFiltrados = array_filter($productos, function (Producto $p) {
            $tipo   = strtolower(trim($p->tipo ?? ''));
            $nombre = strtolower(trim($p->nombre ?? ''));

            
            if (strpos($tipo, 'personalizad') !== false || strpos($nombre, 'personalizad') !== false) {
                return false;
            }

            return true;
        });

        $datos = array_map(fn(Producto $p) => $this->serializarParaCatalogo($p), array_values($productosFiltrados));
        Response::exito($datos, 'Catálogo obtenido correctamente.');
    }

    public function ver(string $id): void
    {
        $producto = Producto::obtenerPorId((int) $id);
        if ($producto === null) {
            Response::error('El producto solicitado no existe.', 404);
            return;
        }

        Response::exito($this->serializarParaCatalogo($producto));
    }

    private function serializarParaCatalogo(Producto $p): array
    {
        return [
            'id_producto'    => $p->idProducto,
            'nombre'         => $p->nombre,
            'descripcion'    => $p->descripcion,
            'tipo'           => $p->tipo,
            'unidad_negocio' => $p->unidadNegocio,
            'precio'         => $p->precio,
            'tiene_foto'     => $p->tieneFoto,
            'agotado'        => !$p->disponible || $p->stock <= 0,
        ];
    }
}
