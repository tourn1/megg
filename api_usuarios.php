<?php
require_once __DIR__ . '/db.php';
checkAuth();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($method === 'GET') {
    $result = $conn->query("SELECT id, usuario, activo FROM usuarios ORDER BY id");
    if (!$result) response(['error' => 'Error al consultar usuarios: ' . $conn->error], 500);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    response($data);
} elseif ($method === 'POST') {
    $usuario = $input['usuario'] ?? '';
    $clave = $input['clave'] ?? '';
    $activo = isset($input['activo']) ? (int)$input['activo'] : 1;
    if (!$usuario || !$clave) response(['error' => 'Usuario y clave requeridos'], 400);

    $check = $conn->query("SELECT id FROM usuarios WHERE usuario = '$usuario'");
    if ($check && $check->num_rows > 0) response(['error' => 'Usuario ya existe'], 409);

    $stmt = $conn->prepare("INSERT INTO usuarios (usuario, clave, activo) VALUES (?, ?, ?)");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('ssi', $usuario, $clave, $activo);
    if ($stmt->execute()) {
        response(['id' => $conn->insert_id, 'success' => true]);
    } else {
        response(['error' => 'Error al insertar usuario'], 500);
    }
} elseif ($method === 'PUT') {
    $id = $input['id'] ?? 0;
    $usuario = $input['usuario'] ?? '';
    $clave = $input['clave'] ?? '';
    $activo = isset($input['activo']) ? (int)$input['activo'] : 1;
    if (!$id || !$usuario || !$clave) response(['error' => 'Datos inválidos'], 400);

    $stmt = $conn->prepare("UPDATE usuarios SET usuario = ?, clave = ?, activo = ? WHERE id = ?");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('ssii', $usuario, $clave, $activo, $id);
    if ($stmt->execute()) {
        response(['success' => true]);
    } else {
        response(['error' => 'Error al actualizar usuario'], 500);
    }
} elseif ($method === 'DELETE') {
    $id = $input['id'] ?? 0;
    if (!$id || $id == 1) response(['error' => 'No se puede eliminar el usuario admin'], 400);

    $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        response(['success' => true]);
    } else {
        response(['error' => 'Error al eliminar usuario'], 500);
    }
} else {
    response(['error' => 'Método no permitido'], 405);
}
?>
