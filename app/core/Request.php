<?php

/**
 * ==========================================================================
 *  Request.php
 * ==========================================================================
 *  Como el Frontend (Fase 5/6) hablará con este backend usando la
 *  Fetch API de JavaScript enviando JSON (no formularios HTML clásicos),
 *  esta clase centraliza la forma de LEER esos datos entrantes.
 *
 *  Sin esta clase, cada controlador tendría que repetir el mismo código
 *  de "leer php://input y decodificar JSON". Con ella, basta con llamar
 *  a Request::jsonBody() desde cualquier controlador.
 * ==========================================================================
 */
class Request
{
    /**
     * jsonBody()
     * ------------------------------------------------------------
     * Lee el cuerpo crudo de la petición HTTP (donde el JavaScript del
     * frontend pone el JSON, ej: {"correo":"...", "contrasena":"..."})
     * y lo convierte en un arreglo asociativo de PHP.
     *
     * Si el cuerpo está vacío o no es JSON válido, devuelve un arreglo
     * vacío en lugar de fallar, para que el controlador pueda validar
     * los campos faltantes de forma controlada (en vez de que PHP
     * lance un error fatal).
     */
    public static function jsonBody(): array
    {
        $contenidoCrudo = file_get_contents('php://input');

        if ($contenidoCrudo === false || trim($contenidoCrudo) === '') {
            return [];
        }

        $datos = json_decode($contenidoCrudo, true);

        // Si json_decode falló (JSON mal formado), json_decode devuelve null.
        return is_array($datos) ? $datos : [];
    }

    /**
     * metodo()
     * Devuelve el verbo HTTP de la petición actual (GET, POST, PUT, DELETE...).
     */
    public static function metodo(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * query($clave, $porDefecto)
     * Lee un parámetro de la URL después del "?", ej: /api/productos?buscar=torta
     */
    public static function query(string $clave, $porDefecto = null)
    {
        return $_GET[$clave] ?? $porDefecto;
    }
}
