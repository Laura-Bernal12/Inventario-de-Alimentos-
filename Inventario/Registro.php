 <?php
// Incluimos tu archivo de conexión (que utiliza PDO según la rúbrica)
include 'conexion.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Recoger y limpiar los datos del formulario
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol = trim($_POST['rol'] ?? '');

        // Validar que no vayan vacíos
        if (empty($nombre) || empty($email) || empty($password) || empty($rol)) {
            die("<h3 style='color:red;'>Error: Todos los campos son obligatorios.</h3><br><a href='javascript:history.back()'>Regresar</a>");
        }

        // Cifrar la contraseña de forma segura
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Preparar la inserción respetando el orden de tus columnas: nombre, email, rol, password
        $stmt = $conexion->prepare("INSERT INTO usuarios (nombre, email, rol, password) VALUES (?, ?, ?, ?)");
        
        // Ejecutar con PDO pasando los valores en un array
        if ($stmt->execute([$nombre, $email, $rol, $password_hash])) {
            echo "<script>
                    alert('✅ ¡Registro exitoso! Ahora puedes iniciar sesión con tu cuenta.');
                    window.location.href = 'index.php?login=ok'; 
                  </script>";
        }

    } catch (Exception $e) {
        // Si hay un error (por ejemplo, correo duplicado), lo mostrará en pantalla
        echo "<h3 style='color:red;'>❌ Error en la base de datos:</h3>";
        echo "<p style='color:red; font-size: 16px;'>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<br><a href='javascript:history.back()'>Regresar al formulario</a>";
    }
} else {
    echo "No se recibieron datos mediante el método POST.";
}
?>