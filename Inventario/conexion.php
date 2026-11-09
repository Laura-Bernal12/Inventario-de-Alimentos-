<?php
$servidor = "localhost";
$base_datos = "inventario_instituto";
$usuario = "root";
$contrasena = "";

try {
    // Conexión centralizada y segura utilizando PDO
    $conexion = new PDO("mysql:host=$servidor;dbname=$base_datos;charset=utf8", $usuario, $contrasena);
    
    // Configurar PDO para que maneje excepciones ante cualquier error
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Opcional: Descomenta la siguiente línea solo para probar que conectó
    // echo "¡Conectado exitosamente!";

} catch (PDOException $e) {
    // Control de errores seguro y profesional
    error_log("Error de conexión: " . $e->getMessage());
    exit("No se pudo conectar a la base de datos del sistema.");
}
?>