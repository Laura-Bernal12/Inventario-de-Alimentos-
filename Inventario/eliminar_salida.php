<?php
// Desactivar salida de errores en HTML para no corromper el JSON
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once 'conexion.php';

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['id'])) {
        echo json_encode(['success' => false, 'error' => 'ID no proporcionado en la solicitud']);
        exit;
    }

    $id = intval($data['id']);

    // Consulta limpia y exacta para tu tabla
    $stmt = $conexion->prepare("DELETE FROM reportes_salida_menu WHERE id = ? OR ID = ?");
    $stmt->execute([$id, $id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>