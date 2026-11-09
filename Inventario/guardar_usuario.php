<?php
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // 1. Leer los datos enviados por JSON desde el formulario de registro
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || empty($data['nombre']) || empty($data['email']) || empty($data['password'])) {
        echo json_encode(['success' => false, 'error' => 'Faltan datos obligatorios en el formulario']);
        exit;
    }

    $nombre = $data['nombre'];
    $email = $data['email'];
    $passwordPlana = $data['password'];
    $rol = $data['rol'] ?? 'Usuario';

    // 2. Encriptar la contraseña de forma segura
    $passwordHash = password_hash($passwordPlana, PASSWORD_DEFAULT);

    // 3. Conexión a la base de datos con PDO
    $conexion = new PDO('mysql:host=localhost;dbname=inventario_instituto;charset=utf8', 'root', '');
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 4. Verificar si el email ya existe en la tabla
    $stmtVerificar = $conexion->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmtVerificar->execute([$email]);
    
    if ($stmtVerificar->rowCount() > 0) {
        echo json_encode(['success' => false, 'error' => 'Este correo electrónico ya está registrado']);
        exit;
    }

    // 5. Insertar el usuario usando los nombres exactos de tus columnas (nombre, email, rol, password)
    $stmt = $conexion->prepare("INSERT INTO usuarios (nombre, email, rol, password) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nombre, $email, $rol, $passwordHash]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>