
/* =====================================================
   INVENTARIO ESCOLAR - JAVASCRIPT COMPLETO
   Registro, inicio de sesión, selección de roles
   y ventana informativa de permisos.
===================================================== */


/* =====================================================
   1. MOSTRAR / OCULTAR CONTRASEÑA
===================================================== */

function togglePasswordVisibility(inputId, btnElement) {
  const input = document.getElementById(inputId);
  if (!input) return;

  input.type = input.type === "password" ? "text" : "password";

  if (btnElement) {
    btnElement.textContent =
      input.type === "password" ? "👁️" : "🙈";
  }
}


/* =====================================================
   2. OBTENER CUENTAS REGISTRADAS
===================================================== */

function getCuentasRegistradas() {
  try {
    const cuentas = JSON.parse(
      localStorage.getItem("cuentasInventario") || "[]"
    );

    return Array.isArray(cuentas) ? cuentas : [];
  } catch (error) {
    console.error("Error al leer las cuentas:", error);
    return [];
  }
}


/* =====================================================
   3. GUARDAR UNA NUEVA CUENTA
===================================================== */

function guardarCuenta(nuevaCuenta) {
  const cuentas = getCuentasRegistradas();

  cuentas.push(nuevaCuenta);

  localStorage.setItem(
    "cuentasInventario",
    JSON.stringify(cuentas)
  );
}


/* =====================================================
   4. INFORMACIÓN DE LOS ROLES
===================================================== */

function obtenerPermisosRol(rol) {
  const permisos = {
    "Administrador": {
      icono: "🛡️",
      puede: [
        "Consultar el panel de control y los reportes.",
        "Registrar, modificar y eliminar productos.",
        "Gestionar entradas, salidas y existencias.",
        "Crear, editar y administrar cuentas de usuarios.",
        "Configurar y supervisar el sistema."
      ],
      noPuede: [
        "Acceder a funciones ajenas al sistema autorizado.",
        "Compartir contraseñas o utilizar cuentas de otras personas."
      ]
    },

    "Directora": {
      icono: "👩‍💼",
      puede: [
        "Consultar el estado general del inventario.",
        "Revisar productos, existencias y alertas.",
        "Consultar los reportes de entradas y salidas.",
        "Supervisar la información del inventario.",
        "Crear nuevas cuentas de usuarios.",
        "Editar los datos de las cuentas.",
        "Cambiar roles y administrar cuentas.",
        "Supervisar las actividades del sistema."
      ],
      noPuede: [
        "Compartir contraseñas o utilizar cuentas de otras personas.",
        "Realizar acciones ajenas a sus responsabilidades institucionales."
      ]
    },

    "Secretaria": {
      icono: "📋",
      puede: [
        "Consultar la información del inventario.",
        "Revisar existencias y reportes disponibles.",
        "Consultar los registros de entradas y salidas."
      ],
      noPuede: [
        "Administrar cuentas sin autorización.",
        "Eliminar productos o registros sin permiso."
      ]
    },

    "Maestro/a": {
      icono: "🎓",
      puede: [
        "Consultar los productos disponibles.",
        "Revisar las existencias de alimentos.",
        "Consultar la información que tenga habilitada."
      ],
      noPuede: [
        "Modificar las cantidades del inventario sin permiso.",
        "Eliminar productos o administrar cuentas."
      ]
    },

    "Encargado de inventario": {
      icono: "📦",
      puede: [
        "Consultar los productos y sus existencias.",
        "Registrar entradas y salidas de alimentos.",
        "Actualizar cantidades y datos autorizados.",
        "Revisar alertas de stock y vencimiento."
      ],
      noPuede: [
        "Administrar cuentas sin autorización.",
        "Cambiar configuraciones reservadas al administrador."
      ]
    },

    "Consulta": {
      icono: "👁️",
      puede: [
        "Ver los productos registrados.",
        "Consultar las existencias de alimentos.",
        "Revisar los reportes habilitados."
      ],
      noPuede: [
        "Registrar entradas o salidas.",
        "Modificar o eliminar productos.",
        "Administrar cuentas o cambiar configuraciones."
      ]
    }
  };

  return permisos[rol] || permisos["Consulta"];
}


