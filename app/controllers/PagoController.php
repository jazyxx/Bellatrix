<?php
require_once __DIR__ . '/../../models/Pago.php';
require_once __DIR__ . '/../../models/Pedido.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Sesion.php';

/**
 * ==========================================================================
 *  PagoController.php
 * ==========================================================================
 *  Implementa el CU014: "Realizar Pago en Línea". Conecta con una
 *  pasarela de pagos (PSE, tarjetas, Nequi) para procesar la
 *  transacción y confirmar el pedido.
 *
 *  ⚠️ IMPORTANTE — ALCANCE DE ESTA FASE:
 *  Tal como se dejó documentado desde la Fase 1 (en Pago.php) y lo
 *  pedía tu plan de trabajo para esta fase ("simulación/integración
 *  de Pasarela de Pagos"), aquí se construye una PASARELA SIMULADA:
 *  no hay ninguna llamada HTTP real a PSE/Nequi/etc. El endpoint
 *  confirmarPago() reemplaza, por ahora, al "webhook" que en un
 *  sistema en producción llegaría directamente desde la pasarela real.
 *
 *  Esto te permite tener el flujo de compra COMPLETO y funcional
 *  para hacer pruebas y para las Vistas (Fase 5/6), sin necesitar
 *  todavía credenciales reales de una pasarela de pagos. Cuando
 *  tengas esas credenciales, solo hay que reemplazar el CUERPO de
 *  confirmarPago() por la llamada real — la firma del método y todo
 *  lo que depende de él (Pago.php, Pedido.php) no cambia.
 * ==========================================================================
 */
class PagoController
{
    /**
     * iniciar()
     * ------------------------------------------------------------
     * POST /api/pagos  (rol: Cliente)
     * Body: { "id_pedido": 7, "medio_pago": "PSE" }
     *
     * Crea el registro de intento de pago (estado 'Pendiente') por el
     * monto exacto del pedido, usando Pago::procesarPago() (Fase 1).
     */
    // --- NUEVO: CONFIGURACIÓN NEQUI ---
    private $apiUrlNequi = "https://api.sandbox.nequi.com";
    private $apiKeyNequi = "TU_API_KEY_AQUI"; 
    private $codigoComercioNequi = "NIT_900_AMBROSIA";
    // ----------------------------------
    public function iniciar(): void
    {
        $datos = Request::jsonBody();
        $idPedido = $datos['id_pedido'] ?? null;
        $medioPago = $datos['medio_pago'] ?? '';
        $celular = $datos['celular'] ?? null; // NUEVO: Requerido para el Push de Nequi

        $mediosValidos = ['PSE', 'Tarjeta crédito', 'Tarjeta débito', 'Nequi', 'Otro'];
        if (!is_numeric($idPedido)) {
            Response::error("Debes indicar 'id_pedido'.", 400);
            return;
        }
        if (!in_array($medioPago, $mediosValidos, true)) {
            Response::error("Debes indicar un 'medio_pago' válido: " . implode(', ', $mediosValidos) . '.', 400);
            return;
        }

        $pedido = Pedido::obtenerPorId((int) $idPedido);
        if ($pedido === null) {
            Response::error('El pedido indicado no existe.', 404);
            return;
        }
        if ($pedido->idCliente !== Sesion::obtenerId()) {
            Response::error('No tienes permiso para pagar este pedido.', 403);
            return;
        }
        if ($pedido->estado !== 'Pendiente de pago') {
            Response::error("Este pedido ya no está pendiente de pago (estado actual: '{$pedido->estado}').", 409);
            return;
        }

        $pago = new Pago([
            'id_pedido'  => $pedido->idPedido,
            'monto'      => $pedido->total,
            'medio_pago' => $medioPago,
        ]);
        $pago->procesarPago();
        // --- NUEVO: INTEGRACIÓN REAL CON NEQUI ---
        if ($medioPago === 'Nequi') {
            if (empty($celular)) {
                Response::error("Para pagar con Nequi debes proporcionar el número de celular.", 400);
                return;
            }

            $tokenAcceso = $this->generarTokenNequi();
            if (!$tokenAcceso) {
                Response::error("Error de autenticación con la pasarela de Nequi.", 500);
                return;
            }

            $respuestaNequi = $this->enviarPushNequi($celular, $pedido->total, $pago->referencia, $tokenAcceso);
            if (!$respuestaNequi['exito']) {
                Response::error('Error al conectar con Nequi: ' . $respuestaNequi['mensaje'], 500);
                return;
            }
        }
        // -----------------------------------------

        Response::exito([
            'id_pago'    => $pago->idPago,
            'referencia' => $pago->referencia,
            'monto'      => $pago->monto,
            'estado'     => $pago->estado,
        ], 'Pago iniciado. Referencia generada; confirma la transacción para completar tu pedido.', 201);
    }

