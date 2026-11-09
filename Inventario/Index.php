<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Acceso Inventario - INPBF</title>
  <link rel="stylesheet" href="Estilos.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

  <main class="app-viewport">
    <div class="mobile-card">

      <!-- PANTALLA 1: BIENVENIDA -->
      <section id="welcomeScreen" class="screen active">
        <div class="illustration-area">
          <div class="network-dots-bg"></div>
          <div class="logo-wrapper">
            <img src="img/logo-instituto.jpg" alt="Logo Instituto Nacional" class="app-logo">
          </div>
        </div>

        <div class="screen-content flex-grow">
          <h2 class="institution-title">
            Instituto Nacional<br>
            <small>Prof. Bertha Fidelia Cañas</small>
          </h2>

          <br>

          <h1 class="headline">Inventario sobre Alimentos Escolares</h1>
          <p class="subline">Este es una aplicación (por el momento un sistema) en el cual se obtendra un control sobre los alimentos escolares demostrando sus entradas, sus salidas y la cantidad que se tiene almacenada o guardada.</p>

          <!-- Botones posicionados al fondo -->
          <div class="split-button-box">
            <button type="button" class="btn-split" onclick="irAIniciarSesion()">Iniciar Sesión</button>
            <button type="button" class="btn-split highlight" onclick="irARegistrarse()">Registrarse</button>
          </div>
        </div>
      </section>

      <!-- PANTALLA 2: INICIAR SESIÓN -->
      <section id="loginScreen" class="screen">
        <header class="screen-header">
          <button class="btn-back" onclick="navigateTo('welcomeScreen')" title="Volver">←</button>
        </header>

        <div class="screen-content flex-grow">
          <h2 class="institution-title small-title">
            Instituto Nacional<br>
            <small>Prof. Bertha Fidelia Cañas</small>
          </h2>

          <h3 class="auth-title">¡Hola de nuevo!</h3>
          <p class="auth-subtitle">Ingresa a tu cuenta institucional</p>

          <form id="loginForm" class="auth-form" action="login.php" method="POST">
            <div class="form-field">
              <label for="loginEmail">Correo Electrónico</label>
              <input type="email" id="loginEmail" name="email" placeholder="usuario@instituto.edu.sv" required>
            </div>

            <div class="form-field">
              <label for="loginPassword">Contraseña</label>
              <div class="password-wrapper">
                <input type="password" id="loginPassword" name="password" placeholder="••••••••" required>
                <button type="button" class="btn-toggle-password" onclick="togglePasswordVisibility('loginPassword', this)" aria-label="Mostrar/Ocultar contraseña">👁️</button>
              </div>
            </div>

            <div class="form-actions">
              <a href="#" class="forgot-link" onclick="forgotPassword(event)">¿Olvidaste tu contraseña?</a>
            </div>

            <button type="submit" class="btn-primary">Iniciar Sesión</button>
          </form>

          <footer class="screen-footer">
            <p>¿No tienes una cuenta? <a href="#" onclick="irARegistrarse()">Registrarse</a></p>
            <a href="#" class="terms-link">Términos</a>
          </footer>
        </div>
      </section>

      <!-- PANTALLA 3: REGISTRARSE -->
      <section id="registerScreen" class="screen">
        <header class="screen-header">
          <button class="btn-back" onclick="navigateTo('welcomeScreen')" title="Volver">←</button>
        </header>

        <div class="screen-content flex-grow">
          <h2 class="institution-title small-title">
            Instituto Nacional<br>
            <small>Prof. Bertha Fidelia Cañas</small>
          </h2>

          <h3 class="auth-title">Crear Cuenta</h3>
          <p class="auth-subtitle">Únete al sistema de inventario</p>

          <form id="registerForm" class="auth-form" action="Registro.php" method="POST">
            <div class="form-field">
              <label for="regName">Nombre Completo</label>
              <input type="text" id="regName" name="nombre" placeholder="Nombre completo" required>
            </div>

            <div class="form-field">
              <label for="regEmail">Correo Electrónico</label>
              <input type="email" id="regEmail" name="email" placeholder="usuario@instituto.edu.sv" required>
            </div>

            <div class="form-field">
              <label for="regRol">Rol en el Sistema</label>
              <select id="regRol" name="rol" required>
                <option value="" selected disabled>Selecciona un rol</option>
                <option value="Administrador">Administrador</option>
                <option value="Directora">Directora</option>
                <option value="Secretaria">Secretaria</option>
                <option value="Maestro/a">Maestro/a</option>
                <option value="Encargado de inventario">Encargado de inventario</option>
                <option value="Consulta">Consulta</option>
              </select>
            </div>

            <div class="form-field">
              <label for="regPassword">Contraseña</label>
              <div class="password-wrapper">
                <input type="password" id="regPassword" name="password" placeholder="Crea una contraseña" required minlength="6">
                <button type="button" class="btn-toggle-password" onclick="togglePasswordVisibility('regPassword', this)" aria-label="Mostrar/Ocultar contraseña">👁️</button>
              </div>
            </div>

            <button type="submit" class="btn-primary">Registrarse</button>
          </form>

          <footer class="screen-footer">
            <p>¿Ya tienes una cuenta? <a href="#" onclick="irAIniciarSesion()">Iniciar Sesión</a></p>
            <a href="#" class="terms-link">Términos</a>
          </footer>
        </div>
      </section>

    </div>
  </main>

  <script src="Java.js"></script>



  <script>
  // Detectar si venimos de un registro exitoso para mostrar el login de inmediato
  window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('login') === 'ok') {
      // Función que ya usa tu diseño para ir a la pantalla de inicio de sesión
      if (typeof irAIniciarSesion === 'function') {
        irAIniciarSesion();
      } else if (typeof navigateTo === 'function') {
        navigateTo('loginScreen');
      }
    }
  });
</script>
</body>
</html>