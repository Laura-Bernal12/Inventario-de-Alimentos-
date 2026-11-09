<?php
require_once 'conexion.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['alimentos'])) {
    echo json_encode(['success' => false, 'error' => 'No se recibieron los datos del menú']);
    exit;
}

try {
    $conexion->beginTransaction();

    foreach ($data['alimentos'] as $alimento) {
        $nombre = $alimento['nombre'];
        $totalNecesario = floatval($alimento['total']);
        $unidadMenu = $alimento['unidad'];
        $responsable = $data['responsable'] ?? 'Admin';
        
        // Convertir la fecha al formato que acepta tu base de datos (YYYY-MM-DD)
        $fechaOriginal = $data['fecha'] ?? date('d/m/Y');
        $partesFecha = explode('/', $fechaOriginal);
        $fechaBD = (count($partesFecha) === 3) ? "{$partesFecha[2]}-{$partesFecha[1]}-{$partesFecha[0]}" : date('Y-m-d');
        
        $horaBD = $data['hora'] ?? date('H:i:s');

        // 1. Descontar del catálogo
        $stmt = $conexion->prepare("SELECT * FROM catalogo WHERE LOWER(TRIM(producto)) = LOWER(TRIM(:nombre))");
        $stmt->execute(['nombre' => $nombre]);
        $prodBD = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($prodBD) {
            preg_match('/^([\d.,]+)/', trim($prodBD['cantidad']), $matches);
            $stockActual = isset($matches[1]) ? floatval(str_replace(',', '.', $matches[1])) : 0;
            
            $nuevoStock = max(0, $stockActual - $totalNecesario);
            $cantidadFormateada = round($nuevoStock, 2) . ' ' . $unidadMenu;

            $updateStmt = $conexion->prepare("UPDATE catalogo SET cantidad = :cantidad WHERE id = :id");
            $updateStmt->execute([
                'cantidad' => $cantidadFormateada,
                'id' => $prodBD['id']
            ]);
        }

        // 2. Insertar en la tabla real de salidas en XAMPP
        $insertSalida = $conexion->prepare("INSERT INTO `reportes de salida` (Producto, Cantidad, Fecha, Hora, Destino, Responsable) VALUES (:producto, :cantidad, :fecha, :hora, :destino, :responsable)");
        $insertSalida->execute([
            'producto' => $nombre,
            'cantidad' => round($totalNecesario, 2) . ' ' . $unidadMenu,
            'fecha' => $fechaBD,
            'hora' => $horaBD,
            'destino' => 'Cocina Escolar',
            'responsable' => $responsable
        ]);
    }

    $conexion->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conexion->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>