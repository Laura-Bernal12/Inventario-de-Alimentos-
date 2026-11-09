<?php
// Habilitar errores para diagnóstico
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conexion.php';

header('Content-Type: application/json');

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['alimentos']) || empty($data['alimentos'])) {
        echo json_encode(['success' => false, 'error' => 'Datos incompletos o vacíos']);
        exit;
    }

    $alimentos = $data['alimentos'];
    $responsable = $data['responsable'] ?? 'Admin';
    
    // FORZAR LA FECHA Y HORA ACTUALES DEL SERVIDOR (Ignorando valores vacíos o erróneos)
    $fecha = date('Y-m-d'); // Formato exacto YYYY-MM-DD para MySQL
    $hora = date('H:i:s');   // Formato exacto HH:MM:SS para MySQL
    
    $destino = 'Cocina Escolar'; 

    $conexion->beginTransaction();

    foreach ($alimentos as $item) {
        $nombreAlimento = $item['nombre'];
        $cantidadConsumida = floatval($item['total']);
        $unidad = $item['unidad'] ?? '';
        $cantidadTexto = round($cantidadConsumida, 2) . ($unidad ? ' ' . $unidad : '');

        // 1. Consultar y actualizar stock en el catálogo
        $stmtCat = $conexion->prepare("SELECT cantidad FROM catalogo WHERE producto = ?");
        $stmtCat->execute([$nombreAlimento]);
        $catRow = $stmtCat->fetch(PDO::FETCH_ASSOC);

        if ($catRow) {
            $cantStr = trim($catRow['cantidad']);
            preg_match('/^([\d.,]+)\s*(.*)$/', $cantStr, $matches);
            $numActual = isset($matches[1]) ? floatval(str_replace(',', '.', $matches[1])) : floatval($cantStr);
            $unidadCat = isset($matches[2]) ? $matches[2] : $unidad;

            $nuevoNum = max(0, $numActual - $cantidadConsumida);
            $nuevaCantidadStr = round($nuevoNum, 2) . ($unidadCat ? ' ' . $unidadCat : '');

            $stmtUpd = $conexion->prepare("UPDATE catalogo SET cantidad = ? WHERE producto = ?");
            $stmtUpd->execute([$nuevaCantidadStr, $nombreAlimento]);
        }

        // 2. Registrar la salida usando obligatoriamente la fecha del servidor
        $stmtSalida = $conexion->prepare("INSERT INTO reportes_salida_menu (Producto, Cantidad, Fecha, Hora, Destino, Responsable) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtSalida->execute([$nombreAlimento, $cantidadTexto, $fecha, $hora, $destino, $responsable]);
    }

    $conexion->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($conexion) && $conexion->inTransaction()) {
        $conexion->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>