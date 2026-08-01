<?php
require_once __DIR__ . '/db.php';
checkAuth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Obtenemos los pedidos con costo total calculado a partir de los detalles y productos
    $sql = "
        SELECT 
            p.id, 
            p.cliente_id, 
            c.nombre as cliente_nombre, 
            p.fecha, 
            p.total, 
            p.pagado, 
            p.entregado,
            COALESCE(
                (SELECT SUM(d.cantidad * pr.precio_costo) 
                 FROM pedido_detalles d 
                 JOIN productos pr ON d.producto_id = pr.id 
                 WHERE d.pedido_id = p.id), 0
            ) as costo_total
        FROM pedidos p 
        LEFT JOIN clientes c ON p.cliente_id = c.id 
        ORDER BY p.fecha DESC, p.id DESC
    ";
    $result = $conn->query($sql);
    if (!$result) response(['error' => 'Error al consultar reporte: ' . $conn->error], 500);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    response($data);
} else {
    response(['error' => 'Método no permitido'], 405);
}
?>