/* =====================================================
   5. SELECTOR VISUAL DE ROLES
===================================================== */

function abrirSelectorRoles() {
  if (document.getElementById("modalSelectorRoles")) return;

  const campoRol = document.getElementById("regRol");

  if (!campoRol) {
    alert("No se encontró el campo para seleccionar el rol.");
    return;
  }

  const roles = [
    {
      nombre: "Directora",
      icono: "♙",
      color: "#2563eb",
      fondo: "#e8f0fe"
    },
    {
      nombre: "Maestro/a",
      icono: "♧",
      color: "#b45309",
      fondo: "#ffedd5"
    },
    {
      nombre: "Secretaria",
      icono: "♙",
      color: "#9333ea",
      fondo: "#f3e0fa"
    },
    {
      nombre: "Consulta",
      icono: "◎",
      color: "#15803d",
      fondo: "#dcfce7"
    },
    {
      nombre: "Administrador",
      icono: "♜",
      color: "#1d4ed8",
      fondo: "#dbeafe"
    },
    {
      nombre: "Encargado de inventario",
      icono: "▣",
      color: "#0f766e",
      fondo: "#ccfbf1"
    }
  ];

  const modal = document.createElement("div");
  modal.id = "modalSelectorRoles";

  modal.style.cssText = `
    position: fixed;
    inset: 0;
    z-index: 100000;
    background: rgba(15, 23, 42, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
    font-family: inherit;
  `;

  const tarjeta = document.createElement("div");

  tarjeta.style.cssText = `
    width: 100%;
    max-width: 615px;
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 14px;
    box-sizing: border-box;
    box-shadow: 0 15px 40px rgba(0,0,0,.15);
  `;

  const titulo = document.createElement("h3");
  titulo.textContent = "Selecciona tu rol";

  titulo.style.cssText = `
    text-align: center;
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    margin: 4px 0 16px;
  `;

  const cuadrilla = document.createElement("div");

  cuadrilla.style.cssText = `
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  `;

  roles.forEach(rol => {
    const boton = document.createElement("button");
    boton.type = "button";

    const seleccionado = campoRol.value === rol.nombre;

    boton.style.cssText = `
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 92px;
      padding: 10px;
      border: 1px solid ${seleccionado ? rol.color : "#d1d5db"};
      border-radius: 10px;
      background: ${seleccionado ? "#e8f0fe" : "#f9fafb"};
      color: #111827;
      font-family: inherit;
      font-size: 13px;
      cursor: pointer;
      box-sizing: border-box;
      transition: background .15s, border-color .15s;
    `;

    const circulo = document.createElement("span");
    circulo.textContent = rol.icono;

    circulo.style.cssText = `
      display: flex;
      align-items: center;
      justify-content: center;
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: ${rol.fondo};
      color: ${rol.color};
      font-size: 25px;
      font-weight: 600;
      flex-shrink: 0;
    `;

    const nombre = document.createElement("span");
    nombre.textContent = rol.nombre;

    nombre.style.cssText = `
      text-align: center;
      overflow-wrap: anywhere;
    `;

    boton.append(circulo, nombre);

    boton.addEventListener("mouseenter", () => {
      boton.style.borderColor = rol.color;
    });

    boton.addEventListener("mouseleave", () => {
      boton.style.borderColor =
        campoRol.value === rol.nombre ? rol.color : "#d1d5db";
    });

    boton.addEventListener("click", () => {
      campoRol.value = rol.nombre;

      campoRol.dispatchEvent(
        new Event("change", { bubbles: true })
      );

      modal.remove();
    });

    cuadrilla.appendChild(boton);
  });

  const pie = document.createElement("p");

  pie.textContent = campoRol.value
    ? "Rol seleccionado: " + campoRol.value
    : "Elige el rol que corresponde a tu cuenta.";

  pie.style.cssText = `
    color: #6b7280;
    font-size: 12px;
    line-height: 1.5;
    margin: 14px 0 0;
  `;

  tarjeta.append(titulo, cuadrilla, pie);
  modal.appendChild(tarjeta);

  modal.addEventListener("click", evento => {
    if (evento.target === modal) {
      modal.remove();
    }
  });

  function cerrarConEscape(evento) {
    if (evento.key === "Escape") {
      modal.remove();
      document.removeEventListener("keydown", cerrarConEscape);
    }
  }

  document.addEventListener("keydown", cerrarConEscape);

  document.body.appendChild(modal);
}


