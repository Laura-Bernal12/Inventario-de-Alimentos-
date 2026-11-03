"use strict";

/* ==========================================================
   PARTE 1: FUNCIONES DEL INVENTARIO (suman, restan, convierten unidades)
   ========================================================== */

/* =========================================================
   inventario.js  -  FUENTE ÚNICA DEL INVENTARIO
   Funciones compartidas: suman, restan y convierten unidades.
   ========================================================= */

const INV_CLAVE = "productosInventario";               // clave principal
const INV_ESPEJOS = ["productos", "inventarioEscolar"]; // se mantienen iguales
const INV_SALIDAS = "salidasTableData";                 // tabla de index1.html
const INV_ENTRADAS = "entradasEscolar";                // tabla de indext.html
const INV_MOVS = "movimientosInventario";               // entradas + salidas (panel)

/* ---------- utilidades ---------- */
function invNorm(t) {
  return String(t ?? "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().toLowerCase();
}
function invNumero(v) {
  if (typeof v === "number") return v;
  const m = String(v ?? "").replace(",", ".").match(/\d+(?:\.\d+)?/);
  return m ? Number(m[0]) : NaN;
}
function invTextoUnidad(v) {
  return String(v ?? "").replace(/^[\d.,\s-]+/, "").trim();
}
function invRedondear(n, d = 3) {
  const f = Math.pow(10, d);
  return Math.round(n * f) / f;
}
function invFecha(d = new Date()) {
  const m = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  return String(d.getDate()).padStart(2, "0") + " " + m[d.getMonth()] + " " + d.getFullYear();
}
function invHora(d = new Date()) {
  return String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
}

function invFechaISO(d = new Date()) {
  return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
}
// Nombre de la cuenta que tiene la sesión activa (incluye cuentas nuevas)
function invResponsable() {
  try {
    const cuentas = JSON.parse(localStorage.getItem("cuentasInventario") || "[]") || [];
    const activa = localStorage.getItem("cuentaActivaInventario");
    const c = cuentas.find(x => x.id === activa) || cuentas[0];
    if (c && c.nombre) return c.nombre;
  } catch (e) { /* se usa el valor por defecto */ }
  return "Admin";
}
// Agrega una fila a la tabla de Entradas (indext.html)
function invRegistrarEntradaTabla(d) {
  let lista = [];
  try { lista = JSON.parse(localStorage.getItem(INV_ENTRADAS) || "[]") || []; } catch (e) { lista = []; }
  const unidad = d.unidad ? String(d.unidad).trim() : "";
  const num = invRedondear(Number(d.cantidad), 3);
  lista.unshift({
    id: "entrada-" + Date.now() + "-" + Math.floor(Math.random() * 1000),
    producto: d.nombre,
    categoria: d.categoria || "",
    cantidad: (num + " " + unidad).trim(),
    cantidadNumero: num,
    fecha: invFechaISO(),
    hora: invHora(),
    proveedor: d.proveedor || "Ministerio de Educación",
    responsable: invResponsable(),
    ubicacion: d.ubicacion || "",
    vencimiento: d.vencimiento || "",
    inventarioAplicado: true
  });
  localStorage.setItem(INV_ENTRADAS, JSON.stringify(lista));
}

/* ---------- leer / guardar inventario ---------- */
function invLeer() {
  for (const k of [INV_CLAVE, ...INV_ESPEJOS]) {
    try {
      const v = JSON.parse(localStorage.getItem(k) || "null");
      if (Array.isArray(v)) return v;
    } catch (e) { /* seguir con la siguiente clave */ }
  }
  return [];
}
function invGuardar(lista) {
  const texto = JSON.stringify(lista);
  [INV_CLAVE, ...INV_ESPEJOS].forEach(k => localStorage.setItem(k, texto));
}
// Para el catálogo: si no existe nada todavía, guarda los productos de ejemplo.
function invLeerOInicializar(porDefecto) {
  const existe = [INV_CLAVE, ...INV_ESPEJOS].some(k => {
    try { return Array.isArray(JSON.parse(localStorage.getItem(k) || "null")); }
    catch (e) { return false; }
  });
  if (existe) return invLeer();
  invGuardar(porDefecto);
  return porDefecto;
}

/* ---------- unidades y conversiones (lb <-> kg, L <-> ml...) ---------- */
const INV_UNIDADES = {
  lb: { g: ["lb", "lbs", "libra", "libras"], t: "peso", f: 453.592 },
  kg: { g: ["kg", "kgs", "kilo", "kilos", "kilogramo", "kilogramos"], t: "peso", f: 1000 },
  g:  { g: ["g", "gr", "gramo", "gramos"], t: "peso", f: 1 },
  l:  { g: ["l", "lt", "lts", "litro", "litros"], t: "vol", f: 1000 },
  ml: { g: ["ml", "mililitro", "mililitros"], t: "vol", f: 1 },
  un: { g: ["un", "pza", "pzas", "pieza", "piezas", "unidad", "unidades", "ud", "uds", "porcion", "porciones"], t: "unidad", f: 1 }
};
function invUnidad(u) {
  const x = invNorm(u).replace(/\./g, "");
  for (const k in INV_UNIDADES) if (INV_UNIDADES[k].g.includes(x)) return k;
  return x;
}
function invNombreUnidad(u) {
  const k = invUnidad(u);
  const bonitos = { lb: "Libras", kg: "Kg", g: "Gramos", l: "Litros", ml: "ml", un: "Unidades" };
  return bonitos[k] || String(u || "").trim() || "Unidades";
}
// Devuelve la cantidad convertida, o null si no se pueden convertir.
function invConvertir(cantidad, de, a) {
  const d = invUnidad(de), t = invUnidad(a);
  if (!d || !t || d === t) return cantidad;
  const A = INV_UNIDADES[d], B = INV_UNIDADES[t];
  if (!A || !B || A.t !== B.t) return null;
  return cantidad * A.f / B.f;
}

/* ---------- buscar producto (exacto, o único parecido: Arroz ~ Arroz Blanco) ---------- */
function invBuscar(lista, nombre) {
  const n = invNorm(nombre);
  if (!n) return null;
  const nom = p => invNorm(p.nombre ?? p.producto ?? p.nombreProducto);
  const exacto = lista.find(p => nom(p) === n);
  if (exacto) return exacto;
  const parecidos = lista.filter(p => {
    const m = nom(p);
    return m && (m.startsWith(n + " ") || n.startsWith(m + " "));
  });
  return parecidos.length === 1 ? parecidos[0] : null;
}
function invCodigo(lista) {
  let max = 0;
  lista.forEach(p => {
    const m = String(p.codigo || "").trim().toUpperCase().match(/^P(\d+)$/);
    if (m && Number(m[1]) > max) max = Number(m[1]);
  });
  return "P" + String(max + 1).padStart(3, "0");
}

/* ---------- movimientos (para "Actividad reciente" del panel) ---------- */
function invMovimientos() {
  try { return JSON.parse(localStorage.getItem(INV_MOVS) || "[]") || []; }
  catch (e) { return []; }
}
function invAnotarMov(tipo, nombre, cantidad, unidad, nota = "") {
  const lista = invMovimientos();
  lista.unshift({ tipo, nombre, cantidad, unidad, nota, fecha: invFecha(), hora: invHora() });
  localStorage.setItem(INV_MOVS, JSON.stringify(lista.slice(0, 500)));
}

/* =========================================================
   NÚCLEO: suma (signo +1) o resta (signo -1) del inventario
   ========================================================= */
function invAplicar(nombre, cantidad, unidad, signo, lista) {
  const propia = !lista;
  lista = lista || invLeer();
  const p = invBuscar(lista, nombre);
  if (!p) return { ok: false, noExiste: true, mensaje: `No encontré “${nombre}” en el inventario.` };

  const n = Number(cantidad);
  if (!(n > 0)) return { ok: false, mensaje: "La cantidad debe ser mayor que cero." };

  const unidadInv = p.unidad || "";
  const enInv = invConvertir(n, unidad || unidadInv, unidadInv);
  if (enInv === null) {
    return { ok: false, mensaje: `La unidad “${unidad}” no se puede convertir a “${unidadInv}” (${nombre}).` };
  }

  const actual = invNumero(p.cantidad) || 0;
  if (signo < 0 && enInv > actual + 1e-9) {
    return {
      ok: false, sinStock: true,
      mensaje: `No alcanza ${p.nombre}: hay ${invRedondear(actual)} ${unidadInv} y se necesitan ${invRedondear(enInv)} ${unidadInv}.`
    };
  }

  const nueva = Math.max(0, invRedondear(actual + signo * enInv));
  p.cantidad = nueva;
  if (propia) invGuardar(lista);
  return { ok: true, producto: p, cantidadAnterior: actual, cantidadNueva: nueva, descontado: invRedondear(enInv), unidad: unidadInv };
}
// Igual, pero recibiendo un texto tipo "5 lb" (lo usa index1.html / Java1.js)
function invAplicarTexto(nombre, texto, signo) {
  return invAplicar(nombre, invNumero(texto), invTextoUnidad(texto), signo);
}

// Si el producto quedó en 0 después de restar, se quita del inventario
// (así desaparece del panel y del catálogo).
function invQuitarSiVacio(nombre) {
  const lista = invLeer();
  const p = invBuscar(lista, nombre);
  if (!p || (invNumero(p.cantidad) || 0) > 0.0005) return false;
  invGuardar(lista.filter(x => x !== p));
  return true;
}

/* =========================================================
   ENTRADA: suma (o crea el producto) y la anota
   ========================================================= */
function invEntrada({ nombre, cantidad, unidad = "", categoria = "", vencimiento = "", ubicacion = "", observacion = "", proveedor = "", tabla = true }) {
  nombre = String(nombre || "").trim();
  cantidad = Number(cantidad);
  if (!nombre || !(cantidad > 0)) return { ok: false, mensaje: "Escribe un nombre y una cantidad mayor que cero." };

  const lista = invLeer();
  let p = invBuscar(lista, nombre);

  if (p) {
    const r = invAplicar(nombre, cantidad, unidad, +1, lista);
    if (!r.ok) return r;
    if (vencimiento) p.vencimiento = vencimiento;
    if (ubicacion) p.ubicacion = ubicacion;
    if (!p.categoria && categoria) p.categoria = categoria;
    if (!p.codigo) p.codigo = invCodigo(lista);
  } else {
    p = {
      codigo: invCodigo(lista),
      nombre,
      categoria,
      cantidad: invRedondear(cantidad),
      unidad: invNombreUnidad(unidad),
      minimo: Math.max(10, Math.round(cantidad * 0.20)),
      ubicacion,
      vencimiento
    };
    lista.push(p);
  }
  invGuardar(lista);

  // Fila en la tabla de Entradas (se omite si la página ya la crea ella misma)
  if (tabla) {
    try {
      invRegistrarEntradaTabla({
        nombre: p.nombre, cantidad, unidad: unidad || p.unidad,
        categoria: p.categoria || categoria, proveedor,
        ubicacion: ubicacion || p.ubicacion, vencimiento: vencimiento || p.vencimiento
      });
    } catch (e) { /* no es crítico */ }
  }

  invAnotarMov("entrada", p.nombre, invRedondear(cantidad, 2), unidad || p.unidad);
  return { ok: true, producto: p, mensaje: "Entrada registrada." };
}

/* =========================================================
   SALIDA: resta del inventario, la pone en la tabla de
   index1.html (salidasTableData) y la anota
   ========================================================= */
function invSalida(nombre, cantidad, unidad, { destino = "Cocina Escolar", responsable = "Admin", motivo = "" } = {}) {
  const c = invRedondear(Number(cantidad), 2);
  const r = invAplicar(nombre, c, unidad, -1);
  if (!r.ok) return r;

  const unidadTxt = unidad || r.unidad;
  let tabla = [];
  try { tabla = JSON.parse(localStorage.getItem(INV_SALIDAS) || "[]") || []; } catch (e) { tabla = []; }
  tabla.push({
    id: Date.now() + Math.random(),
    producto: r.producto.nombre,
    cantidad: `${c} ${unidadTxt}`.trim(),
    fecha: invFecha(),
    hora: invHora(),
    destino,
    responsable,
    inventarioAplicado: true
  });
  localStorage.setItem(INV_SALIDAS, JSON.stringify(tabla));

  invAnotarMov("salida", r.producto.nombre, c, unidadTxt, motivo);
  return r;
}

/* ---------- Menú: validar todo ANTES de descontar ---------- */
// items = [{ nombre, unidad, total }]
function invValidarMenu(items) {
  const copia = JSON.parse(JSON.stringify(invLeer()));
  const faltan = [], problemas = [];
  items.forEach(i => {
    const c = invRedondear(Number(i.total), 2);
    if (!(c > 0)) return;
    const r = invAplicar(i.nombre, c, i.unidad, -1, copia);
    if (!r.ok) (r.noExiste ? faltan : problemas).push(r.noExiste ? i.nombre : r.mensaje);
  });
  return { faltan, problemas };
}
function invAplicarMenu(items, meta) {
  const aplicados = [], omitidos = [];
  items.forEach(i => {
    const r = invSalida(i.nombre, i.total, i.unidad, {
      destino: meta.destino || "Cocina Escolar",
      responsable: meta.responsable || "Admin",
      motivo: "Consumo - " + (meta.menu || "menú")
    });
    (r.ok ? aplicados : omitidos).push(i.nombre);
  });
  return { aplicados, omitidos };
}

/* ==========================================================
   INVENTARIO ESCOLAR
   JavaScript para reportes de entradas
   (todo incluido: no necesita ningún otro archivo)
   ========================================================== */


/* -------------------- CONFIGURACIÓN -------------------- */

const CLAVE_ENTRADAS = "entradasEscolar";
const CLAVE_INVENTARIO = "productosInventario";
const CLAVE_PRODUCTOS = "productos";
const CLAVE_PANEL = "datosPanel";
const CLAVE_CUENTAS = "cuentasInventario";
const CLAVE_CUENTA_ACTIVA = "cuentaActivaInventario";

/* Claves que se respaldan para poder deshacer si algo falla */
const CLAVES_RESPALDO = [
    CLAVE_ENTRADAS,
    CLAVE_INVENTARIO,
    CLAVE_PRODUCTOS,
    "inventarioEscolar",
    "movimientosInventario",
    CLAVE_PANEL
];

const PROVEEDOR_PREDETERMINADO = "Ministerio de Educación";

const COLORES_DONA = [
    "#3b82f6",
    "#f59e0b",
    "#a855f7",
    "#10b981",
    "#ef4444",
    "#06b6d4",
    "#ec4899",
    "#84cc16"
];

let entradas = [];
let inventario = [];
let lineChart = null;
let donutChart = null;

/* Cuentas */
let cuentas = [];
let cuentaEditandoId = null;
let fotoTemporal = "";

/* -------------------- UTILIDADES -------------------- */

function obtenerElemento(id) {
    return document.getElementById(id);
}

function obtenerValor(id) {
    const elemento = obtenerElemento(id);
    return elemento ? elemento.value.trim() : "";
}

function leerLista(clave, valorPredeterminado = []) {
    try {
        const contenido = localStorage.getItem(clave);

        if (contenido === null) {
            return valorPredeterminado;
        }

        const resultado = JSON.parse(contenido);
        return Array.isArray(resultado) ? resultado : valorPredeterminado;
    } catch (error) {
        console.error("Error leyendo " + clave + ":", error);
        return valorPredeterminado;
    }
}

function guardarLista(clave, lista) {
    try {
        localStorage.setItem(clave, JSON.stringify(lista));
        return true;
    } catch (error) {
        console.error("Error guardando " + clave + ":", error);
        return false;
    }
}

function escaparHTML(valor) {
    return String(valor ?? "").replace(/[&<>"']/g, caracter => {
        const caracteres = {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;"
        };

        return caracteres[caracter];
    });
}

function obtenerFechaLocal() {
    const fecha = new Date();
    const anio = fecha.getFullYear();
    const mes = String(fecha.getMonth() + 1).padStart(2, "0");
    const dia = String(fecha.getDate()).padStart(2, "0");

    return `${anio}-${mes}-${dia}`;
}

function obtenerHoraLocal() {
    const fecha = new Date();
    const horas = String(fecha.getHours()).padStart(2, "0");
    const minutos = String(fecha.getMinutes()).padStart(2, "0");

    return `${horas}:${minutos}`;
}

function mostrarMensaje(mensaje) {
    alert(mensaje);
}

/*
 * Convierte "20 libras", "50 L", "30 Kg" o un número en el número.
 */
function convertirNumero(valor) {
    if (typeof valor === "number") {
        return Number.isFinite(valor) ? valor : 0;
    }

    const texto = String(valor ?? "").trim().replace(",", ".");
    const coincidencia = texto.match(/-?\d+(\.\d+)?/);

    if (!coincidencia) {
        return 0;
    }

    const numero = Number(coincidencia[0]);
    return Number.isFinite(numero) ? numero : 0;
}

/*
 * Saca la unidad de un texto como "20 libras" -> "libras".
 */
function obtenerUnidadDeTexto(valor) {
    const texto = String(valor ?? "").trim();
    const unidad = texto.replace(/-?\d+([.,]\d+)?/, "").trim();

    return unidad;
}

function normalizarTexto(valor) {
    return String(valor ?? "").trim().toLowerCase();
}

function tomarRespaldo() {
    const respaldo = {};

    CLAVES_RESPALDO.forEach(clave => {
        respaldo[clave] = localStorage.getItem(clave);
    });

    return respaldo;
}

function restaurarRespaldo(respaldo) {
    Object.keys(respaldo).forEach(clave => restaurarClave(clave, respaldo[clave]));
}

/* -------------------- CUENTAS (las mismas que usa todo el sistema) -------------------- */

/*
 * Lee las cuentas de "cuentasInventario" y la cuenta activa de
 * "cuentaActivaInventario": las mismas claves que usan el Panel,
 * el Catálogo, el Menú y Salidas. Así, cualquier cuenta que se
 * agregue en otra página también se reconoce aquí.
 */
function cargarCuentas() {
    cuentas = leerLista(CLAVE_CUENTAS, []);

    if (cuentas.length === 0) {
        const antigua = leerObjeto("administradorInventario", null);

        const cuentaInicial = {
            id: Date.now().toString(),
            nombre: (antigua && antigua.nombre) || "Admin",
            correo: (antigua && antigua.correo) || "admin@instituto.com",
            rol: (antigua && antigua.rol) || "Administrador",
            foto: localStorage.getItem("fotoAdministrador") || ""
        };

        cuentas.push(cuentaInicial);
        guardarLista(CLAVE_CUENTAS, cuentas);
        localStorage.setItem(CLAVE_CUENTA_ACTIVA, cuentaInicial.id);
    }

    const activa = localStorage.getItem(CLAVE_CUENTA_ACTIVA);

    if (!activa || !cuentas.some(cuenta => cuenta.id === activa)) {
        localStorage.setItem(CLAVE_CUENTA_ACTIVA, cuentas[0].id);
    }
}

function obtenerCuentaActiva() {
    cargarCuentas();

    const activa = localStorage.getItem(CLAVE_CUENTA_ACTIVA);

    return cuentas.find(cuenta => cuenta.id === activa) || cuentas[0];
}

/*
 * El responsable es siempre la cuenta que está activa.
 */
function obtenerResponsableActual() {
    const cuenta = obtenerCuentaActiva();

    if (cuenta && cuenta.nombre) {
        return cuenta.nombre;
    }

    const visible = obtenerElemento("nombreAdministrador");
    const texto = visible ? visible.textContent.trim() : "";

    return texto || "Admin";
}

/* Muestra en la cabecera los datos de la cuenta activa */
function actualizarPerfil() {
    const cuenta = obtenerCuentaActiva();

    if (!cuenta) {
        return;
    }

    const nombre = obtenerElemento("nombreAdministrador");
    const rol = obtenerElemento("rolAdministrador");
    const menuNombre = obtenerElemento("menuNombre");
    const menuCorreo = obtenerElemento("menuCorreo");
    const foto = obtenerElemento("fotoAdministrador");
    const icono = obtenerElemento("iconoAdministrador");

    if (nombre) nombre.textContent = cuenta.nombre;
    if (rol) rol.textContent = cuenta.rol;
    if (menuNombre) menuNombre.textContent = cuenta.nombre;
    if (menuCorreo) menuCorreo.textContent = cuenta.correo;

    if (foto && icono) {
        if (cuenta.foto) {
            foto.src = cuenta.foto;
            foto.classList.remove("hidden");
            icono.classList.add("hidden");
        } else {
            foto.removeAttribute("src");
            foto.classList.add("hidden");
            icono.classList.remove("hidden");
        }
    }
}

/*
 * Deja el formulario listo: fecha y hora de ahora,
 * proveedor predeterminado y responsable = cuenta actual.
 */
function prepararFormularioEntrada() {
    const responsable = obtenerElemento("inputResponsable");

    if (responsable) {
        responsable.value = obtenerResponsableActual();
        responsable.readOnly = true;
    }

    const fecha = obtenerElemento("inputFecha");

    if (fecha) {
        fecha.value = obtenerFechaLocal();
    }

    const hora = obtenerElemento("inputHora");

    if (hora) {
        hora.value = obtenerHoraLocal();
    }

    const proveedor = obtenerElemento("inputProveedor");

    if (proveedor && !proveedor.value) {
        proveedor.value = PROVEEDOR_PREDETERMINADO;
    }
}

/* -------------------- DATOS INICIALES -------------------- */

function cargarDatosIniciales() {
    cargarEntradas();
    inventario = obtenerInventario();
}

/*
 * Lee las entradas. Los registros viejos que no tenían "id"
 * reciben uno aquí, para que se puedan eliminar.
 */
function cargarEntradas() {
    entradas = leerLista(CLAVE_ENTRADAS, []);

    let huboCambios = false;

    entradas.forEach((registro, indice) => {
        if (!registro.id) {
            registro.id = "entrada-" + Date.now() + "-" + indice;
            huboCambios = true;
        }
    });

    if (huboCambios) {
        guardarLista(CLAVE_ENTRADAS, entradas);
    }
}

function obtenerInventario() {
    let productos = leerLista(CLAVE_INVENTARIO, null);

    if (productos === null || productos.length === 0) {
        const productosAntiguos = leerLista(CLAVE_PRODUCTOS, []);
        productos = productosAntiguos;
    }

    return productos;
}

/* -------------------- INVENTARIO -------------------- */

function obtenerNombreProducto(producto) {
    return producto.nombre || producto.producto || producto.nombreProducto || "";
}

/*
 * Suma la entrada al inventario compartido (inventario.js).
 * Convierte unidades (libras <-> Kg), crea el producto si no existe
 * y lo anota en "Actividad reciente" del panel.
 * La fila de la tabla de Entradas la crea esta página, por eso tabla:false.
 */
function actualizarProductoEnInventario(entrada) {
    const cantidadEntrada = convertirNumero(entrada.cantidad);

    if (cantidadEntrada <= 0) {
        throw new Error("La cantidad debe ser mayor que cero.");
    }

    if (typeof invEntrada !== "function") {
        throw new Error("Falta el archivo inventario.js en la carpeta. Agrégalo antes de script.js.");
    }

    const resultado = invEntrada({
        nombre: entrada.producto,
        cantidad: cantidadEntrada,
        unidad: obtenerUnidadDeTexto(entrada.cantidad),
        categoria: entrada.categoria,
        vencimiento: entrada.vencimiento,
        ubicacion: entrada.ubicacion,
        proveedor: entrada.proveedor,
        observacion: "Proveedor: " + entrada.proveedor,
        tabla: false
    });

    if (!resultado.ok) {
        throw new Error(resultado.mensaje || "No se pudo actualizar el inventario.");
    }

    inventario = obtenerInventario();

    actualizarDatosPanel(inventario, entrada);

    return true;
}

function actualizarDatosPanel(lista, entrada) {
    const datosPanel = leerObjeto(CLAVE_PANEL, {});

    datosPanel.totalProductos = lista.length;
    datosPanel.ultimaActividad = {
        tipo: "entrada",
        producto: entrada.producto,
        cantidad: entrada.cantidad,
        fecha: entrada.fecha,
        hora: entrada.hora
    };

    try {
        localStorage.setItem(CLAVE_PANEL, JSON.stringify(datosPanel));
    } catch (error) {
        console.error("No se pudieron actualizar los datos del panel:", error);
    }
}

function leerObjeto(clave, valorPredeterminado = {}) {
    try {
        const contenido = localStorage.getItem(clave);

        if (!contenido) {
            return valorPredeterminado;
        }

        const resultado = JSON.parse(contenido);

        if (resultado && typeof resultado === "object" && !Array.isArray(resultado)) {
            return resultado;
        }

        return valorPredeterminado;
    } catch (error) {
        return valorPredeterminado;
    }
}

/* -------------------- GUARDAR REGISTRO -------------------- */

function guardarEntrada(evento) {
    if (evento) {
        evento.preventDefault();
    }

    const formulario = obtenerElemento("addProductForm");

    if (!formulario) {
        mostrarMensaje("No se encontró el formulario de entradas.");
        return;
    }

    if (formulario.dataset.guardando === "true") {
        return;
    }

    formulario.dataset.guardando = "true";

    cargarEntradas();

    const entradasAnteriores = [...entradas];
    const respaldo = tomarRespaldo();

    try {
        const nuevoRegistro = {
            id: "entrada-" + Date.now(),
            producto: obtenerValor("inputProducto"),
            categoria: obtenerValor("inputCategoria"),
            cantidad: obtenerValor("inputCantidad"),
            fecha: obtenerValor("inputFecha"),
            hora: obtenerValor("inputHora") || obtenerHoraLocal(),
            proveedor: obtenerValor("inputProveedor"),
            responsable: obtenerResponsableActual(),
            ubicacion: obtenerValor("inputUbicacion"),
            vencimiento: obtenerValor("inputVencimiento")
        };

        const camposObligatorios = [
            ["Producto", nuevoRegistro.producto],
            ["Categoría", nuevoRegistro.categoria],
            ["Cantidad", nuevoRegistro.cantidad],
            ["Fecha de entrada", nuevoRegistro.fecha],
            ["Proveedor", nuevoRegistro.proveedor],
            ["Responsable", nuevoRegistro.responsable],
            ["Ubicación", nuevoRegistro.ubicacion],
            ["Fecha de vencimiento", nuevoRegistro.vencimiento]
        ];

        const campoVacio = camposObligatorios.find(campo =>
            !String(campo[1]).trim()
        );

        if (campoVacio) {
            throw new Error("Completa el campo: " + campoVacio[0] + ".");
        }

        const cantidad = convertirNumero(nuevoRegistro.cantidad);

        if (!Number.isFinite(cantidad) || cantidad <= 0) {
            throw new Error(
                "La cantidad debe incluir un número mayor que cero. Ejemplo: 20 libras."
            );
        }

        /*
         * Se conserva el texto tal como se escribió ("20 libras")
         * para mostrarlo en la tabla, y se guarda el número aparte
         * para los gráficos.
         */
        nuevoRegistro.cantidadNumero = cantidad;

        /* Marca que esta entrada sí se sumó al inventario */
        nuevoRegistro.inventarioAplicado = true;

        if (nuevoRegistro.vencimiento < nuevoRegistro.fecha) {
            throw new Error(
                "La fecha de vencimiento no puede ser anterior a la fecha de entrada."
            );
        }

        /*
         * Primero se actualiza el inventario.
         * Si algo falla, se restauran las copias guardadas.
         */
        actualizarProductoEnInventario(nuevoRegistro);

        entradas.unshift(nuevoRegistro);

        if (!guardarLista(CLAVE_ENTRADAS, entradas)) {
            throw new Error("No se pudo guardar el registro de entrada.");
        }

        const registrosGuardados = leerLista(CLAVE_ENTRADAS, null);

        if (
            !registrosGuardados ||
            !registrosGuardados.some(registro => registro.id === nuevoRegistro.id)
        ) {
            throw new Error("No se pudo verificar el registro guardado.");
        }

        renderizarTabla();
        actualizarGraficos();
        actualizarNotificaciones();

        formulario.reset();

        prepararFormularioEntrada();

        cerrarModalEntrada();

        mostrarMensaje("¡Registro guardado correctamente y el inventario ha sido actualizado!");
    } catch (error) {
        entradas = entradasAnteriores;

        restaurarRespaldo(respaldo);

        inventario = obtenerInventario();

        renderizarTabla();
        actualizarGraficos();
        actualizarNotificaciones();

        console.error("Error al guardar la entrada:", error);
        mostrarMensaje(error.message || "Ocurrió un error al guardar el registro.");
    } finally {
        delete formulario.dataset.guardando;
    }
}

function restaurarClave(clave, contenidoAnterior) {
    try {
        if (contenidoAnterior === null) {
            localStorage.removeItem(clave);
        } else {
            localStorage.setItem(clave, contenidoAnterior);
        }
    } catch (error) {
        console.error("No se pudo restaurar " + clave + ":", error);
    }
}

/* -------------------- TABLA DE ENTRADAS -------------------- */

/*
 * Columnas (7, igual que el encabezado del HTML):
 * Producto | Cantidad | Fecha | Hora | Proveedor | Responsable | Acción
 */
function renderizarTabla() {
    const tabla = obtenerElemento("tablaEntradasBody");

    if (!tabla) {
        return;
    }

    cargarEntradas();

    if (entradas.length === 0) {
        tabla.innerHTML = `
            <tr>
                <td colspan="7" style="text-align:center;color:#64748b;padding:24px;">
                    No hay registros de entradas.
                </td>
            </tr>
        `;
        return;
    }

    tabla.innerHTML = entradas.map(entrada => `
        <tr>
            <td>${escaparHTML(entrada.producto)}</td>
            <td>${escaparHTML(entrada.cantidad)}</td>
            <td>${escaparHTML(entrada.fecha)}</td>
            <td>${escaparHTML(entrada.hora || "")}</td>
            <td>${escaparHTML(entrada.proveedor)}</td>
            <td>${escaparHTML(entrada.responsable)}</td>
            <td>
                <button
                    type="button"
                    class="btn-eliminar"
                    data-id="${escaparHTML(entrada.id)}"
                    title="Eliminar registro"
                    aria-label="Eliminar registro"
                >
                    🗑
                </button>
            </td>
        </tr>
    `).join("");

    /* Si ya había algo escrito en el filtro, se vuelve a aplicar */
    const buscador = obtenerElemento("searchInput");

    if (buscador && buscador.value) {
        buscador.dispatchEvent(new Event("input"));
    }
}

/* -------------------- ELIMINAR REGISTRO -------------------- */

/*
 * Elimina cualquier registro de la tabla, venga de Entradas,
 * del Panel o del Catálogo. Si el registro sí se había sumado
 * al inventario, esa cantidad se resta también.
 */
function eliminarEntrada(id) {
    cargarEntradas();

    const entrada = entradas.find(registro => String(registro.id) === String(id));

    if (!entrada) {
        mostrarMensaje("No se encontró el registro seleccionado.");
        return;
    }

    const existeExacto = typeof invLeer === "function" &&
        invLeer().some(p => invNorm(obtenerNombreProducto(p)) === invNorm(entrada.producto));

    /* Los registros nuevos traen la marca inventarioAplicado. Los viejos no la
       tienen: se descuentan solo si el producto existe con ese mismo nombre. */
    const descuentaInventario = typeof invAplicar === "function" && (
        entrada.inventarioAplicado === true ||
        (entrada.inventarioAplicado === undefined && existeExacto)
    );

    const confirmar = confirm(
        `¿Deseas eliminar el registro de entrada de "${entrada.producto}"?\n\n` +
        (descuentaInventario
            ? `También se descontará ${entrada.cantidad} del inventario. Si el producto queda en 0, se quita del panel y del catálogo.`
            : "Se eliminará solo el registro de la tabla; el inventario no cambia.")
    );

    if (!confirmar) {
        return;
    }

    const entradasAnteriores = [...entradas];
    const respaldo = tomarRespaldo();
    let productoQuitado = false;

    if (descuentaInventario) {
        const cantidad = convertirNumero(entrada.cantidadNumero ?? entrada.cantidad);
        const unidad = obtenerUnidadDeTexto(entrada.cantidad);
        const resultado = invAplicar(entrada.producto, cantidad, unidad, -1);

        if (!resultado.ok) {
            const seguir = confirm(
                "No se pudo descontar del inventario:\n" + resultado.mensaje +
                "\n\n¿Quieres eliminar solo el registro de la tabla?"
            );

            if (!seguir) {
                return;
            }
        } else {
            invAnotarMov(
                "salida",
                entrada.producto,
                cantidad,
                unidad || resultado.unidad,
                "Entrada eliminada"
            );

            productoQuitado = invQuitarSiVacio(entrada.producto);
        }
    }

    entradas = entradas.filter(registro => String(registro.id) !== String(id));

    if (!guardarLista(CLAVE_ENTRADAS, entradas)) {
        entradas = entradasAnteriores;
        restaurarRespaldo(respaldo);
        mostrarMensaje("No se pudo eliminar el registro.");
        return;
    }

    inventario = obtenerInventario();

    renderizarTabla();
    actualizarGraficos();
    actualizarNotificaciones();

    mostrarMensaje(
        productoQuitado
            ? `El registro fue eliminado y "${entrada.producto}" se quitó del inventario, el panel y el catálogo.`
            : "El registro de entrada fue eliminado."
    );
}

/* -------------------- BÚSQUEDA -------------------- */

function configurarBusqueda() {
    const buscador = obtenerElemento("searchInput");

    if (!buscador) {
        return;
    }

    buscador.addEventListener("input", function () {
        const busqueda = normalizarTexto(this.value);
        const filas = document.querySelectorAll("#tablaEntradasBody tr");

        filas.forEach(fila => {
            const textoFila = normalizarTexto(fila.textContent);
            fila.style.display = textoFila.includes(busqueda) ? "" : "none";
        });
    });
}

/* -------------------- MODAL DE ENTRADAS -------------------- */

function abrirModalEntrada() {
    const modal = obtenerElemento("addProductModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("hidden");
    modal.style.display = "flex";
}

function cerrarModalEntrada() {
    const modal = obtenerElemento("addProductModal");

    if (!modal) {
        return;
    }

    modal.classList.add("hidden");
    modal.style.display = "none";
}

/* -------------------- GRÁFICOS -------------------- */

function actualizarGraficos() {
    if (typeof Chart === "undefined") {
        return;
    }

    cargarEntradas();

    actualizarGraficoLineas();
    actualizarGraficoProductos();
}

function obtenerCantidadEntrada(entrada) {
    if (entrada.cantidadNumero !== undefined) {
        return convertirNumero(entrada.cantidadNumero);
    }

    return convertirNumero(entrada.cantidad);
}

/* Línea verde: total recibido por fecha */
function actualizarGraficoLineas() {
    const canvas = obtenerElemento("lineChart");

    if (!canvas) {
        return;
    }

    const cantidadesPorFecha = {};

    entradas.forEach(entrada => {
        const fecha = entrada.fecha || "Sin fecha";
        cantidadesPorFecha[fecha] =
            (cantidadesPorFecha[fecha] || 0) + obtenerCantidadEntrada(entrada);
    });

    const fechas = Object.keys(cantidadesPorFecha).sort();
    const cantidades = fechas.map(fecha => cantidadesPorFecha[fecha]);

    if (lineChart) {
        lineChart.destroy();
    }

    lineChart = new Chart(canvas, {
        type: "line",
        data: {
            labels: fechas,
            datasets: [{
                label: "Cantidad de productos ingresados",
                data: cantidades,
                borderColor: "#10b981",
                backgroundColor: "rgba(16, 185, 129, 0.12)",
                pointBackgroundColor: "#10b981",
                pointBorderColor: "#10b981",
                pointRadius: 6,
                borderWidth: 3,
                tension: 0,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}

/* Dona de colores: total recibido por producto */
function actualizarGraficoProductos() {
    const canvas = obtenerElemento("donutChart");

    if (!canvas) {
        return;
    }

    const cantidadesPorProducto = {};

    entradas.forEach(entrada => {
        const producto = entrada.producto || "Sin nombre";

        cantidadesPorProducto[producto] =
            (cantidadesPorProducto[producto] || 0) +
            obtenerCantidadEntrada(entrada);
    });

    const productos = Object.keys(cantidadesPorProducto);
    const cantidades = productos.map(
        producto => cantidadesPorProducto[producto]
    );

    if (donutChart) {
        donutChart.destroy();
    }

    donutChart = new Chart(canvas, {
        type: "doughnut",
        data: {
            labels: productos,
            datasets: [{
                label: "Productos recibidos",
                data: cantidades,
                backgroundColor: productos.map(
                    (_, i) => COLORES_DONA[i % COLORES_DONA.length]
                ),
                borderColor: "#ffffff",
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "62%",
            plugins: {
                legend: {
                    position: "right",
                    labels: {
                        boxWidth: 40,
                        boxHeight: 10
                    }
                }
            }
        }
    });
}

/* -------------------- NOTIFICACIONES DE VENCIMIENTO -------------------- */

function obtenerProductosPorVencer() {
    const lista = obtenerInventario();
    const hoy = new Date(obtenerFechaLocal() + "T00:00:00");
    const limite = new Date(hoy);

    limite.setDate(limite.getDate() + 7);

    return lista.filter(producto => {
        const fechaTexto =
            producto.vencimiento ||
            producto.fechaVencimiento ||
            producto.fecha_vencimiento ||
            "";

        if (!fechaTexto) {
            return false;
        }

        const fecha = new Date(fechaTexto + "T00:00:00");

        if (Number.isNaN(fecha.getTime())) {
            return false;
        }

        return fecha >= hoy && fecha <= limite;
    });
}

/*
 * Solo cambia el contenido interno de la campana.
 * Abrir y cerrar la campana lo hace el HTML.
 */
function actualizarNotificaciones() {
    const productosPorVencer = obtenerProductosPorVencer();

    const contador = obtenerElemento("contadorAlertas");
    const textoAlertas = obtenerElemento("textoAlertas");
    const contenido = obtenerElemento("contenidoNotificaciones");
    const botonCampana = obtenerElemento("bellBtn");

    if (contador) {
        contador.textContent = productosPorVencer.length;
    }

    if (textoAlertas) {
        textoAlertas.textContent = productosPorVencer.length
            ? productosPorVencer.length + " alerta(s)"
            : "Sin alertas";
    }

    if (botonCampana) {
        botonCampana.setAttribute(
            "aria-label",
            `Notificaciones: ${productosPorVencer.length} productos próximos a vencer`
        );
    }

    if (!contenido) {
        return;
    }

    if (productosPorVencer.length === 0) {
        contenido.innerHTML = `
            <strong style="display:block;font-size:14px;color:#1e293b;">Todo en orden</strong>
            <span style="font-size:12px;color:#64748b;">
                No hay productos próximos a vencer en los siguientes 7 días.
            </span>
        `;
        return;
    }

    contenido.innerHTML = productosPorVencer.map(producto => {
        const nombre = obtenerNombreProducto(producto);
        const fecha = producto.vencimiento ||
            producto.fechaVencimiento ||
            producto.fecha_vencimiento;

        return `
            <div style="margin-bottom:8px;">
                <strong style="display:block;font-size:13px;color:#1e293b;">
                    ${escaparHTML(nombre)}
                </strong>
                <span style="font-size:12px;color:#64748b;">
                    Vence: ${escaparHTML(fecha)}
                </span>
            </div>
        `;
    }).join("");
}

/* -------------------- MENÚ LATERAL (ANTERIOR) -------------------- */

function toggleMenuInventario() {
    const menu = obtenerElemento("menuInventario");

    if (menu) {
        menu.classList.toggle("hidden");
    }
}

function cerrarMenuInventario() {
    const menu = obtenerElemento("menuInventario");

    if (menu) {
        menu.classList.add("hidden");
    }
}

/* -------------------- MENÚ DE CUENTA -------------------- */

function toggleMenuCuenta(evento) {
    if (evento) {
        evento.stopPropagation();
    }

    const menu = obtenerElemento("menuCuenta");

    if (menu) {
        menu.classList.toggle("hidden");
    }
}

function cerrarMenuCuenta() {
    const menu = obtenerElemento("menuCuenta");

    if (menu) {
        menu.classList.add("hidden");
    }
}

/* -------------------- CUENTAS: MI CUENTA / AGREGAR / CAMBIAR -------------------- */

function abrirModalCuenta() {
    const modal = obtenerElemento("modalCuenta");

    if (modal) {
        modal.classList.remove("hidden");
        modal.style.display = "flex";
    }
}

function abrirMiCuenta() {
    cerrarMenuCuenta();

    const cuenta = obtenerCuentaActiva();

    if (!cuenta) {
        return;
    }

    cuentaEditandoId = cuenta.id;
    fotoTemporal = cuenta.foto || "";

    const titulo = obtenerElemento("tituloModalCuenta");

    if (titulo) {
        titulo.textContent = "Mi cuenta";
    }

    const campoFoto = obtenerElemento("fotoCuenta");

    if (campoFoto) {
        campoFoto.value = "";
    }

    obtenerElemento("cuentaNombre").value = cuenta.nombre || "";
    obtenerElemento("cuentaCorreo").value = cuenta.correo || "";
    obtenerElemento("cuentaRol").value = cuenta.rol || "";

    mostrarVistaPreviaFoto(fotoTemporal);
    abrirModalCuenta();
}

function abrirAgregarCuenta() {
    cerrarMenuCuenta();

    cuentaEditandoId = null;
    fotoTemporal = "";

    const titulo = obtenerElemento("tituloModalCuenta");

    if (titulo) {
        titulo.textContent = "Agregar cuenta";
    }

    const campoFoto = obtenerElemento("fotoCuenta");

    if (campoFoto) {
        campoFoto.value = "";
    }

    obtenerElemento("cuentaNombre").value = "";
    obtenerElemento("cuentaCorreo").value = "";
    obtenerElemento("cuentaRol").value = "Administrador";

    mostrarVistaPreviaFoto("");
    abrirModalCuenta();
}

function cerrarModalCuenta() {
    const modal = obtenerElemento("modalCuenta");

    if (modal) {
        modal.classList.add("hidden");
        modal.style.display = "none";
    }
}

function guardarCuenta() {
    const nombre = obtenerValor("cuentaNombre");
    const correo = obtenerValor("cuentaCorreo");
    const rol = obtenerValor("cuentaRol");

    if (!nombre || !correo || !rol) {
        mostrarMensaje("Completa el nombre, correo y rol de la cuenta.");
        return;
    }

    const campoCorreo = obtenerElemento("cuentaCorreo");

    if (campoCorreo && !campoCorreo.checkValidity()) {
        mostrarMensaje("Escribe un correo electrónico válido.");
        return;
    }

    cargarCuentas();

    if (cuentaEditandoId) {
        const cuenta = cuentas.find(c => c.id === cuentaEditandoId);

        if (cuenta) {
            cuenta.nombre = nombre;
            cuenta.correo = correo;
            cuenta.rol = rol;
            cuenta.foto = fotoTemporal || "";
        }
    } else {
        const correoExiste = cuentas.some(
            c => normalizarTexto(c.correo) === normalizarTexto(correo)
        );

        if (correoExiste) {
            mostrarMensaje("Ese correo ya pertenece a otra cuenta.");
            return;
        }

        const nuevaCuenta = {
            id: Date.now().toString(),
            nombre: nombre,
            correo: correo,
            rol: rol,
            foto: fotoTemporal || ""
        };

        cuentas.push(nuevaCuenta);
        localStorage.setItem(CLAVE_CUENTA_ACTIVA, nuevaCuenta.id);
    }

    if (!guardarLista(CLAVE_CUENTAS, cuentas)) {
        mostrarMensaje("No se pudo guardar la cuenta. Prueba con una foto más pequeña.");
        return;
    }

    actualizarPerfil();
    prepararFormularioEntrada();
    cerrarModalCuenta();

    mostrarMensaje("Los datos de la cuenta se guardaron correctamente.");
}

function abrirCambiarCuenta() {
    cerrarMenuCuenta();

    const activa = obtenerCuentaActiva();
    const lista = obtenerElemento("listaCuentas");

    if (!lista) {
        mostrarMensaje(
            "La selección de otras cuentas necesita una lista de usuarios registrada."
        );
        return;
    }

    lista.innerHTML = "";

    cuentas.forEach(cuenta => {
        const boton = document.createElement("button");
        boton.type = "button";
        boton.style.cssText =
            "display:flex;align-items:center;justify-content:space-between;" +
            "width:100%;padding:12px;margin-bottom:8px;border:1px solid #e2e8f0;" +
            "border-radius:8px;background:#fff;cursor:pointer;text-align:left;";

        const info = document.createElement("div");

        const nombre = document.createElement("strong");
        nombre.textContent = cuenta.nombre;

        const detalle = document.createElement("small");
        detalle.style.cssText = "display:block;color:#64748b;";
        detalle.textContent = cuenta.rol + " · " + cuenta.correo;

        info.append(nombre, detalle);
        boton.appendChild(info);

        if (activa && cuenta.id === activa.id) {
            const marca = document.createElement("span");
            marca.textContent = "✓";
            marca.style.cssText = "color:#16a34a;font-weight:bold;font-size:18px;";
            boton.appendChild(marca);
        }

        boton.addEventListener("click", function () {
            cambiarCuenta(cuenta.id);
        });

        lista.appendChild(boton);
    });

    const modal = obtenerElemento("modalCambiarCuenta");

    if (modal) {
        modal.classList.remove("hidden");
        modal.style.display = "flex";
    }
}

function cambiarCuenta(id) {
    const cuenta = cuentas.find(c => c.id === id);

    if (!cuenta) {
        return;
    }

    localStorage.setItem(CLAVE_CUENTA_ACTIVA, id);

    actualizarPerfil();
    prepararFormularioEntrada();
    cerrarModalCambiarCuenta();

    mostrarMensaje("Cuenta cambiada correctamente: " + cuenta.nombre);
}

function cerrarModalCambiarCuenta() {
    const modal = obtenerElemento("modalCambiarCuenta");

    if (modal) {
        modal.classList.add("hidden");
        modal.style.display = "none";
    }
}

/* Vista previa de la foto en el modal de cuenta */
function mostrarVistaPreviaFoto(origen) {
    const vistaPrevia = obtenerElemento("previewFoto");
    const icono = obtenerElemento("previewIcono");

    if (origen) {
        if (vistaPrevia) {
            vistaPrevia.src = origen;
            vistaPrevia.classList.remove("hidden");
        }

        if (icono) {
            icono.classList.add("hidden");
        }
    } else {
        if (vistaPrevia) {
            vistaPrevia.removeAttribute("src");
            vistaPrevia.classList.add("hidden");
        }

        if (icono) {
            icono.classList.remove("hidden");
        }
    }
}

function previsualizarFoto() {
    /* La vista previa la maneja configurarFotoCuenta() */
}

function configurarFotoCuenta() {
    const campoFoto = obtenerElemento("fotoCuenta");

    if (!campoFoto) {
        return;
    }

    campoFoto.addEventListener("change", function () {
        const archivo = this.files && this.files[0];

        if (!archivo) {
            return;
        }

        if (!archivo.type.startsWith("image/")) {
            mostrarMensaje("Selecciona un archivo de imagen.");
            this.value = "";
            return;
        }

        const lector = new FileReader();

        lector.onload = function () {
            const imagen = new Image();

            imagen.onload = function () {
                if (imagen.width > 1200 || imagen.height > 1200) {
                    mostrarMensaje(
                        "La imagen es demasiado grande. Selecciona una imagen más pequeña."
                    );
                    return;
                }

                /* La foto se guarda en la cuenta al pulsar "Guardar cambios" */
                fotoTemporal = lector.result;
                mostrarVistaPreviaFoto(fotoTemporal);
            };

            imagen.onerror = function () {
                mostrarMensaje("No se pudo abrir la imagen seleccionada.");
            };

            imagen.src = lector.result;
        };

        lector.readAsDataURL(archivo);
    });
}

/* -------------------- CONFIGURACIÓN -------------------- */

function abrirConfiguracion() {
    mostrarMensaje(
        "La configuración se puede conectar desde el módulo de configuración del sistema."
    );
}

function cerrarSesion() {
    const confirmar = confirm("¿Deseas cerrar la sesión?");

    if (confirmar) {
        window.location.href = "login.html";
    }
}

/* -------------------- INICIALIZACIÓN -------------------- */

function configurarFormulario() {
    const formulario = obtenerElemento("addProductForm");

    if (formulario) {
        formulario.addEventListener("submit", guardarEntrada);
    }

    /* Cada vez que se abre el formulario se refresca responsable, fecha y hora */
    const botonAbrir = obtenerElemento("openModalBtn");

    if (botonAbrir) {
        botonAbrir.addEventListener("click", prepararFormularioEntrada);
    }

    /*
     * El botón "+ Agregar", el botón "Cancelar" y la campana
     * ya los maneja el script del propio HTML.
     */

    const tabla = obtenerElemento("tablaEntradasBody");

    if (tabla) {
        tabla.addEventListener("click", function (evento) {
            const boton = evento.target.closest(".btn-eliminar");

            if (boton && boton.dataset.id) {
                eliminarEntrada(boton.dataset.id);
            }
        });
    }

    configurarBusqueda();
    configurarFotoCuenta();
}

/* Vuelve a leer todo (tabla, gráficos, cuenta) desde lo guardado */
function refrescarPantalla() {
    actualizarPerfil();
    prepararFormularioEntrada();
    renderizarTabla();
    actualizarGraficos();
    actualizarNotificaciones();
}

document.addEventListener("DOMContentLoaded", function () {
    cargarDatosIniciales();
    cargarCuentas();
    actualizarPerfil();
    configurarFormulario();
    renderizarTabla();
    actualizarGraficos();
    actualizarNotificaciones();

    const fecha = obtenerElemento("inputFecha");

    if (fecha && !fecha.value) {
        fecha.value = obtenerFechaLocal();
    }

    const hora = obtenerElemento("inputHora");

    if (hora && !hora.value) {
        hora.value = obtenerHoraLocal();
    }

    prepararFormularioEntrada();

    document.addEventListener("click", function (evento) {
        const menuCuenta = obtenerElemento("menuCuenta");
        const perfil = document.querySelector(".user-profile");

        if (
            menuCuenta &&
            !menuCuenta.contains(evento.target) &&
            (!perfil || !perfil.contains(evento.target))
        ) {
            menuCuenta.classList.add("hidden");
        }
    });
});

/* Si el Panel de Control, el Catálogo o el Menú cambian los datos
   (otra pestaña, o al volver a esta página), todo se actualiza solo. */
window.addEventListener("storage", function (evento) {
    if (
        evento.key === CLAVE_ENTRADAS ||
        evento.key === CLAVE_INVENTARIO ||
        evento.key === CLAVE_PRODUCTOS ||
        evento.key === CLAVE_CUENTAS ||
        evento.key === CLAVE_CUENTA_ACTIVA
    ) {
        refrescarPantalla();
    }
});

window.addEventListener("focus", refrescarPantalla);
window.addEventListener("pageshow", refrescarPantalla);