<?php
require_once __DIR__ . '/db.php';
checkAuth();

$action = $_GET['action'] ?? '';

if ($action === 'dump') {
    // Generar dump MySQL completo de la base de datos
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="dump_megg_' . date('Y-m-d_H-i-s') . '.sql"');

    echo "-- Backup Dump MySQL - El closet de Megg\n";
    echo "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Host: " . $host . "\n";
    echo "-- Database: " . $db . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tablesResult = $conn->query("SHOW TABLES");
    if ($tablesResult) {
        while ($tableRow = $tablesResult->fetch_row()) {
            $tableName = $tableRow[0];

            // ESTRUCTURA DE LA TABLA
            echo "-- --------------------------------------------------------\n";
            echo "-- Estructura de tabla para `$tableName` \n";
            echo "-- --------------------------------------------------------\n\n";
            echo "DROP TABLE IF EXISTS `$tableName`;\n";

            $createTableResult = $conn->query("SHOW CREATE TABLE `$tableName`");
            if ($createTableResult && $createRow = $createTableResult->fetch_assoc()) {
                echo $createRow['Create Table'] . ";\n\n";
            }

            // DATOS DE LA TABLA
            $rowsResult = $conn->query("SELECT * FROM `$tableName`");
            if ($rowsResult && $rowsResult->num_rows > 0) {
                echo "-- Volcado de datos para `$tableName` \n\n";
                while ($row = $rowsResult->fetch_assoc()) {
                    $keys = array_keys($row);
                    $escapedKeys = array_map(function($k) { return "`$k`"; }, $keys);
                    $values = array_map(function($v) use ($conn) {
                        if ($v === null) return 'NULL';
                        return "'" . $conn->real_escape_string($v) . "'";
                    }, array_values($row));

                    echo "INSERT INTO `$tableName` (" . implode(', ', $escapedKeys) . ") VALUES (" . implode(', ', $values) . ");\n";
                }
                echo "\n";
            }
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
} else {
    response(['error' => 'Acción no válida'], 400);
}
?>