/* =====================================================
   6. ACTIVAR SELECTOR VISUAL DE ROLES
===================================================== */

function configurarSelectorRoles() {
  const campoRol = document.getElementById("regRol");

  if (!campoRol || campoRol.dataset.selectorConfigurado === "true") {
    return;
  }

  campoRol.dataset.selectorConfigurado = "true";

  campoRol.addEventListener("mousedown", function (evento) {
    evento.preventDefault();
    abrirSelectorRoles();
  });

  campoRol.addEventListener("keydown", function (evento) {
    if (
      evento.key === "Enter" ||
      evento.key === " " ||
      evento.key === "ArrowDown"
    ) {
      evento.preventDefault();
      abrirSelectorRoles();
    }
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", configurarSelectorRoles);
} else {
  configurarSelectorRoles();
}


/* =====================================================
   7. LISTAS DE PERMISOS DE LA VENTANA DE ÉXITO
===================================================== */

function crearListaPermisos(
  tituloTexto,
  elementos,
  colorFondo,
  colorTitulo,
  simbolo,
  colorSimbolo
) {
  const seccion = document.createElement("section");

  seccion.style.cssText = `
    background: ${colorFondo};
    border-radius: 12px;
    padding: 15px;
    margin-bottom: 14px;
    box-sizing: border-box;
  `;

  const titulo = document.createElement("h3");
  titulo.textContent = tituloTexto;

  titulo.style.cssText = `
    color: ${colorTitulo};
    font-size: 16px;
    line-height: 1.4;
    margin: 0 0 13px;
  `;

  const lista = document.createElement("ul");

  lista.style.cssText = `
    list-style: none;
    padding: 0;
    margin: 0;
  `;

  elementos.forEach(texto => {
    const elemento = document.createElement("li");

    elemento.style.cssText = `
      display: flex;
      gap: 9px;
      align-items: flex-start;
      margin-bottom: 10px;
      color: #374151;
      font-size: 14px;
      line-height: 1.5;
      overflow-wrap: anywhere;
    `;

    const marca = document.createElement("span");
    marca.textContent = simbolo;

    marca.style.cssText = `
      color: ${colorSimbolo};
      font-weight: bold;
      flex-shrink: 0;
    `;

    const contenido = document.createElement("span");
    contenido.textContent = texto;

    elemento.append(marca, contenido);
    lista.appendChild(elemento);
  });

  seccion.append(titulo, lista);

  return seccion;
}


/* =====================================================
   8. VENTANA DE CUENTA CREADA CON ÉXITO
===================================================== */

function mostrarVentanaPermisos(nombre, rol) {
  const permisos = obtenerPermisosRol(rol);

  document.getElementById("ventanaPermisosCuenta")?.remove();

  const ventana = document.createElement("div");
  ventana.id = "ventanaPermisosCuenta";

  Object.assign(ventana.style, {
    position: "fixed",
    inset: "0",
    zIndex: "99999",
    background: "rgba(15, 23, 42, 0.65)",
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
    padding: "16px",
    fontFamily: "Arial, sans-serif",
    boxSizing: "border-box",
    overflowY: "auto"
  });

  const tarjeta = document.createElement("div");

  Object.assign(tarjeta.style, {
    width: "100%",
    maxWidth: "440px",
    maxHeight: "calc(100vh - 32px)",
    overflowY: "auto",
    background: "#ffffff",
    borderRadius: "20px",
    padding: "26px",
    boxShadow: "0 20px 50px rgba(0, 0, 0, 0.25)",
    boxSizing: "border-box",
    flexShrink: "0",
    margin: "auto"
  });

  const encabezado = document.createElement("div");

  encabezado.style.cssText = `
    text-align: center;
    margin-bottom: 20px;
    padding-top: 4px;
  `;

  const icono = document.createElement("div");
  icono.textContent = permisos.icono;

  icono.style.cssText = `
    width: 76px;
    height: 76px;
    margin: 0 auto 12px;
    border-radius: 50%;
    background: #e8f0fe;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 35px;
  `;

  const titulo = document.createElement("h2");
  titulo.textContent = "¡Cuenta creada con éxito!";

  titulo.style.cssText = `
    color: #166534;
    font-size: 23px;
    line-height: 1.35;
    margin: 0 0 8px;
    overflow-wrap: anywhere;
  `;

  const bienvenida = document.createElement("p");
  bienvenida.textContent = "Bienvenido/a, " + nombre;

  bienvenida.style.cssText = `
    color: #4b5563;
    font-size: 14px;
    line-height: 1.5;
    margin: 0;
    overflow-wrap: anywhere;
  `;

  const etiquetaRol = document.createElement("div");
  etiquetaRol.textContent = "Rol: " + rol;

  etiquetaRol.style.cssText = `
    display: inline-block;
    margin-top: 12px;
    padding: 7px 14px;
    border-radius: 20px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 14px;
    font-weight: bold;
    max-width: 100%;
    box-sizing: border-box;
    overflow-wrap: anywhere;
  `;

  encabezado.append(icono, titulo, bienvenida, etiquetaRol);

  const listaPuede = crearListaPermisos(
    "✓ ¿Qué puedes hacer?",
    permisos.puede,
    "#f0fdf4",
    "#166534",
    "✓",
    "#16a34a"
  );

  const listaNoPuede = crearListaPermisos(
    "🔒 ¿Qué no puedes hacer?",
    permisos.noPuede,
    "#fef2f2",
    "#991b1b",
    "✕",
    "#dc2626"
  );

  const nota = document.createElement("p");

  nota.textContent =
    "Estos permisos son informativos. Las funciones disponibles dependen de la configuración real del sistema.";

  nota.style.cssText = `
    color: #6b7280;
    font-size: 12px;
    line-height: 1.5;
    text-align: center;
    margin: 0 0 18px;
  `;

  const boton = document.createElement("button");

  boton.type = "button";
  boton.textContent = "Entendido, continuar";

  boton.style.cssText = `
    display: block;
    width: 100%;
    padding: 14px;
    border: 0;
    border-radius: 10px;
    background: #2563eb;
    color: #ffffff;
    font-size: 15px;
    line-height: 1.4;
    font-weight: bold;
    cursor: pointer;
    box-sizing: border-box;
  `;

  boton.addEventListener("mouseenter", function () {
    boton.style.background = "#1d4ed8";
  });

  boton.addEventListener("mouseleave", function () {
    boton.style.background = "#2563eb";
  });

  boton.addEventListener("click", function () {
    ventana.remove();

    navigateTo("loginScreen");

    const correo = window.correoCuentaCreada || "";
    const campoCorreo = document.getElementById("loginEmail");
    const campoPassword = document.getElementById("loginPassword");

    if (campoCorreo && correo) {
      campoCorreo.value = correo;
    }

    if (campoPassword) {
      campoPassword.value = "";
    }

    window.correoCuentaCreada = "";
  });

  tarjeta.append(
    encabezado,
    listaPuede,
    listaNoPuede,
    nota,
    boton
  );

  ventana.appendChild(tarjeta);
  document.body.appendChild(ventana);
}

function mostrarMensajeExito(nombre, rol) {
  mostrarVentanaPermisos(nombre, rol);
}


/* =====================================================
   9. CAMBIAR DE PANTALLA
===================================================== */

function navigateTo(targetScreenId) {
  const pantallas = document.querySelectorAll(".screen");

  pantallas.forEach(pantalla => {
    pantalla.classList.remove("active");
  });

  const destino = document.getElementById(targetScreenId);

  if (destino) {
    destino.classList.add("active");
  } else {
    console.error("No se encontró la pantalla:", targetScreenId);
  }
}

function irAIniciarSesion() {
  navigateTo("loginScreen");
}

function irARegistrarse() {
  navigateTo("registerScreen");
}


/* =====================================================
   10. INICIAR SESIÓN
===================================================== */

function handleLogin(event) {
  event.preventDefault();

  const campoEmail = document.getElementById("loginEmail");
  const campoPassword = document.getElementById("loginPassword");

  if (!campoEmail || !campoPassword) {
    alert("No se encontraron los campos de inicio de sesión.");
    return;
  }

  const email = campoEmail.value.trim().toLowerCase();
  const password = campoPassword.value;

  if (!email || !password) {
    alert("Completa el correo electrónico y la contraseña.");
    return;
  }

  const cuentas = getCuentasRegistradas();

  const usuario = cuentas.find(cuenta =>
    cuenta.correo &&
    cuenta.correo.toLowerCase() === email
  );

  if (!usuario) {
    alert("La cuenta no existe. Primero debes registrarte.");
    return;
  }

  if (usuario.password !== password) {
    alert("La contraseña ingresada es incorrecta.");
    return;
  }

  localStorage.setItem(
    "cuentaActivaInventario",
    usuario.id
  );

  alert(
    `Sesión iniciada correctamente. Bienvenido/a, ${usuario.nombre} (${usuario.rol}).`
  );

  window.location.href = "alimentos.html";
}


/* =====================================================
   11. REGISTRAR UNA NUEVA CUENTA
===================================================== */

function handleRegister(event) {
  event.preventDefault();

  const campoNombre = document.getElementById("regName");
  const campoEmail = document.getElementById("regEmail");
  const campoRol = document.getElementById("regRol");
  const campoPassword = document.getElementById("regPassword");

  if (!campoNombre || !campoEmail || !campoRol || !campoPassword) {
    alert("No se encontraron todos los campos del registro.");
    return;
  }

  const nombre = campoNombre.value.trim();
  const email = campoEmail.value.trim().toLowerCase();
  const rol = campoRol.value;
  const password = campoPassword.value;

  const rolesPermitidos = [
    "Administrador",
    "Directora",
    "Secretaria",
    "Maestro/a",
    "Encargado de inventario",
    "Consulta"
  ];

  if (nombre.length < 2) {
    alert("Escribe tu nombre completo.");
    return;
  }

  const formatoCorreo = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  if (!formatoCorreo.test(email)) {
    alert("Ingresa un correo electrónico válido.");
    return;
  }

  if (!rolesPermitidos.includes(rol)) {
    alert("Selecciona un rol válido para tu cuenta.");
    return;
  }

  if (password.length < 6) {
    alert("La contraseña debe tener al menos 6 caracteres.");
    return;
  }

  const cuentas = getCuentasRegistradas();

  const yaExiste = cuentas.some(cuenta =>
    cuenta.correo &&
    cuenta.correo.toLowerCase() === email
  );

  if (yaExiste) {
    alert("Este correo ya está registrado. Puedes iniciar sesión.");
    navigateTo("loginScreen");
    return;
  }

  const nuevaCuenta = {
    id: Date.now().toString(),
    nombre: nombre,
    correo: email,
    password: password,
    rol: rol,
    foto: ""
  };

  try {
    guardarCuenta(nuevaCuenta);

    window.correoCuentaCreada = email;

    const formulario = document.getElementById("registerForm");

    if (formulario) {
      formulario.reset();
    }

    mostrarMensajeExito(nombre, rol);

  } catch (error) {
    console.error("Error al registrar la cuenta:", error);

    alert(
      "Ocurrió un error al guardar la cuenta. Inténtalo nuevamente."
    );
  }
}


/* =====================================================
   12. RECUPERAR CONTRASEÑA
===================================================== */

function forgotPassword(event) {
  event.preventDefault();

  const email = prompt("Ingresa tu correo institucional:");

  if (!email || !email.trim()) {
    return;
  }

  const correo = email.trim().toLowerCase();

  const cuenta = getCuentasRegistradas().find(usuario =>
    usuario.correo &&
    usuario.correo.toLowerCase() === correo
  );

  if (!cuenta) {
    alert("No se encontró una cuenta con ese correo.");
    return;
  }

  alert(
    "La recuperación automática de contraseña todavía no está configurada. Contacta al administrador del sistema."
  );
}
