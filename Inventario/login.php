
<?php
session_start();
include 'conexion.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo "<script>alert('❌ Todos los campos son obligatorios.'); window.location.href='index.php';</script>";
        exit();
    }

    $stmt = $conexion->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($password, $usuario['password'])) {
        // Guardamos las variables de sesión estrictas
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['nombre'] = $usuario['nombre'];
        $_SESSION['rol'] = $usuario['rol'];

        // Redirigimos al panel de control
        header("Location: alimentos.php");
        exit();
    } else {
        echo "<script>alert('❌ Correo o contraseña incorrectos.'); window.location.href='index.php';</script>";
        exit();
    }
}
?>