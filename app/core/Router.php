<?php
require_once __DIR__ . '/Response.php';

class Router
{
  
    private array $rutas = [
        'GET'    => [],
        'POST'   => [],
        'PUT'    => [],
        'DELETE' => [],
    ];

    public function get(string $ruta, $handler, array $middlewares = []): void
    {
        $this->registrar('GET', $ruta, $handler, $middlewares);
    }

    public function post(string $ruta, $handler, array $middlewares = []): void
    {
        $this->registrar('POST', $ruta, $handler, $middlewares);
    }

    public function put(string $ruta, $handler, array $middlewares = []): void
    {
        $this->registrar('PUT', $ruta, $handler, $middlewares);
    }

    public function delete(string $ruta, $handler, array $middlewares = []): void
    {
        $this->registrar('DELETE', $ruta, $handler, $middlewares);
    }

   
    private function registrar(string $metodo, string $ruta, $handler, array $middlewares): void
    {
        [$patronRegex, $nombresParametros] = $this->compilarRuta($ruta);

        $this->rutas[$metodo][] = [
            'ruta_original'      => $ruta,
            'patron'             => $patronRegex,
            'nombres_parametros' => $nombresParametros,
            'handler'            => $handler,
            'middlewares'        => $middlewares,
        ];
    }

   
    private function compilarRuta(string $ruta): array
    {
        $nombresParametros = [];
 
        $patron = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function (array $coincidencia) use (&$nombresParametros): string {
                $nombresParametros[] = $coincidencia[1];
                return '(?P<' . $coincidencia[1] . '>[^/]+)';
            },
            $ruta
        );
    
        $patronFinal = '#^' . $patron . '$#';

        return [$patronFinal, $nombresParametros];
    }

    public function despachar(): void
    {
        $metodoActual = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $rutaActual = $this->obtenerRutaActual();

        if (!isset($this->rutas[$metodoActual])) {
            Response::error("Método HTTP no soportado: {$metodoActual}.", 405);
            return;
        }

        foreach ($this->rutas[$metodoActual] as $definicionRuta) {
            if (preg_match($definicionRuta['patron'], $rutaActual, $coincidencias) === 1) {       
                $parametros = [];
                foreach ($definicionRuta['nombres_parametros'] as $nombre) {
                    $parametros[] = $coincidencias[$nombre];
                }
          
                foreach ($definicionRuta['middlewares'] as $middleware) {
                    $puedeContinuar = call_user_func($middleware);
                    if ($puedeContinuar !== true) {
                        return;
                    }
                }

                $this->ejecutarHandler($definicionRuta['handler'], $parametros);
                return;
            }
        }

        Response::error("La ruta '{$rutaActual}' no existe.", 404);
    }

    private function ejecutarHandler($handler, array $parametros): void
    {
        if (is_array($handler) && count($handler) === 2) {
            [$nombreClase, $nombreMetodo] = $handler;
            $instanciaControlador = new $nombreClase();
            call_user_func_array([$instanciaControlador, $nombreMetodo], $parametros);
            return;
        }

        if (is_callable($handler)) {
            call_user_func_array($handler, $parametros);
            return;
        }

        throw new Exception('El handler de la ruta no es válido (debe ser [Clase::class, "metodo"] o una función).');
    }

    private function obtenerRutaActual(): string
    {
        $rutaCompleta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        $carpetaBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($carpetaBase !== '' && str_starts_with($rutaCompleta, $carpetaBase)) {
            $rutaCompleta = substr($rutaCompleta, strlen($carpetaBase));
        }

        $rutaCompleta = '/' . ltrim($rutaCompleta, '/');
        $rutaCompleta = rtrim($rutaCompleta, '/');

        return $rutaCompleta === '' ? '/' : $rutaCompleta;
    }
}