    /**
     * confirmar($id)
     * ------------------------------------------------------------
     * POST /api/pagos/{id}/confirmar  (rol: Cliente)
     * Body: { "aprobado": true, "referencia_pasarela"?: "..." }
     *
     * SIMULA la respuesta que en producción vendría directamente de
     * la pasarela real (PSE/Nequi/etc). Usa Pago::confirmarTransaccion()
     * (Fase 1), que además — si el pago fue aprobado — avanza
     * automáticamente el Pedido a estado 'Confirmado', lo cual a su
     * vez dispara la notificación al cliente (CU016).
     */
    public function confirmar(string $id): void
    {
        $pago = Pago::obtenerPorId((int) $id);
        if ($pago === null) {
            Response::error('El pago indicado no existe.', 404);
            return;
        }

        $pedido = Pedido::obtenerPorId($pago->idPedido);
        if ($pedido === null || $pedido->idCliente !== Sesion::obtenerId()) {
            Response::error('No tienes permiso para confirmar este pago.', 403);
            return;
        }
        if ($pago->estado !== 'Pendiente') {
            Response::error("Este pago ya fue procesado anteriormente (estado actual: '{$pago->estado}').", 409);
            return;
        }

        $datos = Request::jsonBody();
        $aprobado = (bool) ($datos['aprobado'] ?? false);
        $referenciaPasarela = $datos['referencia_pasarela'] ?? null;

        $pago->confirmarTransaccion($aprobado, $referenciaPasarela);

        $mensaje = $aprobado
            ? 'Pago aprobado. Tu pedido fue confirmado exitosamente.'
            : 'El pago fue rechazado. Puedes intentar nuevamente con otro medio de pago.';

        Response::exito([
            'id_pago'    => $pago->idPago,
            'estado'     => $pago->estado,
            'referencia' => $pago->referencia,
        ], $mensaje);
    }

    /**
     * porPedido($idPedido)
     * GET /api/pagos/pedido/{idPedido}  (rol: cualquiera autenticado)
     * Lista los intentos de pago de un pedido (útil si el primer
     * intento fue rechazado y el cliente tuvo que intentar de nuevo).
     */
    public function porPedido(string $idPedido): void
    {
        $pedido = Pedido::obtenerPorId((int) $idPedido);
        if ($pedido === null) {
            Response::error('El pedido indicado no existe.', 404);
            return;
        }
        if (Sesion::obtenerRol() === 'Cliente' && $pedido->idCliente !== Sesion::obtenerId()) {
            Response::error('No tienes permiso para ver los pagos de este pedido.', 403);
            return;
        }

        $pagos = Pago::obtenerPorPedido((int) $idPedido);
        $datos = array_map(fn(Pago $p) => [
            'id_pago'    => $p->idPago,
            'monto'      => $p->monto,
            'medio_pago' => $p->medioPago,
            'estado'     => $p->estado,
            'referencia' => $p->referencia,
            'fecha'      => $p->fecha,
        ], $pagos);

        Response::exito($datos);
    }

