<?php
// ===================== DB.PHP: CONFIGURACIÓN, CORS, SESIÓN Y BD =====================

if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

error_reporting(0);
ini_set('display_errors', 0);

function response($data, $status = 200) {
    http_response_code($status);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(['error' => 'Error al codificar JSON: ' . json_last_error_msg()]);
        http_response_code(500);
    }
    echo $json;
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    @session_start();
}

$host = 'sql303.infinityfree.com';
$user = 'if0_42028061';
$pass = 'airesS2011';
$db   = 'if0_42028061_megg';

try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        response(['error' => 'Error de conexión MySQL: ' . $conn->connect_error], 500);
    }
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    response(['error' => 'Excepción en MySQL: ' . $e->getMessage()], 500);
}

// Crear estructura si no existe
$checkTables = $conn->query("SHOW TABLES LIKE 'usuarios'");
if ($checkTables && $checkTables->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS usuarios (id INT AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(50) NOT NULL UNIQUE, clave VARCHAR(255) NOT NULL, activo TINYINT(1) DEFAULT 1)");
    $conn->query("CREATE TABLE IF NOT EXISTS clientes (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, email VARCHAR(100), telefono VARCHAR(50), direccion VARCHAR(255))");
    $conn->query("CREATE TABLE IF NOT EXISTS productos (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, descripcion TEXT, precio DECIMAL(10,2) NOT NULL, stock INT DEFAULT 0)");
    $conn->query("CREATE TABLE IF NOT EXISTS pedidos (id INT AUTO_INCREMENT PRIMARY KEY, cliente_id INT NOT NULL, fecha DATE NOT NULL, total DECIMAL(10,2) NOT NULL, pagado TINYINT(1) DEFAULT 0)");
    $conn->query("CREATE TABLE IF NOT EXISTS pedido_detalles (id INT AUTO_INCREMENT PRIMARY KEY, pedido_id INT NOT NULL, producto_id INT NOT NULL, cantidad INT NOT NULL, precio_unitario DECIMAL(10,2) NOT NULL)");

    $resUser = $conn->query("SELECT COUNT(*) as total FROM usuarios");
    if ($resUser && $resUser->fetch_assoc()['total'] == 0) {
        $conn->query("INSERT INTO usuarios (usuario, clave, activo) VALUES ('admin', 'admin', 1)");
    }
}

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        response(['error' => 'No autorizado'], 401);
    }
}
?>
