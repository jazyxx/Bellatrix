<?php
require_once __DIR__ . '/../config/Database.php';

class GestorVentas
{
    public ?int $idGestor;
    public string $canal;          
    public string $unidadNegocio;  
    public string $fecha;          
    public float $totalVentas;
    public float $totalEgresos;
    public float $saldo;           
    public ?int $idEmpleado;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idGestor      = $datos['id_gestor']      ?? null;
        $this->canal          = $datos['canal']          ?? 'Presencial';
        $this->unidadNegocio  = $datos['unidad_negocio'] ?? 'Pastelería';
        $this->fecha          = $datos['fecha']          ?? date('Y-m-d');
        $this->totalVentas    = isset($datos['total_ventas']) ? (float)$datos['total_ventas'] : 0.0;
        $this->totalEgresos   = isset($datos['total_egresos']) ? (float)$datos['total_egresos'] : 0.0;
        $this->saldo          = isset($datos['saldo']) ? (float)$datos['saldo'] : ($this->totalVentas - $this->totalEgresos);
        $this->idEmpleado     = $datos['id_empleado']   ?? null;
    }

    public static function obtenerOCrearCajaDelDia(string $canal, string $unidad, ?string $fecha = null, ?int $idEmpleado = null): GestorVentas
    {
        $fecha = $fecha ?? date('Y-m-d');
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE canal = :canal AND unidad_negocio = :unidad AND fecha = :fecha");
        $stmt->bindValue(':canal', $canal);
        $stmt->bindValue(':unidad', $unidad);
        $stmt->bindValue(':fecha', $fecha);
        $stmt->execute();

        $fila = $stmt->fetch();
        if ($fila) {
            return new GestorVentas($fila);
        }

        $insertar = $pdo->prepare("INSERT INTO gestor_ventas (canal, unidad_negocio, fecha, total_ventas, total_egresos, id_empleado)
                                    VALUES (:canal, :unidad, :fecha, 0, 0, :empleado)");
        $insertar->bindValue(':canal', $canal);
        $insertar->bindValue(':unidad', $unidad);
        $insertar->bindValue(':fecha', $fecha);
        $insertar->bindValue(':empleado', $idEmpleado, PDO::PARAM_INT);
        $insertar->execute();

        return new GestorVentas([
            'id_gestor' => (int)$pdo->lastInsertId(),
            'canal' => $canal, 'unidad_negocio' => $unidad, 'fecha' => $fecha,
            'total_ventas' => 0, 'total_egresos' => 0, 'saldo' => 0, 'id_empleado' => $idEmpleado,
        ]);
    }

    public function registrarIngreso(float $monto): bool
    {
        $nuevoTotal = $this->totalVentas + $monto;

        $sql = "UPDATE gestor_ventas SET total_ventas = :total WHERE id_gestor = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':total', $nuevoTotal);
        $stmt->bindValue(':id', $this->idGestor, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->totalVentas = $nuevoTotal;
            $this->saldo = $this->totalVentas - $this->totalEgresos;
        }
        return $ok;
    }

    public function revertirIngreso(float $monto): bool
    {
        $nuevoTotal = max(0.0, $this->totalVentas - $monto);

        $sql = "UPDATE gestor_ventas SET total_ventas = :total WHERE id_gestor = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':total', $nuevoTotal);
        $stmt->bindValue(':id', $this->idGestor, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->totalVentas = $nuevoTotal;
            $this->saldo = $this->totalVentas - $this->totalEgresos;
        }
        return $ok;
    }

    public function registrarEgreso(float $monto): bool
    {
        $saldoActual = $this->totalVentas - $this->totalEgresos;

        if ($monto > $saldoActual) {
            throw new Exception(
                "Egreso rechazado: el monto (\${$monto}) supera el saldo disponible " .
                "(\${$saldoActual}) en la caja de {$this->unidadNegocio} - {$this->canal}."
            );
        }

        $nuevoTotalEgresos = $this->totalEgresos + $monto;

        $sql = "UPDATE gestor_ventas SET total_egresos = :total WHERE id_gestor = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':total', $nuevoTotalEgresos);
        $stmt->bindValue(':id', $this->idGestor, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->totalEgresos = $nuevoTotalEgresos;
            $this->saldo = $this->totalVentas - $this->totalEgresos;
        }
        return $ok;
    }

    public static function gestionarVentasPorTipo(string $tipo, string $valor): array
    {
        return match ($tipo) {
            'canal'  => self::separarPorCanal($valor),
            'unidad' => self::separarPorUnidad($valor),
            default  => throw new Exception("Tipo de gestión no reconocido: '{$tipo}'."),
        };
    }

    
    public static function separarPorCanal(string $canal): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE canal = :canal ORDER BY fecha DESC");
        $stmt->bindValue(':canal', $canal);
        $stmt->execute();

        return array_map(fn($fila) => new GestorVentas($fila), $stmt->fetchAll());
    }

    
    public static function separarPorUnidad(string $unidad): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE unidad_negocio = :unidad ORDER BY fecha DESC");
        $stmt->bindValue(':unidad', $unidad);
        $stmt->execute();

        return array_map(fn($fila) => new GestorVentas($fila), $stmt->fetchAll());
    }

    public static function generarReporteDiario(?string $fecha = null): array
    {
        $fecha = $fecha ?? date('Y-m-d');
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE fecha = :fecha");
        $stmt->bindValue(':fecha', $fecha);
        $stmt->execute();

        return array_map(fn($fila) => new GestorVentas($fila), $stmt->fetchAll());
    }

    
    public static function generarReporteSemanal(string $fechaInicio): array
    {
        $fechaFin = date('Y-m-d', strtotime($fechaInicio . ' +6 days'));
        return self::generarReportePorRango($fechaInicio, $fechaFin);
    }

    
    public static function generarReporteMensual(int $anio, int $mes): array
    {
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin = date('Y-m-t', strtotime($fechaInicio)); 
        return self::generarReportePorRango($fechaInicio, $fechaFin);
    }

    public static function generarReportePorRango(string $fechaInicio, string $fechaFin): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE fecha BETWEEN :inicio AND :fin ORDER BY fecha ASC");
        $stmt->bindValue(':inicio', $fechaInicio);
        $stmt->bindValue(':fin', $fechaFin);
        $stmt->execute();

        return array_map(fn($fila) => new GestorVentas($fila), $stmt->fetchAll());
    }

    public function exportarRegistros(string $formato = 'array'): array
    {
        return [
            'id_gestor'      => $this->idGestor,
            'canal'          => $this->canal,
            'unidad_negocio' => $this->unidadNegocio,
            'fecha'          => $this->fecha,
            'total_ventas'   => $this->totalVentas,
            'total_egresos'  => $this->totalEgresos,
            'saldo'          => $this->saldo,
            'formato_solicitado' => $formato,
        ];
    }
}
