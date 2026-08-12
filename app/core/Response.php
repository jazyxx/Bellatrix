<?php

/**
 * ==========================================================================
 *  Response.php
 * ==========================================================================
 *  Estandariza CÓMO responde el backend a cada petición. Sin esta clase,
 *  cada controlador armaría el JSON de respuesta "a su manera", y el
 *  frontend tendría que adivinar en qué formato viene cada endpoint.
 *
 *  Con esta clase, TODAS las respuestas del sistema tienen la misma forma:
 *
 *      { "exito": true,  "mensaje": "...", "datos": {...} }
 *      { "exito": false, "mensaje": "..." }
 *
 *  Esto hace que el JavaScript del frontend (Fase 5/6) pueda manejar
 *  todas las respuestas de la API de forma genérica y predecible.
 * ==========================================================================
 */
class Response
{
    /**
     * json($datos, $codigo)
     * Método base: envía cualquier arreglo como JSON, con el código de
     * estado HTTP indicado (200 OK, 401 No autorizado, 404 No encontrado, etc.).
     */
    public static function json($datos, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * exito($datos, $mensaje, $codigo)
     * Se usa cuando la operación SÍ funcionó. Ejemplo de uso:
     *     Response::exito(['id_cliente' => 5], 'Cuenta creada exitosamente.', 201);
     */
    public static function exito($datos = [], string $mensaje = 'Operación exitosa.', int $codigo = 200): void
    {
        self::json([
            'exito'   => true,
            'mensaje' => $mensaje,
            'datos'   => $datos,
        ], $codigo);
    }

    /**
     * error($mensaje, $codigo)
     * Se usa cuando algo salió mal (datos inválidos, no autorizado, etc.).
     * Ejemplo de uso:
     *     Response::error('Correo o contraseña incorrectos.', 401);
     *
     * Códigos HTTP más usados en este proyecto:
     *   400 = Petición mal formada (faltan datos)
     *   401 = No autenticado (no ha iniciado sesión)
     *   403 = Autenticado pero sin permiso (rol incorrecto)
     *   404 = El recurso solicitado no existe
     *   409 = Conflicto (ej: correo ya registrado)
     *   422 = Datos no pasaron las validaciones
     *   500 = Error interno inesperado del servidor
     */
    public static function error(string $mensaje, int $codigo = 400): void
    {
        self::json([
            'exito'   => false,
            'mensaje' => $mensaje,
        ], $codigo);
    }
}
