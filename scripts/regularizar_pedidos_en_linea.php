<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo se puede ejecutar desde la consola.');
}

require_once __DIR__ . '/../models/Pedido.php';
require_once __DIR__ . '/../models/Venta.php';

$aplicar = in_array('--aplicar', $argv, true);
$pdo = Database::getConnection();

$ids = $pdo->query("SELECT p.id_pedido FROM pedido p
                    WHERE p.estado IN ('Confirmado','En preparación','Listo para recoger','Entregado')
                      AND NOT EXISTS (SELECT 1 FROM ventas v WHERE v.id_pedido = p.id_pedido)
                    ORDER BY p.id_pedido")->fetchAll(PDO::FETCH_COLUMN);

echo ($aplicar ? "APLICANDO" : "SIMULACIÓN") . ": " . count($ids) . " pedido(s) pagado(s) sin venta.\n";

foreach ($ids as $id) {
    $pedido = Pedido::obtenerPorId((int)$id);
    echo "  Pedido #{$id} [{$pedido->estado}] total {$pedido->total}: ";
    if (!$aplicar) {
        echo "se registraría\n";
        continue;
    }
    try {
        $ventas = Venta::registrarDesdePedido($pedido, $pedido->idEmpleadoGestion !== null ? (int)$pedido->idEmpleadoGestion : null);
        echo "OK (ventas: " . implode(',', $ventas) . ")\n";
    } catch (Exception $e) {
        echo "OMITIDO -> " . $e->getMessage() . "\n";
    }
}
