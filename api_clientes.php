<?php
require_once __DIR__ . '/db.php';
checkAuth();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

// ── Foto helper ────────────────────────────────────────────────────────────────
function saveFotoFile($fotoData, $categoria = 'clientes') {
    if (empty($fotoData) || strpos($fotoData, 'data:image/') !== 0) {
        return $fotoData ?? '';
    }
    // Parse header and base64 payload
    list($header, $b64data) = explode(',', $fotoData, 2);
    if      (strpos($header, 'png')  !== false) $ext = 'png';
    elseif  (strpos($header, 'gif')  !== false) $ext = 'gif';
    elseif  (strpos($header, 'webp') !== false) $ext = 'webp';
    else                                         $ext = 'jpg';

    $subdir = __DIR__ . "/uploads/{$categoria}";
    if (!is_dir($subdir)) {
        mkdir($subdir, 0755, true);
    }
    $filename = uniqid('', true) . '.' . $ext;
    $filepath = $subdir . '/' . $filename;
    $imgBytes = base64_decode($b64data);
    if ($imgBytes === false || file_put_contents($filepath, $imgBytes) === false) {
        return '';
    }
    return "uploads/{$categoria}/{$filename}";
}

function deleteFotoFile($fotoPath) {
    if (empty($fotoPath) || strpos($fotoPath, 'uploads/') !== 0) return;
    $fullPath = __DIR__ . '/' . $fotoPath;
    if (file_exists($fullPath)) {
        @unlink($fullPath);
    }
}

// ── SELECT (para dropdowns) ────────────────────────────────────────────────────
if ($action === 'select') {
    $result = $conn->query("SELECT id, nombre FROM clientes ORDER BY nombre");
    if (!$result) response(['error' => 'Error al consultar clientes: ' . $conn->error], 500);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    response($data);
}

// ── GET ────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $result = $conn->query("SELECT * FROM clientes ORDER BY nombre");
    if (!$result) response(['error' => 'Error al consultar clientes: ' . $conn->error], 500);
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    response($data);

// ── POST ───────────────────────────────────────────────────────────────────────
} elseif ($method === 'POST') {
    $nombre    = $input['nombre']    ?? '';
    $email     = $input['email']     ?? '';
    $telefono  = $input['telefono']  ?? '';
    $direccion = $input['direccion'] ?? '';
    $dni       = $input['dni']       ?? '';
    $fotoRaw   = $input['foto']      ?? '';

    if (!$nombre) response(['error' => 'Nombre requerido'], 400);

    // Save image file; if already a path, keep it as-is
    $foto = saveFotoFile($fotoRaw, 'clientes');

    $stmt = $conn->prepare("INSERT INTO clientes (nombre, email, telefono, direccion, dni, foto) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('ssssss', $nombre, $email, $telefono, $direccion, $dni, $foto);
    if ($stmt->execute()) {
        response(['id' => $conn->insert_id, 'success' => true]);
    } else {
        response(['error' => 'Error al insertar cliente'], 500);
    }

// ── PUT ────────────────────────────────────────────────────────────────────────
} elseif ($method === 'PUT') {
    $id        = $input['id']        ?? 0;
    $nombre    = $input['nombre']    ?? '';
    $email     = $input['email']     ?? '';
    $telefono  = $input['telefono']  ?? '';
    $direccion = $input['direccion'] ?? '';
    $dni       = $input['dni']       ?? '';
    $fotoRaw   = $input['foto']      ?? '';

    if (!$id || !$nombre) response(['error' => 'ID y nombre requeridos'], 400);

    // If a new base64 image was sent, delete old file and save new one
    if (!empty($fotoRaw) && strpos($fotoRaw, 'data:image/') === 0) {
        // Get old foto path to delete
        $stmt = $conn->prepare("SELECT foto FROM clientes WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['foto'])) {
            deleteFotoFile($row['foto']);
        }
        $foto = saveFotoFile($fotoRaw, 'clientes');
    } else {
        // Keep existing path (no new image selected)
        $foto = $fotoRaw;
    }

    $stmt = $conn->prepare("UPDATE clientes SET nombre = ?, email = ?, telefono = ?, direccion = ?, dni = ?, foto = ? WHERE id = ?");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('ssssssi', $nombre, $email, $telefono, $direccion, $dni, $foto, $id);
    if ($stmt->execute()) {
        response(['success' => true]);
    } else {
        response(['error' => 'Error al actualizar cliente'], 500);
    }

// ── DELETE ─────────────────────────────────────────────────────────────────────
} elseif ($method === 'DELETE') {
    $id = $input['id'] ?? 0;
    if (!$id) response(['error' => 'ID requerido'], 400);

    // Delete photo file before removing record
    $stmt = $conn->prepare("SELECT foto FROM clientes WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!empty($row['foto'])) {
        deleteFotoFile($row['foto']);
    }

    $stmt = $conn->prepare("DELETE FROM clientes WHERE id = ?");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        response(['success' => true]);
    } else {
        response(['error' => 'Error al eliminar cliente'], 500);
    }

} else {
    response(['error' => 'Método no permitido'], 405);
}
?>
