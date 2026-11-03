<?php

class Request
{
  
    public static function jsonBody(): array
    {
        $contenidoCrudo = file_get_contents('php://input');

        if ($contenidoCrudo === false || trim($contenidoCrudo) === '') {
            return [];
        }

        $datos = json_decode($contenidoCrudo, true);
        return is_array($datos) ? $datos : [];
    }
    public static function metodo(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function query(string $clave, $porDefecto = null)
    {
        return $_GET[$clave] ?? $porDefecto;
    }
}
