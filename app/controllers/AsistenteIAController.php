<?php
require_once __DIR__ . '/../../models/InteraccionIA.php';
require_once __DIR__ . '/../../models/GestorVentas.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

/**
 * ==========================================================================
 *  AsistenteIAController.php
 * ==========================================================================
 *  Asistente Financiero con IA para el módulo de Caja (admin_caja.html).
 *  Ayuda al Administrador a interpretar los reportes de `gestor_ventas`
 *  y a tomar mejores decisiones financieras (qué unidad de negocio o
 *  canal rinde mejor, alertas de egresos altos, tendencias) conversando
 *  en lenguaje natural.
 *
 *  SEGURIDAD:
 *  - Estas 3 rutas SIEMPRE pasan por Middleware::rol(['Administrador'])
 *    (ver routes.php) antes de llegar aquí, así que ya sabemos que hay
 *    una sesión activa de un Administrador — igual se vuelve a validar
 *    aquí dentro por robustez (defensa en profundidad).
 *  - La API key del proveedor de IA NUNCA se expone al navegador: vive
 *    solo en config/IA.php, del lado del servidor. Este controlador es
 *    el ÚNICO intermediario autorizado entre el frontend y la API
 *    externa — el JavaScript del cliente nunca habla directo con
 *    OpenAI.
 *  - Todo mensaje entrante se sanitiza (strip_tags + límite de
 *    longitud) antes de guardarse en la BD o reenviarse a la IA.
 * ==========================================================================
 */
class AsistenteIAController
{
    /**
     * historial()
     * ------------------------------------------------------------
     * GET /api/asistente-ia/historial
     * Devuelve la conversación previa del Administrador en sesión,
     * para repintar el widget si recarga la página o la vuelve a abrir.
     */
    public function historial(): void
    {
        $idEmpleado = Sesion::obtenerId();
        if ($idEmpleado === null) {
            Response::error('Debes iniciar sesión para acceder a este recurso.', 401);
            return;
        }

        $interacciones = InteraccionIA::listarPorEmpleado($idEmpleado, 30);

        $datos = array_map(fn(InteraccionIA $i) => [
            'rol'       => $i->rolMensaje,
            'mensaje'   => $i->mensaje,
            'creado_en' => $i->creadoEn,
        ], $interacciones);

        Response::exito($datos);
    }

    /**
     * mensaje()
     * ------------------------------------------------------------
     * POST /api/asistente-ia/mensaje
     * Body: { "mensaje": "¿Qué unidad de negocio vendió más este mes?" }
     *
     * 1) Sanitiza y guarda la pregunta del Administrador.
     * 2) Arma un resumen REAL de los últimos 30 días de gestor_ventas
     *    como contexto (para que la IA responda con datos reales del
     *    negocio, no cifras inventadas).
     * 3) Llama al proveedor de IA vía cURL, incluyendo el historial
     *    reciente de la conversación.
     * 4) Guarda la respuesta del asistente y la devuelve al frontend.
     */
    public function mensaje(): void
    {
        $idEmpleado = Sesion::obtenerId();
        if ($idEmpleado === null) {
            Response::error('Debes iniciar sesión para acceder a este recurso.', 401);
            return;
        }

        $datos = Request::jsonBody();

        // --- Sanitización de la entrada ---
        // strip_tags() evita que se guarde o reenvíe HTML/scripts, y
        // mb_substr() limita el tamaño para no gastar de más en la API
        // de IA ni permitir mensajes desproporcionados.
        $mensajeUsuario = trim(strip_tags((string) ($datos['mensaje'] ?? '')));
        $mensajeUsuario = mb_substr($mensajeUsuario, 0, 1000);

        if ($mensajeUsuario === '') {
            Response::error('Escribe una pregunta antes de enviar.', 400);
            return;
        }

        // 1) Guardar el mensaje del usuario.
        $interaccionUsuario = new InteraccionIA([
            'id_empleado' => $idEmpleado,
            'rol_mensaje' => 'usuario',
            'mensaje'     => $mensajeUsuario,
        ]);
        $interaccionUsuario->crear();

        // 2) Contexto financiero real + historial reciente de la conversación.
        $contexto  = $this->construirContextoFinanciero();
        $historial = InteraccionIA::listarPorEmpleado($idEmpleado, 10);

        $mensajesParaIA = [[
            'role'    => 'system',
            'content' => "Eres el Asistente Financiero de Ambrosía (pastelería y heladería). "
                       . "Ayudas al Administrador a interpretar los reportes de caja y a tomar "
                       . "mejores decisiones financieras (qué unidad de negocio o canal rinde "
                       . "mejor, alertas de egresos altos, tendencias). Responde en español, de "
                       . "forma breve y clara, basándote ÚNICAMENTE en los datos reales que se "
                       . "te dan a continuación. Si no tienes datos suficientes para responder "
                       . "algo con certeza, dilo explícitamente en vez de inventar cifras.\n\n"
                       . $contexto,
        ]];

        foreach ($historial as $item) {
            $mensajesParaIA[] = [
                'role'    => $item->rolMensaje === 'usuario' ? 'user' : 'assistant',
                'content' => $item->mensaje,
            ];
        }

        // 3) Consultar al proveedor de IA.
        [$respuestaIA, $errorIA] = $this->consultarProveedorIA($mensajesParaIA);

        if ($respuestaIA === null) {
            Response::error("No se pudo obtener respuesta del asistente: {$errorIA}", 502);
            return;
        }

        // 4) Guardar la respuesta del asistente y responder al frontend.
        $interaccionAsistente = new InteraccionIA([
            'id_empleado' => $idEmpleado,
            'rol_mensaje' => 'asistente',
            'mensaje'     => $respuestaIA,
        ]);
        $interaccionAsistente->crear();

        Response::exito(['mensaje' => $respuestaIA], 'Respuesta generada correctamente.');
    }

