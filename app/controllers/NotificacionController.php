<?php
require_once __DIR__ . '/../../models/Notificacion.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

/**
 * ==========================================================================
 *  NotificacionController.php
 * ==========================================================================
 *  Implementa el lado "de consulta" del CU016: "Recibir Notificación".
 *
 *  El ENVÍO de notificaciones NO ocurre aquí — ya sucede de forma
 *  automática dentro de los modelos de la Fase 1 (Pedido::
 *  cambiarEstado() -> notificarCliente(), y AuthController en la
 *  Fase 2 para el registro/recuperación). Este controlador solo le
 *  permite al Cliente CONSULTAR su propio historial de notificaciones
 *  recibidas (por ejemplo, para una campanita de notificaciones en
 *  el frontend de la Fase 5/6).
 * ==========================================================================
 */
class NotificacionController
{
    /**
     * misNotificaciones()
     * GET /api/notificaciones  (rol: Cliente)
     */
    public function misNotificaciones(): void
    {
        $notificaciones = Notificacion::listarPorCliente(Sesion::obtenerId());

        $datos = array_map(fn(Notificacion $n) => [
            'id_notificacion' => $n->idNotificacion,
            'id_pedido'       => $n->idPedido,
            'tipo'            => $n->tipo,
            'canal_envio'     => $n->canalEnvio,
            'mensaje'         => $n->mensaje,
            'enviado'         => $n->enviado,
            'fecha_envio'     => $n->fechaEnvio,
            'creado_en'       => $n->creadoEn,
        ], $notificaciones);

        Response::exito($datos, 'Notificaciones obtenidas correctamente.');
    }
}
