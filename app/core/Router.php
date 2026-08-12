<?php
require_once __DIR__ . '/Response.php';

/**
 * ==========================================================================
 *  Router.php
 * ==========================================================================
 *  El Enrutador es el "recepcionista" de todo el backend. Como este
 *  proyecto usa PHP nativo (sin frameworks como Laravel o Symfony),
 *  necesitamos construir a mano el mecanismo que decide: "esta URL con
 *  este verbo HTTP, ¿a qué controlador y método debe ir?".
 *
 *  ¿CÓMO SE USA? (ver routes.php para los casos reales)
 *
 *      $router = new Router();
 *
 *      // Ruta simple, sin parámetros:
 *      $router->post('/api/auth/login', [AuthController::class, 'login']);
 *
 *      // Ruta con parámetro dinámico (ej. /api/productos/7):
 *      $router->get('/api/productos/{id}', [ProductoController::class, 'ver']);
 *
 *      // Ruta protegida con middleware (ver Middleware.php):
 *      $router->get('/api/admin/x', [X::class,'y'], [Middleware::rol(['Administrador'])]);
 *
 *      // Al final de index.php:
 *      $router->despachar();
 *
 *  ¿QUÉ ES UN "FRONT CONTROLLER"?
 *  Todo este sistema usa el patrón "Front Controller": TODAS las
 *  peticiones HTTP (sin importar la URL) pasan primero por un único
 *  archivo (index.php), que arma este Router y le pide que decida
 *  qué hacer. Esto centraliza el manejo de errores, CORS, sesiones,
 *  etc. en un solo lugar.
 * ==========================================================================
 */
class Router
{
    /**
     * Aquí se guardan todas las rutas registradas, organizadas por
     * verbo HTTP. Ejemplo de cómo se ve internamente:
     *
     *  $rutas = [
     *      'GET'  => [ [...definición de ruta...], [...otra...] ],
     *      'POST' => [ [...definición de ruta...] ],
     *  ]
     *
     * @var array<string, array<int, array>>
     */
    private array $rutas = [
        'GET'    => [],
        'POST'   => [],
        'PUT'    => [],
        'DELETE' => [],
    ];

    // ------------------------------------------------------------
    //  MÉTODOS PÚBLICOS PARA REGISTRAR RUTAS (uno por verbo HTTP)
    // ------------------------------------------------------------

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

    /**
     * registrar()
     * ------------------------------------------------------------
     * Método interno que usan get()/post()/put()/delete(). Convierte
     * la ruta "amigable" (ej: '/api/productos/{id}') en una expresión
     * regular que se pueda comparar contra la URL real de la petición,
     * y guarda toda la definición de la ruta en el arreglo $rutas.
     */
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

    /**
     * compilarRuta()
     * ------------------------------------------------------------
     * Transforma algo como:      '/api/productos/{id}'
     * en una expresión regular:  '#^/api/productos/(?P<id>[^/]+)$#'
     *
     * Esto permite que, más adelante (Fase 3), rutas dinámicas como
     * '/api/pedidos/{id}/estado' funcionen automáticamente, capturando
     * el valor real (ej. "7") y entregándoselo al controlador como
     * parámetro con nombre.
     */
    private function compilarRuta(string $ruta): array
    {
        $nombresParametros = [];

        // Busca cada trozo "{algo}" y lo reemplaza por un grupo de
        // captura con nombre: (?P<algo>[^/]+) -> "cualquier cosa que
        // no sea una barra /".
        $patron = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function (array $coincidencia) use (&$nombresParametros): string {
                $nombresParametros[] = $coincidencia[1];
                return '(?P<' . $coincidencia[1] . '>[^/]+)';
            },
            $ruta
        );

        // Se escapan las barras "/" (ya vienen escapadas por el patrón
        // de arriba al usar preg_replace_callback sobre texto plano,
        // pero se agrega el delimitador y los anclajes ^...$ para que
        // la ruta deba coincidir COMPLETA, no solo una parte de ella).
        $patronFinal = '#^' . $patron . '$#';

        return [$patronFinal, $nombresParametros];
    }

    // ------------------------------------------------------------
    //  DESPACHO: decide qué ruta ejecutar para la petición ACTUAL
    // ------------------------------------------------------------

    /**
     * despachar()
     * ------------------------------------------------------------
     * Se llama UNA sola vez, al final de index.php. Es el método que:
     *   1. Averigua qué URL y qué verbo HTTP se están pidiendo.
     *   2. Busca, entre las rutas registradas, cuál coincide.
     *   3. Ejecuta los middlewares de esa ruta (si los tiene).
     *   4. Si todos los middlewares dan luz verde, ejecuta el
     *      controlador (o la función) asociado a la ruta.
     *   5. Si no encuentra ninguna coincidencia, responde 404.
     */
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

                // preg_match con grupos con nombre devuelve un arreglo mixto
                // (índices numéricos Y nombres). Aquí nos quedamos solo con
                // los parámetros nombrados, en el mismo orden en que
                // aparecen en la ruta, para pasárselos al controlador.
                $parametros = [];
                foreach ($definicionRuta['nombres_parametros'] as $nombre) {
                    $parametros[] = $coincidencias[$nombre];
                }

                // Se ejecutan los middlewares EN ORDEN. Si alguno devuelve
                // false, significa que YA envió su propia respuesta de
                // error (401/403) y debemos detenernos aquí mismo.
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

    /**
     * ejecutarHandler()
     * ------------------------------------------------------------
     * El "handler" de una ruta puede ser de dos formas:
     *   a) Un arreglo [NombreDeClase::class, 'nombreDelMetodo']
     *      -> se crea el controlador y se llama a ese método.
     *   b) Una función anónima (closure), útil para rutas de prueba
     *      rápidas sin necesidad de crear un controlador completo.
     */
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

    /**
     * obtenerRutaActual()
     * ------------------------------------------------------------
     * Calcula la "ruta lógica" de la petición (ej: '/api/auth/login'),
     * sin importar en qué subcarpeta del servidor esté instalado el
     * proyecto (ej: http://localhost/bellatrix/api/auth/login también
     * funciona), y sin importar los parámetros de query string (?x=1).
     */
    private function obtenerRutaActual(): string
    {
        // parse_url + PHP_URL_PATH quita cualquier "?query=string" del final.
        $rutaCompleta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        // Si el proyecto vive en una subcarpeta (ej: /bellatrix/index.php),
        // se resta esa subcarpeta para quedarnos solo con la ruta "lógica".
        $carpetaBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($carpetaBase !== '' && str_starts_with($rutaCompleta, $carpetaBase)) {
            $rutaCompleta = substr($rutaCompleta, strlen($carpetaBase));
        }

        $rutaCompleta = '/' . ltrim($rutaCompleta, '/');
        $rutaCompleta = rtrim($rutaCompleta, '/');

        return $rutaCompleta === '' ? '/' : $rutaCompleta;
    }
}
