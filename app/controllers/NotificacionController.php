<?php
require_once __DIR__ . '/../../models/Notificacion.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

class NotificacionController
{
 
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
