<?php
require_once __DIR__ . '/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($action === 'login') {
    $usuario = $input['usuario'] ?? '';
    $clave = $input['clave'] ?? '';
    if (!$usuario || !$clave) response(['error' => 'Faltan credenciales'], 400);

    $stmt = $conn->prepare("SELECT id, usuario, activo FROM usuarios WHERE usuario = ? AND clave = ? AND activo = 1");
    if (!$stmt) response(['error' => 'Error en consulta login: ' . $conn->error], 500);

    $stmt->bind_param('ss', $usuario, $clave);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['usuario'] = $row['usuario'];
        response(['success' => true, 'usuario' => $row['usuario']]);
    } else {
        response(['error' => 'Usuario o contraseña incorrectos'], 401);
    }
} elseif ($action === 'logout') {
    session_destroy();
    response(['success' => true]);
} elseif ($action === 'check') {
    if (isset($_SESSION['user_id'])) {
        response(['authenticated' => true, 'usuario' => $_SESSION['usuario'] ?? 'admin']);
    } else {
        response(['authenticated' => false], 401);
    }
} else {
    response(['error' => 'Acción no válida en login'], 404);
}
?>