    /**
     * limpiarHistorial()
     * ------------------------------------------------------------
     * DELETE /api/asistente-ia/historial
     * Borra la conversación guardada de este Administrador (botón
     * "Nueva conversación" del widget) y reinicia el contexto.
     */
    public function limpiarHistorial(): void
    {
        $idEmpleado = Sesion::obtenerId();
        if ($idEmpleado === null) {
            Response::error('Debes iniciar sesión para acceder a este recurso.', 401);
            return;
        }

        InteraccionIA::borrarHistorial($idEmpleado);
        Response::exito([], 'Conversación reiniciada.');
    }

    /**
     * construirContextoFinanciero()
     * ------------------------------------------------------------
     * Arma un resumen en texto plano de los últimos 30 días de
     * `gestor_ventas`, reutilizando GestorVentas::generarReportePorRango()
     * (ya existente desde el módulo de Caja) — así el contexto que
     * recibe la IA son datos reales del negocio, no una simulación.
     */
    private function construirContextoFinanciero(): string
    {
        $hoy = date('Y-m-d');
        $haceUnMes = date('Y-m-d', strtotime('-30 days'));
        $cajas = GestorVentas::generarReportePorRango($haceUnMes, $hoy);

        if (empty($cajas)) {
            return "No hay movimientos de caja registrados en los últimos 30 días.";
        }

        $totalVentas = 0.0;
        $totalEgresos = 0.0;
        $porUnidadCanal = [];

        foreach ($cajas as $caja) {
            $totalVentas  += (float) $caja->totalVentas;
            $totalEgresos += (float) $caja->totalEgresos;

            $clave = $caja->unidadNegocio . ' / ' . $caja->canal;
            $porUnidadCanal[$clave] = ($porUnidadCanal[$clave] ?? 0) + (float) $caja->totalVentas;
        }

        $saldoNeto = $totalVentas - $totalEgresos;

        $resumen  = "Resumen financiero de Ambrosía (del {$haceUnMes} al {$hoy}):\n";
        $resumen .= "- Total ventas: $" . number_format($totalVentas, 2) . "\n";
        $resumen .= "- Total egresos: $" . number_format($totalEgresos, 2) . "\n";
        $resumen .= "- Saldo neto: $" . number_format($saldoNeto, 2) . "\n";
        $resumen .= "- Ventas por unidad de negocio y canal:\n";
        foreach ($porUnidadCanal as $clave => $monto) {
            $resumen .= "  · {$clave}: $" . number_format($monto, 2) . "\n";
        }

        return $resumen;
    }

    /**
     * consultarProveedorIA($mensajes)
     * ------------------------------------------------------------
     * Único punto del sistema que habla, vía cURL, con la API externa
     * de IA (config/IA.php). Devuelve [texto_respuesta, null] si todo
     * salió bien, o [null, mensaje_de_error] si algo falló (red,
     * credenciales, respuesta inesperada) — nunca lanza una excepción
     * hacia afuera, para que mensaje() pueda responder un error
     * controlado (HTTP 502) en vez de un error fatal de PHP.
     */
    private function consultarProveedorIA(array $mensajes): array
    {
        $config = require __DIR__ . '/../../config/IA.php';

        $payload = json_encode([
            'model'       => $config['IA_MODELO'],
            'messages'    => $mensajes,
            'temperature' => 0.4,
            'max_tokens'  => 500,
        ]);

        $ch = curl_init($config['IA_ENDPOINT']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['IA_API_KEY'],
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT    => 25,
        ]);

        $respuestaCruda = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        if ($respuestaCruda === false) {
            return [null, "Error de conexión: {$errorCurl}"];
        }

        $respuestaDecodificada = json_decode($respuestaCruda, true);

        if ($codigoHttp !== 200 || !isset($respuestaDecodificada['choices'][0]['message']['content'])) {
            $mensajeError = $respuestaDecodificada['error']['message']
                ?? "El proveedor de IA respondió con un error (HTTP {$codigoHttp}).";
            return [null, $mensajeError];
        }

        return [trim($respuestaDecodificada['choices'][0]['message']['content']), null];
    }
}
