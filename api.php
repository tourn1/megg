<?php
// ===================== API.PHP (ROUTER CENTRAL) =====================
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'login':
    case 'logout':
    case 'check':
    case 'test':
        require __DIR__ . '/api_login.php';
        break;

    case 'clientes':
    case 'clientes_select':
        if ($action === 'clientes_select') $_GET['action'] = 'select';
        require __DIR__ . '/api_clientes.php';
        break;

    case 'productos':
    case 'productos_select':
        if ($action === 'productos_select') $_GET['action'] = 'select';
        require __DIR__ . '/api_productos.php';
        break;

    case 'pedidos':
    case 'pedido_detalles':
        if ($action === 'pedido_detalles') $_GET['action'] = 'detalles';
        require __DIR__ . '/api_pedidos.php';
        break;

    case 'usuarios':
        require __DIR__ . '/api_usuarios.php';
        break;

    default:
        require __DIR__ . '/db.php';
        response(['error' => 'Acción no válida en router: ' . $action], 404);
}
?>