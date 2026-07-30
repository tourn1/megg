<?php
require_once __DIR__ . '/db.php';
checkAuth();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

// ── Foto helpers ───────────────────────────────────────────────────────────────
function saveFotoFile($fotoData, $categoria = 'productos') {
    if (empty($fotoData) || strpos($fotoData, 'data:image/') !== 0) return $fotoData ?? '';
    list($header, $b64data) = explode(',', $fotoData, 2);
    if      (strpos($header, 'png')  !== false) $ext = 'png';
    elseif  (strpos($header, 'gif')  !== false) $ext = 'gif';
    elseif  (strpos($header, 'webp') !== false) $ext = 'webp';
    else                                         $ext = 'jpg';
    $subdir = __DIR__ . "/uploads/{$categoria}";
    if (!is_dir($subdir)) mkdir($subdir, 0755, true);
    $filename = uniqid('', true) . '.' . $ext;
    $imgBytes = base64_decode($b64data);
    if ($imgBytes === false || file_put_contents($subdir . '/' . $filename, $imgBytes) === false) return '';
    return "uploads/{$categoria}/{$filename}";
}

function deleteFotoFile($fotoPath) {
    if (empty($fotoPath) || strpos($fotoPath, 'uploads/') !== 0) return;
    $fullPath = __DIR__ . '/' . $fotoPath;
    if (file_exists($fullPath)) @unlink($fullPath);
}

// ── SELECT (dropdown) ──────────────────────────────────────────────────────────
if ($action === 'select') {
    $result = $conn->query("SELECT id, nombre, COALESCE(precio_venta, precio) AS precio FROM productos ORDER BY nombre");
    if (!$result) response(['error' => 'Error al consultar productos: ' . $conn->error], 500);
    $data = [];
    while ($row = $result->fetch_assoc()) $data[] = $row;
    response($data);
}

// ── GET ────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $result = $conn->query("SELECT * FROM productos ORDER BY nombre");
    if (!$result) response(['error' => 'Error al consultar productos: ' . $conn->error], 500);
    $data = [];
    while ($row = $result->fetch_assoc()) $data[] = $row;
    response($data);

// ── POST ───────────────────────────────────────────────────────────────────────
} elseif ($method === 'POST') {
    $nombre        = $input['nombre']        ?? '';
    $descripcion   = $input['descripcion']   ?? '';
    $precio_costo  = floatval($input['precio_costo'] ?? 0);
    $precio_venta  = floatval($input['precio_venta'] ?? ($input['precio'] ?? 0));
    $stock         = isset($input['stock']) ? intval($input['stock']) : 1;
    $fotoRaw       = $input['foto']          ?? '';
    if (!$nombre || $precio_venta <= 0) response(['error' => 'Nombre y precio de venta requeridos'], 400);

    $foto = saveFotoFile($fotoRaw, 'productos');
    $stmt = $conn->prepare("INSERT INTO productos (nombre, descripcion, precio_costo, precio_venta, precio, stock, foto) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('ssdddis', $nombre, $descripcion, $precio_costo, $precio_venta, $precio_venta, $stock, $foto);
    if ($stmt->execute()) {
        response(['id' => $conn->insert_id, 'success' => true]);
    } else {
        response(['error' => 'Error al insertar producto'], 500);
    }

// ── PUT ────────────────────────────────────────────────────────────────────────
} elseif ($method === 'PUT') {
    $id            = $input['id']            ?? 0;
    $nombre        = $input['nombre']        ?? '';
    $descripcion   = $input['descripcion']   ?? '';
    $precio_costo  = floatval($input['precio_costo'] ?? 0);
    $precio_venta  = floatval($input['precio_venta'] ?? ($input['precio'] ?? 0));
    $stock         = isset($input['stock']) ? intval($input['stock']) : 1;
    $fotoRaw       = $input['foto']          ?? '';
    if (!$id || !$nombre || $precio_venta <= 0) response(['error' => 'Datos inválidos'], 400);

    if (!empty($fotoRaw) && strpos($fotoRaw, 'data:image/') === 0) {
        $stmt = $conn->prepare("SELECT foto FROM productos WHERE id = ?");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['foto'])) deleteFotoFile($row['foto']);
        $foto = saveFotoFile($fotoRaw, 'productos');
    } else {
        $foto = $fotoRaw;
    }

    $stmt = $conn->prepare("UPDATE productos SET nombre = ?, descripcion = ?, precio_costo = ?, precio_venta = ?, precio = ?, stock = ?, foto = ? WHERE id = ?");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('ssdddisi', $nombre, $descripcion, $precio_costo, $precio_venta, $precio_venta, $stock, $foto, $id);
    if ($stmt->execute()) {
        response(['success' => true]);
    } else {
        response(['error' => 'Error al actualizar producto'], 500);
    }

// ── DELETE ─────────────────────────────────────────────────────────────────────
} elseif ($method === 'DELETE') {
    $id = $input['id'] ?? 0;
    if (!$id) response(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT foto FROM productos WHERE id = ?");
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!empty($row['foto'])) deleteFotoFile($row['foto']);

    $stmt = $conn->prepare("DELETE FROM productos WHERE id = ?");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        response(['success' => true]);
    } else {
        response(['error' => 'Error al eliminar producto'], 500);
    }
} else {
    response(['error' => 'Método no permitido'], 405);
}
?>
