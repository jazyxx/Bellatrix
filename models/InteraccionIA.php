<?php
require_once __DIR__ . '/../config/Database.php';

class InteraccionIA
{
    public ?int $idInteraccion;
    public int $idEmpleado;
    public string $rolMensaje; 
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

    public static function listarPorEmpleado(int $idEmpleado, int $limite = 20): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT * FROM interaccion_ia WHERE id_empleado = :empleado ORDER BY creado_en DESC LIMIT :limite"
        );
        $stmt->bindValue(':empleado', $idEmpleado, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
    
        $filas = array_reverse($stmt->fetchAll());
        return array_map(fn($fila) => new InteraccionIA($fila), $filas);
    }

    public static function borrarHistorial(int $idEmpleado): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM interaccion_ia WHERE id_empleado = :empleado");
        $stmt->bindValue(':empleado', $idEmpleado, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
