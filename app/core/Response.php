<?php

class Response
{
    public static function json($datos, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function exito($datos = [], string $mensaje = 'Operación exitosa.', int $codigo = 200): void
    {
        self::json([
            'exito'   => true,
            'mensaje' => $mensaje,
            'datos'   => $datos,
        ], $codigo);
    }

    public static function error(string $mensaje, int $codigo = 400): void
    {
        self::json([
            'exito'   => false,
            'mensaje' => $mensaje,
        ], $codigo);
    }
}
