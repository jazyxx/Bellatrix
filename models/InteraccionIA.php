<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * ==========================================================================
 *  InteraccionIA.php
 * ==========================================================================
 *  Mapea la tabla `interaccion_ia`. Guarda cada mensaje (tanto del
 *  Administrador como del Asistente de IA) del chat financiero que vive
 *  en admin_caja.html. Es el historial persistente de la conversación:
 *  así, si el Administrador cierra el widget o recarga la página, no
 *  pierde el contexto de lo que ya había preguntado.
 *
 *  Usado por AsistenteIAController.php.
 * ==========================================================================
 */
class InteraccionIA
{
    public ?int $idInteraccion;
    public int $idEmpleado;
    public string $rolMensaje; // ENUM: 'usuario' | 'asistente'
    public string $mensaje;
    public ?string $creadoEn;

    private PDO $pdo;

    public function __construct(array $datos = [])
    {
        $this->pdo = Database::getConnection();

        $this->idInteraccion = $datos['id_interaccion'] ?? null;
        $this->idEmpleado    = (int) ($datos['id_empleado'] ?? 0);
        $this->rolMensaje    = $datos['rol_mensaje'] ?? 'usuario';
        $this->mensaje       = $datos['mensaje'] ?? '';
        $this->creadoEn      = $datos['creado_en'] ?? null;
    }

    /**
     * crear()
     * ------------------------------------------------------------
     * Inserta este mensaje (de usuario o de asistente) en la BD.
     * Devuelve el id_interaccion recién creado.
     */
    public function crear(): int
    {
        $sql = "INSERT INTO interaccion_ia (id_empleado, rol_mensaje, mensaje) VALUES (:empleado, :rol, :mensaje)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':empleado', $this->idEmpleado, PDO::PARAM_INT);
        $stmt->bindValue(':rol', $this->rolMensaje);
        $stmt->bindValue(':mensaje', $this->mensaje);
        $stmt->execute();

        $this->idInteraccion = (int) $this->pdo->lastInsertId();
        return $this->idInteraccion;
    }

    /**
     * listarPorEmpleado($idEmpleado, $limite)
     * ------------------------------------------------------------
     * Devuelve los últimos $limite mensajes de este empleado, en orden
     * CRONOLÓGICO (del más antiguo al más reciente) — a diferencia de
     * Notificacion::listarPorCliente() (que muestra lo más nuevo
     * primero para una bandeja), aquí se necesita orden cronológico
     * porque esta lista se reenvía tal cual como "historial de
     * conversación" al proveedor de IA.
     */
    public static function listarPorEmpleado(int $idEmpleado, int $limite = 20): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT * FROM interaccion_ia WHERE id_empleado = :empleado ORDER BY creado_en DESC LIMIT :limite"
        );
        $stmt->bindValue(':empleado', $idEmpleado, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        // Se pide DESC (más reciente primero) por eficiencia del LIMIT,
        // y se invierte en PHP para devolver del más antiguo al más
        // reciente, que es el orden que espera una conversación de chat.
        $filas = array_reverse($stmt->fetchAll());
        return array_map(fn($fila) => new InteraccionIA($fila), $filas);
    }

    /**
     * borrarHistorial($idEmpleado)
     * ------------------------------------------------------------
     * Utilidad para el botón "Nueva conversación" del widget: borra
     * todo el historial de este empleado y reinicia el contexto.
     */
    public static function borrarHistorial(int $idEmpleado): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM interaccion_ia WHERE id_empleado = :empleado");
        $stmt->bindValue(':empleado', $idEmpleado, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
