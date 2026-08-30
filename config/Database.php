<?php
/**
 * ==========================================================================
 *  Database.php
 * ==========================================================================
 *  ¿QUÉ HACE ESTE ARCHIVO?
 *  Es el ÚNICO lugar del sistema donde se abre la conexión a la base de
 *  datos MySQL "pasteleriaok". Todos los demás Modelos (Producto.php,
 *  Cliente.php, Venta.php, etc.) van a "pedirle" la conexión a esta clase
 *  en lugar de conectarse cada uno por su cuenta.
 *
 *  ¿POR QUÉ SE HACE ASÍ? (Patrón "Singleton")
 *  Abrir una conexión a la base de datos es una operación "costosa" para
 *  el servidor. Si cada modelo abriera su propia conexión, tendríamos
 *  decenas de conexiones abiertas al mismo tiempo sin necesidad.
 *  El patrón Singleton garantiza que, sin importar cuántas veces se llame
 *  a Database::getConnection(), SIEMPRE se reutilice la MISMA conexión.
 *
 *  ¿QUÉ ES PDO?
 *  PDO (PHP Data Objects) es la forma moderna y segura de hablar con la
 *  base de datos en PHP. La usamos con "sentencias preparadas" (prepared
 *  statements), que es la defensa principal contra ataques de Inyección
 *  SQL (SQL Injection), tal como pide la arquitectura del proyecto.
 * ==========================================================================
 */

class Database
{
    // ----------------------------------------------------------------
    // 1. CONFIGURACIÓN DE CONEXIÓN
    //    Ajusta estos 4 valores según tu entorno local (XAMPP/WAMP/Laragon).
    //    Por defecto en XAMPP/WAMP: usuario "root" y contraseña vacía "".
    // ----------------------------------------------------------------
    private const DB_HOST    = "127.0.0.1";
    private const DB_NAME    = "pasteleriaok";
    private const DB_USER    = "root";
    private const DB_PASS    = "";
    private const DB_CHARSET = "utf8mb4";

    /**
     * Aquí se guarda la ÚNICA instancia de la conexión PDO que existirá
     * en toda la ejecución del programa. Empieza en null porque al
     * arrancar el sistema todavía no se ha conectado a nada.
     * @var PDO|null
     */
    private static ?PDO $instancia = null;

    /**
     * Constructor privado.
     * Es privado a propósito: así NADIE puede hacer "new Database()"
     * desde afuera. La única puerta de entrada es getConnection().
     * Esto es lo que hace que el patrón Singleton funcione.
     */
    private function __construct()
    {
    }

    /**
     * getConnection()
     * ----------------------------------------------------------------
     * Este es el método que usarán TODOS los modelos del sistema, así:
     *
     *      $pdo = Database::getConnection();
     *
     * La primera vez que se llama, crea la conexión real a MySQL.
     * Las siguientes veces, simplemente devuelve la conexión que ya
     * estaba abierta (por eso es "static" y guarda el resultado en
     * la propiedad estática $instancia).
     *
     * @return PDO La conexión activa a la base de datos.
     */
    public static function getConnection(): PDO
    {
        // Si todavía no existe una conexión creada, la creamos.
        if (self::$instancia === null) {

            // DSN = Data Source Name. Le dice a PDO a qué motor,
            // servidor, base de datos y codificación conectarse.
            $dsn = "mysql:host=" . self::DB_HOST .
                   ";dbname=" . self::DB_NAME .
                   ";charset=" . self::DB_CHARSET;

            // Opciones recomendadas de seguridad y comportamiento de PDO.
            $opciones = [
                // Si algo falla, que PDO lance una excepción (error controlado)
                // en lugar de fallar en silencio. Esto es clave para poder
                // usar try/catch en los controladores más adelante.
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                // Que los resultados de SELECT se devuelvan como arreglos
                // asociativos, ej: $fila['nombre'] en lugar de $fila[0].
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Desactiva la "emulación" de sentencias preparadas para que
                // PDO use las sentencias preparadas REALES de MySQL.
                // Esto es más seguro contra SQL Injection.
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                self::$instancia = new PDO($dsn, self::DB_USER, self::DB_PASS, $opciones);
            } catch (PDOException $e) {
                // No mostramos el error crudo de PDO al usuario final
                // (podría revelar datos sensibles del servidor), pero sí
                // lo relanzamos como una excepción genérica y clara.
                throw new Exception("Error de conexión a la base de datos: " . $e->getMessage());
            }
        }

        return self::$instancia;
    }

    /**
     * Evita que esta clase pueda ser "clonada", lo cual rompería la
     * garantía de que solo existe UNA conexión (regla del Singleton).
     */
    private function __clone()
    {
    }
}
