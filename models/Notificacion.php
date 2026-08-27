<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../libs/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * ==========================================================================
 *  Notificacion.php
 * ==========================================================================
 *  Mapea la tabla `notificacion`. Corresponde a la clase "Notificacion"
 *  del Diagrama de Clases. Implementa el CU016 (Recibir Notificación).
 *
 *  IMPORTANTE: este modelo se encarga de GENERAR el texto del mensaje
 *  y de DEJAR REGISTRO en la base de datos de que la notificación
 *  existe. El envío real (conectarse a un servidor SMTP y mandar el
 *  correo, por ejemplo con PHPMailer) es responsabilidad de la Fase 4
 *  (Módulo E-Commerce y Notificaciones), para mantener este modelo
 *  enfocado solo en datos, tal como pide el patrón MVC.
 * ==========================================================================
 */
class Notificacion
{
    public ?int $idNotificacion;
    public int $idCliente;
    public ?int $idPedido;
    public string $tipo; // ENUM: 'Confirmación de registro'|'Confirmación de pedido'|'Cambio de estado'|'Recuperación de contraseña'
    public string $canalEnvio; // ENUM: 'Correo'|'Mensaje'
    public string $mensaje;
    public bool $enviado;
    public ?string $fechaEnvio;
    public ?string $creadoEn;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idNotificacion = $datos['id_notificacion'] ?? null;
        $this->idCliente      = $datos['id_cliente']      ?? 0;
        $this->idPedido       = $datos['id_pedido']       ?? null;
        $this->tipo           = $datos['tipo']            ?? 'Confirmación de pedido';
        $this->canalEnvio     = $datos['canal_envio']     ?? 'Correo';
        $this->mensaje        = $datos['mensaje']         ?? '';
        $this->enviado        = isset($datos['enviado']) ? (bool)$datos['enviado'] : false;
        $this->fechaEnvio     = $datos['fecha_envio']     ?? null;
        $this->creadoEn       = $datos['creado_en']       ?? null;
    }

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * generarMensajeConfirmacion($pedido)
     * Arma el texto del correo/mensaje de confirmación de un pedido
     * recién creado, incluyendo su total.
     */
    public function generarMensajeConfirmacion(object $pedido): string
    {
        $total = number_format($pedido->total, 2);
        return "¡Hola! Hemos recibido tu pedido #{$pedido->idPedido} en Ambrosía por un total de \${$total}. "
             . "Te avisaremos apenas cambie de estado. ¡Gracias por tu compra!";
    }

    /**
     * generarMensajeCambioEstado($estado)
     * Arma el texto según el nuevo estado del pedido.
     */
    public function generarMensajeCambioEstado(string $estado): string
    {
        $mensajes = [
            'Confirmado'          => 'Tu pago fue confirmado y tu pedido ya está en la fila de preparación.',
            'En preparación'      => 'Nuestro equipo ya está preparando tu pedido con mucho cariño.',
            'Listo para recoger'  => 'Tu pedido está listo. ¡Puedes pasar a recogerlo cuando quieras!',
            'Entregado'           => 'Tu pedido fue entregado. ¡Esperamos que lo disfrutes!',
            'Cancelado'           => 'Tu pedido ha sido cancelado. Si tienes dudas, contáctanos.',
        ];

        return $mensajes[$estado] ?? "El estado de tu pedido cambió a: {$estado}.";
    }

    /**
     * enviarCorreo($destinatario, $cuerpo)
     * ------------------------------------------------------------
     * Envía el correo REAL vía SMTP usando PHPMailer, con la
     * configuración de config/Mail.php. Si el envío falla (SMTP mal
     * configurado, sin internet, etc.), NO lanza excepción hacia
     * arriba: registra el error en el log y devuelve false, para que
     * el flujo de recuperación de contraseña nunca se rompa por un
     * problema de correo (el token ya quedó guardado en la BD de
     * todas formas).
     */
    public function enviarCorreo(string $destinatario, string $cuerpo): bool
    {
        $config = require __DIR__ . '/../config/Mail.php';

        $mail = new PHPMailer(true);
        try {
            // --- Configuración del servidor SMTP ---
            $mail->isSMTP();
            $mail->Host       = $config['MAIL_HOST'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['MAIL_USUARIO'];
            $mail->Password   = $config['MAIL_CLAVE'];
            $mail->SMTPSecure = $config['MAIL_CIFRADO']; // 'tls' o 'ssl'
            $mail->Port       = $config['MAIL_PUERTO'];
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPDebug  = $config['MAIL_DEBUG'] ? 2 : 0;

            // --- Remitente y destinatario ---
            $mail->setFrom($config['MAIL_DESDE'], $config['MAIL_DESDE_NOMBRE']);
            $mail->addAddress($destinatario);

            // --- Contenido del mensaje ---
            $mail->Subject = $this->tipo !== '' ? $this->tipo : 'Notificación de Ambrosía';
            $mail->Body    = nl2br(htmlspecialchars($cuerpo));   // versión HTML
            $mail->AltBody = $cuerpo;                            // versión texto plano
            $mail->isHTML(true);

            $mail->send();
            return $this->registrarEnvio();
        } catch (PHPMailerException $e) {
            error_log("[ERROR DE CORREO] No se pudo enviar a {$destinatario}: {$mail->ErrorInfo}");
            return false;
        }
    }

    /**
     * enviarMensaje($destinatario)
     * Igual que enviarCorreo(), pero para el canal 'Mensaje' (ej. SMS
     * o WhatsApp). También queda como stub hasta la Fase 4.
     */
    public function enviarMensaje(string $destinatario): bool
    {
        // TODO (Fase 4): reemplazar por integración real (ej. API de WhatsApp).
        error_log("[SIMULACIÓN DE MENSAJE] Para: {$destinatario} | Mensaje: {$this->mensaje}");
        return $this->registrarEnvio();
    }

    /**
     * registrarEnvio()
     * Marca la notificación como enviada y guarda la fecha/hora exacta.
     */
    public function registrarEnvio(): bool
    {
        $sql = "UPDATE notificacion SET enviado = 1, fecha_envio = NOW() WHERE id_notificacion = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $this->idNotificacion, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->enviado = true;
            $this->fechaEnvio = date('Y-m-d H:i:s');
        }

        return $ok;
    }

    // =================================================================
    //  CRUD
    // =================================================================

    /**
     * crear()
     * Guarda el registro de la notificación en la BD (todavía sin
     * enviar; enviado = 0 por defecto).
     */
    public function crear(): int
    {
        $sql = "INSERT INTO notificacion (id_cliente, id_pedido, tipo, canal_envio, mensaje, enviado)
                VALUES (:cliente, :pedido, :tipo, :canal, :mensaje, 0)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cliente', $this->idCliente, PDO::PARAM_INT);
        $stmt->bindValue(':pedido', $this->idPedido, PDO::PARAM_INT);
        $stmt->bindValue(':tipo', $this->tipo);
        $stmt->bindValue(':canal', $this->canalEnvio);
        $stmt->bindValue(':mensaje', $this->mensaje);
        $stmt->execute();

        $this->idNotificacion = (int)$this->pdo->lastInsertId();
        return $this->idNotificacion;
    }

    public static function listarPorCliente(int $idCliente): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM notificacion WHERE id_cliente = :cliente ORDER BY creado_en DESC");
        $stmt->bindValue(':cliente', $idCliente, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new Notificacion($fila), $stmt->fetchAll());
    }
}
