<?php
/**
 * ==========================================================================
 *  config/Mail.php
 * ==========================================================================
 *  Configuración del servidor SMTP usado para el envío REAL de correos
 *  (recuperación de contraseña, y a futuro cualquier otra notificación).
 *
 *  IMPORTANTE — completa estos 4 valores antes de probar:
 *
 *  Opción A) Gmail (recomendada para producción/demo con tu propio correo):
 *    1. Activa la verificación en 2 pasos en tu cuenta de Gmail.
 *    2. Ve a https://myaccount.google.com/apppasswords y genera una
 *       "Contraseña de aplicación" (16 caracteres, sin espacios).
 *    3. MAIL_USUARIO = tu correo completo (ej: ambrosia.pasteleria@gmail.com)
 *       MAIL_CLAVE   = esa contraseña de aplicación (NO tu contraseña normal)
 *       MAIL_HOST    = smtp.gmail.com
 *       MAIL_PUERTO  = 587
 *
 *  Opción B) Mailtrap (recomendada SOLO para pruebas/sustentación — los
 *  correos NO llegan a bandejas reales, quedan atrapados en una bandeja
 *  de pruebas online, ideal para demostrar el flujo sin arriesgarte a
 *  mandar correos reales por accidente):
 *    1. Crea una cuenta gratis en https://mailtrap.io
 *    2. Ve a tu "Inbox" de pruebas → pestaña "SMTP Settings" → "PHPMailer"
 *    3. Copia el host, usuario y clave que te muestra ahí.
 *
 *  NUNCA subas este archivo con credenciales reales a un repositorio
 *  público (GitHub). Si vas a subir el proyecto, agrega config/Mail.php
 *  a tu .gitignore y deja aquí solo valores de ejemplo.
 * ==========================================================================
 */

return [
    // Servidor SMTP saliente.
    'MAIL_HOST'     => 'smtp.gmail.com',

    // Puerto: 587 para TLS (STARTTLS, el más común), 465 para SSL directo.
    'MAIL_PUERTO'   => 587,

    // Tipo de cifrado: 'tls' (puerto 587) o 'ssl' (puerto 465).
    'MAIL_CIFRADO'  => 'tls',

    // Credenciales de la cuenta que ENVÍA los correos.
    'MAIL_USUARIO'  => 'ambrosia.pasteleriaa@gmail.com',
    'MAIL_CLAVE'    => 'gttb mtfk tkun ouid',

    // Nombre y correo que verá el cliente como remitente.
    'MAIL_DESDE'      => 'ambrosia.pasteleriaa@gmail.com',
    'MAIL_DESDE_NOMBRE' => 'Ambrosía - Pastelería y Heladería',

    // Si es true, PHPMailer escribe el detalle de la conversación SMTP
    // en el log del servidor — muy útil para depurar, pero apágalo
    // (déjalo en false) antes de la sustentación para no llenar el log.
    'MAIL_DEBUG'    => false,
];
