<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Pedido.php';

/**
 * ==========================================================================
 *  Pago.php
 * ==========================================================================
 *  Mapea la tabla `pago`. Corresponde a la clase "Pago" del Diagrama
 *  de Clases. Implementa la parte de datos del CU014 (Realizar Pago en
 *  Línea): la integración REAL con la pasarela (PSE, tarjetas, Nequi)
 *  se conectará en la Fase 4, pero el modelo ya deja lista la
 *  estructura para registrar el intento de pago y su resultado.
 * ==========================================================================
 */
class Pago
{
    public ?int $idPago;
    public int $idPedido;
    public float $monto;
    public string $medioPago;  // ENUM: 'PSE'|'Tarjeta crédito'|'Tarjeta débito'|'Nequi'|'Otro'
    public string $estado;     // ENUM: 'Pendiente'|'Aprobado'|'Rechazado'|'Error'
    public ?string $referencia;
    public ?string $fecha;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idPago     = $datos['id_pago']     ?? null;
        $this->idPedido   = $datos['id_pedido']   ?? 0;
        $this->monto      = isset($datos['monto']) ? (float)$datos['monto'] : 0.0;
        $this->medioPago  = $datos['medio_pago']  ?? 'Otro';
        $this->estado     = $datos['estado']      ?? 'Pendiente';
        $this->referencia = $datos['referencia']  ?? null;
        $this->fecha      = $datos['fecha']       ?? null;
    }

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases)
    // =================================================================

    /**
     * procesarPago()
     * ------------------------------------------------------------
     * Registra el intento de pago en la BD con estado 'Pendiente' y
     * genera una referencia única interna. La conexión con la
     * pasarela real (llamada HTTP externa) se implementa en Fase 4;
     * este método deja la fila lista para que ese controlador la
     * actualice después con confirmarTransaccion().
     */
    public function procesarPago(): int
    {
        if ($this->referencia === null) {
            // Referencia interna temporal, mientras llega la de la pasarela real.
            $this->referencia = 'REF-' . strtoupper(bin2hex(random_bytes(6)));
        }

        $sql = "INSERT INTO pago (id_pedido, monto, medio_pago, estado, referencia)
                VALUES (:pedido, :monto, :medio, 'Pendiente', :referencia)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':pedido', $this->idPedido, PDO::PARAM_INT);
        $stmt->bindValue(':monto', $this->monto);
        $stmt->bindValue(':medio', $this->medioPago);
        $stmt->bindValue(':referencia', $this->referencia);
        $stmt->execute();

        $this->idPago = (int)$this->pdo->lastInsertId();
        $this->estado = 'Pendiente';
        return $this->idPago;
    }

    /**
     * confirmarTransaccion($aprobado, $referenciaPasarela)
     * ------------------------------------------------------------
     * Actualiza el estado del pago según la respuesta de la pasarela
     * (CU014). Si el pago fue aprobado, además avanza el Pedido
     * asociado al estado 'Confirmado' (usando Pedido::cambiarEstado(),
     * que a su vez dispara la notificación automática al cliente).
     */
    public function confirmarTransaccion(bool $aprobado, ?string $referenciaPasarela = null): bool
    {
        $this->estado = $aprobado ? 'Aprobado' : 'Rechazado';
        if ($referenciaPasarela !== null) {
            $this->referencia = $referenciaPasarela;
        }

        $sql = "UPDATE pago SET estado = :estado, referencia = :referencia WHERE id_pago = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':estado', $this->estado);
        $stmt->bindValue(':referencia', $this->referencia);
        $stmt->bindValue(':id', $this->idPago, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok && $aprobado) {
            // El pago fue aprobado -> el pedido pasa de "Pendiente de pago" a "Confirmado".
            $pedido = Pedido::obtenerPorId($this->idPedido);
            if ($pedido) {
                $pedido->cambiarEstado('Confirmado');
            }
        }

        return $ok;
    }

    /**
     * generarComprobante()
     * ------------------------------------------------------------
     * Devuelve los datos estructurados del comprobante de pago.
     * La generación del ARCHIVO físico (PDF) se hará en una fase
     * posterior usando una librería de PDFs; aquí se entrega el
     * arreglo de datos que ese generador va a necesitar.
     */
    public function generarComprobante(): array
    {
        return [
            'id_pago'     => $this->idPago,
            'id_pedido'   => $this->idPedido,
            'monto'       => $this->monto,
            'medio_pago'  => $this->medioPago,
            'estado'      => $this->estado,
            'referencia'  => $this->referencia,
            'fecha'       => $this->fecha,
        ];
    }

    public static function obtenerPorPedido(int $idPedido): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM pago WHERE id_pedido = :pedido ORDER BY fecha DESC");
        $stmt->bindValue(':pedido', $idPedido, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($fila) => new Pago($fila), $stmt->fetchAll());
    }

    public static function obtenerPorId(int $id): ?Pago
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM pago WHERE id_pago = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $fila = $stmt->fetch();
        return $fila ? new Pago($fila) : null;
    }
}
