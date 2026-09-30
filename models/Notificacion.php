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
     * enviarCorreo($destinatario, $cuerpo, $asunto = null, $html = null)
     * ------------------------------------------------------------
     * Envía el correo REAL vía SMTP usando PHPMailer, con la
     * configuración de config/Mail.php.
     *
     *  - $cuerpo : texto plano (AltBody). Si no se pasa $html, también
     *              se usa como cuerpo HTML.
     *  - $asunto : opcional; por defecto se usa el tipo de notificación.
     *  - $html   : opcional; cuerpo HTML ya armado (ej. plantilla del pedido).
     *
     * Si el envío falla (SMTP mal configurado, sin internet, etc.), NO
     * lanza excepción hacia arriba: registra el error en el log y devuelve
     * false, para que ningún flujo (recuperación de contraseña, cambio de
     * estado de un pedido) se rompa por un problema de correo.
     */
    public function enviarCorreo(string $destinatario, string $cuerpo, ?string $asunto = null, ?string $html = null): bool
    {
        $archivoConfig = __DIR__ . '/../config/Mail.php';
        if (!is_file($archivoConfig)) {
            error_log("[ERROR DE CORREO] Falta config/Mail.php (copia config/Mail.example.php y completa las credenciales).");
            return false;
        }
        $config = require $archivoConfig;

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
            $mail->Timeout    = 15; // no dejar la petición colgada si el SMTP no responde
            $mail->SMTPDebug  = $config['MAIL_DEBUG'] ? 2 : 0;

            // --- Remitente y destinatario ---
            $mail->setFrom($config['MAIL_DESDE'], $config['MAIL_DESDE_NOMBRE']);
            $mail->addAddress($destinatario);

            // --- Contenido del mensaje ---
            $mail->Subject = $asunto ?? ($this->tipo !== '' ? $this->tipo : 'Notificación de Ambrosía');
            $mail->Body    = $html ?? nl2br(htmlspecialchars($cuerpo));   // versión HTML
            $mail->AltBody = $cuerpo;                                     // versión texto plano
            $mail->isHTML(true);

            $mail->send();
            return $this->registrarEnvio();
        } catch (PHPMailerException $e) {
            error_log("[ERROR DE CORREO] No se pudo enviar a {$destinatario}: {$mail->ErrorInfo}");
            return false;
        } catch (\Throwable $e) {
            error_log("[ERROR DE CORREO] Fallo inesperado enviando a {$destinatario}: " . $e->getMessage());
            return false;
        }
    }

    // =================================================================
    //  COLA DE ENVÍOS (correos que esperan a que se haga commit)
    // =================================================================

    /** @var array<int, array{n: Notificacion, correo: string, asunto: string, html: string, texto: string}> */
    private static array $pendientes = [];

    /**
     * encolarCorreo()
     * Cuando el cambio de estado ocurre DENTRO de una transacción mayor
     * (ej. Pago::confirmarTransaccion), el correo no debe salir hasta que
     * esa transacción haga commit: si luego hace rollback (p. ej. stock
     * insuficiente) el cliente habría recibido un aviso falso.
     */
    public static function encolarCorreo(Notificacion $n, string $correo, string $asunto, string $html, string $texto): void
    {
        self::$pendientes[] = ['n' => $n, 'correo' => $correo, 'asunto' => $asunto, 'html' => $html, 'texto' => $texto];
    }

    /** Envía los correos en cola. Llamar DESPUÉS del commit. */
    public static function despacharPendientes(): void
    {
        $cola = self::$pendientes;
        self::$pendientes = [];
        foreach ($cola as $p) {
            $p['n']->enviarCorreo($p['correo'], $p['texto'], $p['asunto'], $p['html']);
        }
    }

    /** Descarta los correos en cola. Llamar si la transacción hizo rollback. */
    public static function descartarPendientes(): void
    {
        self::$pendientes = [];
    }

    // =================================================================
    //  PLANTILLA COMÚN DE CORREOS (misma imagen para TODOS los correos)
    // =================================================================

    /** Píldora de color con el estado/título (mismo estilo que los avisos de pedido). */
    private static function etiquetaHtml(string $texto, string $color): string
    {
        return '<p style="margin:0 0 16px;"><span style="display:inline-block;background:' . $color
            . ';color:#fff;padding:6px 14px;border-radius:20px;font-size:14px;font-weight:bold;">'
            . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</span></p>';
    }

    /**
     * plantillaBase($nombreCliente, $contenido)
     * Marco visual compartido: encabezado de marca, saludo, contenido y pie.
     * $contenido ya debe venir como HTML seguro (escapado por quien lo arma).
     */
    private static function plantillaBase(string $nombreCliente, string $contenido): string
    {
        $nombre = htmlspecialchars($nombreCliente, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:0;background:#f6f1ee;font-family:Arial,Helvetica,sans-serif;color:#333;">'
            . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">'
            . '<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">'
            . '<tr><td style="background:#8b4a5c;color:#ffffff;padding:18px 24px;font-size:20px;font-weight:bold;">Ambrosía · Pastelería y Heladería</td></tr>'
            . '<tr><td style="padding:24px;">'
            . '<p style="margin:0 0 12px;font-size:16px;">Hola, <strong>' . $nombre . '</strong>:</p>'
            . $contenido
            . '<p style="margin:16px 0 0;font-size:12px;color:#888;">Este es un mensaje automático, por favor no respondas a este correo.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /**
     * armarCorreoMarca(...)
     * ------------------------------------------------------------
     * Correo genérico con la misma imagen que los avisos de pedido.
     * Sirve para registro de cuenta, recuperación de contraseña, etc.
     *
     * @param string      $etiqueta Texto de la píldora (ej. "¡Bienvenido(a)!").
     * @param string      $color    Color hex de la píldora.
     * @param string      $mensaje  Texto principal (texto plano; aquí se escapa).
     * @param string|null $codigo   Código destacado en una caja (ej. token de recuperación).
     * @param string|null $nota     Aviso secundario en letra pequeña.
     * @return array{0: string, 1: string} [html, texto]
     */
    public function armarCorreoMarca(
        string $nombreCliente,
        string $etiqueta,
        string $color,
        string $mensaje,
        ?string $codigo = null,
        ?string $nota = null
    ): array {
        $e = fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

        $contenido = self::etiquetaHtml($etiqueta, $color)
            . '<p style="margin:0 0 16px;font-size:14px;line-height:1.5;">' . nl2br($e($mensaje)) . '</p>';

        if ($codigo !== null && $codigo !== '') {
            $contenido .= '<p style="margin:0 0 16px;text-align:center;">'
                . '<span style="display:inline-block;background:#f6f1ee;border:1px dashed #8b4a5c;border-radius:8px;'
                . 'padding:12px 20px;font-size:22px;letter-spacing:2px;font-weight:bold;color:#8b4a5c;">'
                . $e($codigo) . '</span></p>';
        }
        if ($nota !== null && $nota !== '') {
            $contenido .= '<p style="margin:0;font-size:13px;line-height:1.5;color:#666;">' . nl2br($e($nota)) . '</p>';
        }

        $html  = self::plantillaBase($nombreCliente, $contenido);
        $texto = "Hola, {$nombreCliente}:\n\n{$mensaje}\n\n"
               . ($codigo !== null && $codigo !== '' ? "{$codigo}\n\n" : '')
               . ($nota !== null && $nota !== '' ? "{$nota}\n\n" : '')
               . "Ambrosía - Pastelería y Heladería";

        return [$html, $texto];
    }

    /**
     * enviarCorreoMarca(...)
     * Atajo: arma el correo con la plantilla común y lo envía.
     */
    public function enviarCorreoMarca(
        string $destinatario,
        string $nombreCliente,
        string $asunto,
        string $etiqueta,
        string $color,
        string $mensaje,
        ?string $codigo = null,
        ?string $nota = null
    ): bool {
        [$html, $texto] = $this->armarCorreoMarca($nombreCliente, $etiqueta, $color, $mensaje, $codigo, $nota);
        return $this->enviarCorreo($destinatario, $texto, $asunto, $html);
    }

    /**
     * armarCorreoPedido($pedido, $nombreCliente)
     * ------------------------------------------------------------
     * Arma asunto, HTML y texto plano del correo que recibe el cliente
     * cuando su pedido se crea o cambia de estado.
     *
     * @return array{0: string, 1: string, 2: string} [asunto, html, texto]
     */
    public function armarCorreoPedido(object $pedido, string $nombreCliente): array
    {
        $e      = fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $moneda = fn(float $v): string => '$' . number_format($v, 0, ',', '.');

        $num    = (int)$pedido->idPedido;
        $estado = (string)$pedido->estado;

        $asunto = ($this->tipo === 'Confirmación de pedido')
            ? "Recibimos tu pedido #{$num} - Ambrosía"
            : "Tu pedido #{$num} ahora está: {$estado} - Ambrosía";

        $colores = [
            'Pendiente de pago'  => '#b7791f',
            'Confirmado'         => '#2b6cb0',
            'En preparación'     => '#6b46c1',
            'Listo para recoger' => '#2f855a',
            'Entregado'          => '#2f855a',
            'Cancelado'          => '#c53030',
        ];
        $color = $colores[$estado] ?? '#555555';

        $filas = '';
        $lineasTexto = '';
        foreach ($pedido->productos as $d) {
            $nombre = $d->nombreProducto ?? 'Producto';
            $filas .= '<tr>'
                . '<td style="padding:6px 0;border-bottom:1px solid #eee;">' . $e($nombre) . '</td>'
                . '<td style="padding:6px 0;border-bottom:1px solid #eee;text-align:center;">' . (int)$d->cantidad . '</td>'
                . '<td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right;">' . $moneda($d->subtotal()) . '</td>'
                . '</tr>';
            $lineasTexto .= "  - {$nombre} x{$d->cantidad}: " . $moneda($d->subtotal()) . "\n";
        }

        $tabla = $filas === '' ? '' :
            '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;margin:16px 0;">'
            . '<tr style="color:#888;font-size:12px;text-transform:uppercase;">'
            . '<th align="left" style="padding-bottom:6px;">Producto</th>'
            . '<th style="padding-bottom:6px;">Cant.</th>'
            . '<th align="right" style="padding-bottom:6px;">Subtotal</th></tr>'
            . $filas
            . '<tr><td colspan="2" style="padding-top:10px;font-weight:bold;">Total</td>'
            . '<td align="right" style="padding-top:10px;font-weight:bold;">' . $moneda((float)$pedido->total) . '</td></tr>'
            . '</table>';

        $contenido =
              '<p style="margin:0 0 16px;font-size:14px;">Novedades de tu pedido <strong>#' . $num . '</strong></p>'
            . self::etiquetaHtml($estado, $color)
            . '<p style="margin:0;font-size:14px;line-height:1.5;">' . $e($this->mensaje) . '</p>'
            . $tabla;

        $html = self::plantillaBase($nombreCliente, $contenido);

        $texto = "Hola, {$nombreCliente}:\n\n"
            . "Novedades de tu pedido #{$num}\nEstado: {$estado}\n\n"
            . $this->mensaje . "\n\n"
            . ($lineasTexto !== '' ? "Detalle:\n{$lineasTexto}Total: " . $moneda((float)$pedido->total) . "\n\n" : '')
            . "Ambrosía - Pastelería y Heladería";

        return [$asunto, $html, $texto];
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