    /**
     * comprobante($id)
     * GET /api/pagos/{id}/comprobante  (rol: cualquiera autenticado)
     * Devuelve los datos estructurados del comprobante (Pago::
     * generarComprobante(), Fase 1). La generación del PDF real del
     * comprobante se deja para una fase posterior con la librería de
     * PDFs adecuada; por ahora el frontend puede renderizarlo con estos datos.
     */
    public function comprobante(string $id): void
    {
        $pago = Pago::obtenerPorId((int) $id);
        if ($pago === null) {
            Response::error('El pago indicado no existe.', 404);
            return;
        }

        $pedido = Pedido::obtenerPorId($pago->idPedido);
        if ($pedido !== null && Sesion::obtenerRol() === 'Cliente' && $pedido->idCliente !== Sesion::obtenerId()) {
            Response::error('No tienes permiso para ver este comprobante.', 403);
            return;
        }

        Response::exito($pago->generarComprobante());
    }
    // =========================================================================
    // NUEVOS MÉTODOS PARA INTEGRACIÓN NEQUI
    // =========================================================================

    /**
     * webhookNequi()
     * POST /api/pagos/webhook-nequi
     * Endpoint público que recibe la respuesta asíncrona de Nequi.
     */
    public function webhookNequi(): void
    {
        $payloadRaw = file_get_contents('php://input');
        $datosWebhook = json_decode($payloadRaw, true);

        if (empty($datosWebhook)) {
            Response::error("No payload received", 400);
            return;
        }

        $estadoNequi = $datosWebhook['ResponseMessage']['ResponseBody']['any']['status'] ?? '';
        $referenciaPasarela = $datosWebhook['ResponseMessage']['ResponseBody']['any']['transactionId'] ?? null;
        $referenciaNuestra = $datosWebhook['ResponseMessage']['ResponseBody']['any']['reference1'] ?? null;

        $pago = Pago::obtenerPorReferencia($referenciaNuestra); 
        
        if ($pago) {
            $aprobado = (strtoupper($estadoNequi) === 'APPROVED');
            $pago->confirmarTransaccion($aprobado, $referenciaPasarela);
        }

        http_response_code(200);
        echo json_encode(["status" => "success"]);
    }

    /**
     * Método privado para enviar la petición Push a Nequi
     */
    private function enviarPushNequi($celular, $monto, $referenciaPedido, $tokenAcceso)
    {
        $endpoint = $this->apiUrlNequi . "/payments/v2/-services-paymentservice-unregisteredpayment";

        $cuerpoPeticion = [
            "RequestMessage" => [
                "RequestBody" => [
                    "any" => [
                        "unregisteredPaymentRQ" => [
                            "phoneNumber" => (string)$celular,
                            "code" => $this->codigoComercioNequi,
                            "value" => (string)$monto,
                            "reference1" => (string)$referenciaPedido,
                            "reference2" => "Compra Bellatrix",
                            "reference3" => ""
                        ]
                    ]
                ]
            ]
        ];

        $headers = [
            "Content-Type: application/json",
            "Accept: application/json",
            "x-api-key: " . $this->apiKeyNequi,
            "Authorization: Bearer " . $tokenAcceso
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpoPeticion));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

        $respuesta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) return ["exito" => false, "mensaje" => $error];

        return [
            "exito" => $codigoHttp == 200 || $codigoHttp == 202,
            "datos" => json_decode($respuesta, true)
        ];
    }

    /**
     * Método privado para generar el Token OAuth2
     */
    private function generarTokenNequi()
    {
        $clientId = "TU_CLIENT_ID";
        $clientSecret = "TU_CLIENT_SECRET";
        
        $authUrl = "https://oauth.sandbox.nequi.com/oauth2/token?grant_type=client_credentials";
        $credencialesBase64 = base64_encode($clientId . ":" . $clientSecret);

        $headers = [
            "Authorization: Basic " . $credencialesBase64,
            "Content-Type: application/x-www-form-urlencoded",
            "Accept: application/json"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $authUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $respuesta = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) return null;

        $datos = json_decode($respuesta, true);
        return $datos['access_token'] ?? null;
    }
}
