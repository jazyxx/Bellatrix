<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  GestorVentas.php
 * ==========================================================================
 *  Mapea la tabla `gestor_ventas`, que en este sistema funciona como la
 *  "CAJA" de cada unidad de negocio (Pastelería / Heladería) por canal
 *  y por día. Corresponde a la clase "GestorVentas" del Diagrama de Clases.
 *
 *  DATO CLAVE DE LA BASE DE DATOS:
 *  La columna `saldo` es GENERATED ALWAYS AS (total_ventas - total_egresos)
 *  STORED. Esto significa que MySQL la calcula automáticamente: nuestro
 *  código NUNCA debe hacer INSERT ni UPDATE sobre `saldo` directamente
 *  (por eso no aparece en los INSERT/UPDATE de esta clase).
 *
 *  Esta clase es la que implementa el CU018: "Bloqueo de egresos si
 *  superan el saldo disponible en la caja de la unidad seleccionada"
 *  y también el CU018 (Gestionar Cajas por Unidad), manteniendo
 *  Pastelería y Heladería completamente independientes gracias a la
 *  restricción UNIQUE (canal, unidad_negocio, fecha) de la tabla.
 * ==========================================================================
 */
class GestorVentas
{
    public ?int $idGestor;
    public string $canal;          // ENUM: 'Presencial'|'En línea'
    public string $unidadNegocio;  // ENUM: 'Pastelería'|'Heladería'
    public string $fecha;          // DATE (Y-m-d)
    public float $totalVentas;
    public float $totalEgresos;
    public float $saldo;           // Solo lectura: la calcula MySQL.
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

    /**
     * obtenerOCrearCajaDelDia($canal, $unidad, $fecha, $idEmpleado)
     * ------------------------------------------------------------
     * Como (canal, unidad_negocio, fecha) es UNIQUE en la BD, este
     * método busca la "caja" de ese día para esa combinación exacta,
     * o la crea en 0 si es la primera venta/egreso del día.
     * Esto garantiza la independencia total entre Pastelería y
     * Heladería que pide el CU018.
     */
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

    // =================================================================
    //  MÉTODOS DE NEGOCIO (Diagrama de Clases + CU018)
    // =================================================================

    /**
     * registrarIngreso($monto)
     * Suma un ingreso (venta) a la caja del día. Es el método que
     * usará el Controlador de Ventas cada vez que Venta::finalizar()
     * se ejecute con éxito.
     */
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

    /**
     * registrarEgreso($monto)
     * ------------------------------------------------------------
     * IMPLEMENTA EL CU018: "Bloqueo de egresos si superan el saldo
     * disponible en la caja de la unidad seleccionada".
     *
     * Antes de registrar el egreso, valida que el saldo actual
     * (total_ventas - total_egresos) sea suficiente. Si no lo es,
     * lanza una excepción y NO permite el egreso.
     */
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

    /**
     * gestionarVentasPorTipo($tipo)
     * $tipo: 'canal' o 'unidad'. Atajo que delega en los métodos
     * separarPorCanal()/separarPorUnidad() según lo que se pida.
     */
    public static function gestionarVentasPorTipo(string $tipo, string $valor): array
    {
        return match ($tipo) {
            'canal'  => self::separarPorCanal($valor),
            'unidad' => self::separarPorUnidad($valor),
            default  => throw new Exception("Tipo de gestión no reconocido: '{$tipo}'."),
        };
    }

    /** separarPorCanal($canal): trae todos los registros de caja de un canal ('Presencial'|'En línea'). */
    public static function separarPorCanal(string $canal): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE canal = :canal ORDER BY fecha DESC");
        $stmt->bindValue(':canal', $canal);
        $stmt->execute();

        return array_map(fn($fila) => new GestorVentas($fila), $stmt->fetchAll());
    }

    /** separarPorUnidad($unidad): trae todos los registros de caja de una unidad de negocio. */
    public static function separarPorUnidad(string $unidad): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE unidad_negocio = :unidad ORDER BY fecha DESC");
        $stmt->bindValue(':unidad', $unidad);
        $stmt->execute();

        return array_map(fn($fila) => new GestorVentas($fila), $stmt->fetchAll());
    }

    /**
     * generarReporteDiario($fecha)
     * Trae todas las cajas (de todas las unidades y canales) de un día puntual.
     */
    public static function generarReporteDiario(?string $fecha = null): array
    {
        $fecha = $fecha ?? date('Y-m-d');
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE fecha = :fecha");
        $stmt->bindValue(':fecha', $fecha);
        $stmt->execute();

        return array_map(fn($fila) => new GestorVentas($fila), $stmt->fetchAll());
    }

    /** generarReporteSemanal($fechaInicioSemana): reporte de los 7 días desde la fecha dada. */
    public static function generarReporteSemanal(string $fechaInicio): array
    {
        $fechaFin = date('Y-m-d', strtotime($fechaInicio . ' +6 days'));
        return self::generarReportePorRango($fechaInicio, $fechaFin);
    }

    /** generarReporteMensual($año, $mes): reporte de todo un mes calendario. */
    public static function generarReporteMensual(int $anio, int $mes): array
    {
        $fechaInicio = sprintf('%04d-%02d-01', $anio, $mes);
        $fechaFin = date('Y-m-t', strtotime($fechaInicio)); // 't' = último día del mes.
        return self::generarReportePorRango($fechaInicio, $fechaFin);
    }

    /**
     * generarReportePorRango($fechaInicio, $fechaFin)
     * Método genérico de rango de fechas, reutilizado por los tres
     * anteriores y también por Administrador::generarReporte().
     */
    public static function generarReportePorRango(string $fechaInicio, string $fechaFin): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM gestor_ventas WHERE fecha BETWEEN :inicio AND :fin ORDER BY fecha ASC");
        $stmt->bindValue(':inicio', $fechaInicio);
        $stmt->bindValue(':fin', $fechaFin);
        $stmt->execute();

        return array_map(fn($fila) => new GestorVentas($fila), $stmt->fetchAll());
    }

    /**
     * exportarRegistros($formato)
     * ------------------------------------------------------------
     * Devuelve los registros de caja ya estructurados como arreglo
     * asociativo, listos para que un controlador de la Fase 3/6 los
     * convierta al formato solicitado (CSV, Excel o PDF). La
     * generación del ARCHIVO final se hace fuera del modelo (con una
     * librería específica), respetando la separación de capas del MVC.
     */
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
