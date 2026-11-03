<?php
require_once __DIR__ . '/../../models/InteraccionIA.php';
require_once __DIR__ . '/../../models/GestorVentas.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

























class AsistenteIAController
{
    






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

    













    public function mensaje(): void
    {
        $idEmpleado = Sesion::obtenerId();
        if ($idEmpleado === null) {
            Response::error('Debes iniciar sesión para acceder a este recurso.', 401);
            return;
        }

        $datos = Request::jsonBody();

        
        
        
        
        $mensajeUsuario = trim(strip_tags((string) ($datos['mensaje'] ?? '')));
        $mensajeUsuario = mb_substr($mensajeUsuario, 0, 1000);

        if ($mensajeUsuario === '') {
            Response::error('Escribe una pregunta antes de enviar.', 400);
            return;
        }

        
        $interaccionUsuario = new InteraccionIA([
            'id_empleado' => $idEmpleado,
            'rol_mensaje' => 'usuario',
            'mensaje'     => $mensajeUsuario,
        ]);
        $interaccionUsuario->crear();

        
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

        
        [$respuestaIA, $errorIA] = $this->consultarProveedorIA($mensajesParaIA);

        if ($respuestaIA === null) {
            Response::error("No se pudo obtener respuesta del asistente: {$errorIA}", 502);
            return;
        }

        
        $interaccionAsistente = new InteraccionIA([
            'id_empleado' => $idEmpleado,
            'rol_mensaje' => 'asistente',
            'mensaje'     => $respuestaIA,
        ]);
        $interaccionAsistente->crear();

        Response::exito(['mensaje' => $respuestaIA], 'Respuesta generada correctamente.');
    }

    






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
