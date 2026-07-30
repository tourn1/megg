<?php
require_once __DIR__ . '/db.php';
checkAuth();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

// ── Foto helpers ───────────────────────────────────────────────────────────────
function saveFotoFile($fotoData, $categoria = 'pedidos') {
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

// ── Detalles ───────────────────────────────────────────────────────────────────
if ($action === 'detalles') {
    $pedido_id = isset($_GET['pedido_id']) ? (int)$_GET['pedido_id'] : 0;
    if (!$pedido_id) response(['error' => 'pedido_id requerido'], 400);
    $stmt = $conn->prepare("SELECT * FROM pedido_detalles WHERE pedido_id = ?");
    if (!$stmt) response(['error' => 'Error SQL: ' . $conn->error], 500);
    $stmt->bind_param('i', $pedido_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) $data[] = $row;
    response($data);
}

// ── GET ────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $result = $conn->query("
        SELECT p.*, c.nombre as cliente_nombre 
        FROM pedidos p 
        LEFT JOIN clientes c ON p.cliente_id = c.id 
        ORDER BY p.id DESC
    ");
    if (!$result) response(['error' => 'Error al consultar pedidos: ' . $conn->error], 500);
    $data = [];
    while ($row = $result->fetch_assoc()) $data[] = $row;
    response($data);

// ── POST ───────────────────────────────────────────────────────────────────────
} elseif ($method === 'POST') {
    $cliente_id = $input['cliente_id'] ?? 0;
    $fecha      = $input['fecha']      ?? date('Y-m-d');
    $total      = $input['total']      ?? 0;
    $pagado     = (!empty($input['pagado']) && ($input['pagado'] === true || $input['pagado'] === 1 || $input['pagado'] === '1' || $input['pagado'] === 'true')) ? 1 : 0;
    $fotoRaw    = $input['foto']       ?? '';
    if (!$cliente_id || $total < 0) response(['error' => 'Datos inválidos'], 400);

    $foto = saveFotoFile($fotoRaw, 'pedidos');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO pedidos (cliente_id, fecha, total, pagado, foto) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('isdis', $cliente_id, $fecha, $total, $pagado, $foto);
        $stmt->execute();
        $pedido_id = $conn->insert_id;

        $detalles = $input['detalles'] ?? [];
        foreach ($detalles as $det) {
            $producto_id     = $det['producto_id']     ?? 0;
            $cantidad        = $det['cantidad']        ?? 0;
            $precio_unitario = $det['precio_unitario'] ?? 0;
            if ($producto_id && $cantidad > 0) {
                $stmt2 = $conn->prepare("INSERT INTO pedido_detalles (pedido_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
                $stmt2->bind_param('iiid', $pedido_id, $producto_id, $cantidad, $precio_unitario);
                $stmt2->execute();
            }
        }
        $conn->commit();
        response(['id' => $pedido_id, 'success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        response(['error' => 'Error al guardar pedido: ' . $e->getMessage()], 500);
    }

// ── PUT ────────────────────────────────────────────────────────────────────────
} elseif ($method === 'PUT') {
    $id         = $input['id']         ?? 0;
    $cliente_id = $input['cliente_id'] ?? 0;
    $fecha      = $input['fecha']      ?? date('Y-m-d');
    $total      = $input['total']      ?? 0;
    $pagado     = (!empty($input['pagado']) && ($input['pagado'] === true || $input['pagado'] === 1 || $input['pagado'] === '1' || $input['pagado'] === 'true')) ? 1 : 0;
    $fotoRaw    = $input['foto']       ?? '';
    if (!$id || !$cliente_id) response(['error' => 'ID y cliente requeridos'], 400);

    if (!empty($fotoRaw) && strpos($fotoRaw, 'data:image/') === 0) {
        $stmt = $conn->prepare("SELECT foto FROM pedidos WHERE id = ?");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['foto'])) deleteFotoFile($row['foto']);
        $foto = saveFotoFile($fotoRaw, 'pedidos');
    } else {
        $foto = $fotoRaw;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE pedidos SET cliente_id = ?, fecha = ?, total = ?, pagado = ?, foto = ? WHERE id = ?");
        $stmt->bind_param('isdisi', $cliente_id, $fecha, $total, $pagado, $foto, $id);
        $stmt->execute();

        $conn->query("DELETE FROM pedido_detalles WHERE pedido_id = $id");
        $detalles = $input['detalles'] ?? [];
        foreach ($detalles as $det) {
            $producto_id     = $det['producto_id']     ?? 0;
            $cantidad        = $det['cantidad']        ?? 0;
            $precio_unitario = $det['precio_unitario'] ?? 0;
            if ($producto_id && $cantidad > 0) {
                $stmt2 = $conn->prepare("INSERT INTO pedido_detalles (pedido_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
                $stmt2->bind_param('iiid', $id, $producto_id, $cantidad, $precio_unitario);
                $stmt2->execute();
            }
        }
        $conn->commit();
        response(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        response(['error' => 'Error al actualizar pedido: ' . $e->getMessage()], 500);
    }

// ── DELETE ─────────────────────────────────────────────────────────────────────
} elseif ($method === 'DELETE') {
    $id = $input['id'] ?? 0;
    if (!$id) response(['error' => 'ID requerido'], 400);

    $stmt = $conn->prepare("SELECT foto FROM pedidos WHERE id = ?");
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!empty($row['foto'])) deleteFotoFile($row['foto']);

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM pedido_detalles WHERE pedido_id = $id");
        $conn->query("DELETE FROM pedidos WHERE id = $id");
        $conn->commit();
        response(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        response(['error' => 'Error al eliminar pedido: ' . $e->getMessage()], 500);
    }
} else {
    response(['error' => 'Método no permitido'], 405);
}
?>
