<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Pedido.php';
require_once __DIR__ . '/Notificacion.php';

class Pago
{
    public ?int $idPago;
    public int $idPedido;
    public float $monto;
    public string $medioPago;  
    public string $estado;     
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

    public function procesarPago(): int
    {
        if ($this->referencia === null) {
            
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

    public function confirmarTransaccion(bool $aprobado, ?string $referenciaPasarela = null): bool
    {
        
        
        $propia = !$this->pdo->inTransaction();
        if ($propia) {
            $this->pdo->beginTransaction();
        }
        $estadoPrevio = $this->estado;
        try {
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
                
                $pedido = Pedido::obtenerPorId($this->idPedido);
                if ($pedido) {
                    $pedido->cambiarEstado('Confirmado');
                }
            }
            if ($propia) {
                $this->pdo->commit();
                
                Notificacion::despacharPendientes();
            }
        } catch (Exception $e) {
            if ($propia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            
            Notificacion::descartarPendientes();
            $this->estado = $estadoPrevio;
            throw $e;
        }

        return $ok;
    }

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
