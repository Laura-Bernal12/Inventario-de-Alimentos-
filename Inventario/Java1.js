// Datos iniciales de la tabla
 
const initialData = [
 
  { id: 1, producto: "Leche Entera", cantidad: "40 L", fecha: "27 May 2026", hora: "07:15", destino: "Cocina Escolar", responsable: "Ma. Antonieta López" },
 
  { id: 2, producto: "Aceite Vegetal", cantidad: "15 L", fecha: "25 May 2026", hora: "08:00", destino: "Cocina Escolar", responsable: "Esperanza Ruíz" },
 
  { id: 3, producto: "Arroz Blanco", cantidad: "17 Kg", fecha: "23 May 2026", hora: "07:30", destino: "Cocina Escolar", responsable: "Esperanza Ruíz" },
 
  { id: 4, producto: "Pan Integral", cantidad: "40 pzas", fecha: "21 May 2026", hora: "07:20", destino: "Cocina Escolar", responsable: "Ma. Antonieta López" }
 
];
 
 
 
// Cargar tabla desde localStorage o utilizar initialData
 
let tableData = JSON.parse(localStorage.getItem("salidasTableData")) || [...initialData];
 
 
 
let chartLineInstance = null;
 
let chartDonutInstance = null;
 
 
 
const $ = id => document.getElementById(id);
 
 
 
/* =====================================================
 
   ORDENAMIENTO DE TABLA POR FECHA
 
\====================================================== */
 
function parseFechaSpanish(fechaStr) {
 
  if (!fechaStr) return new Date(0);
 
  const meses = {
 
    jan: 0, ene: 0, feb: 1, mar: 2, apr: 3, abr: 3, may: 4, jun: 5,
 
    jul: 6, aug: 7, ago: 7, sep: 8, oct: 9, nov: 10, dec: 11, dic: 11
 
  };
 
 
 
  const partes = fechaStr.trim().split(/\s+/);
 
  if (partes.length >= 3) {
 
    const dia = parseInt(partes[0], 10) || 1;
 
    const mesKey = partes[1].toLowerCase().substring(0, 3);
 
    const mes = meses[mesKey] !== undefined ? meses[mesKey] : 0;
 
    const anio = parseInt(partes[2], 10) || new Date().getFullYear();
 
    return new Date(anio, mes, dia);
 
  }
 
 
 
  const parsed = Date.parse(fechaStr);
 
  return isNaN(parsed) ? new Date(0) : new Date(parsed);
 
}
 
 
 
function ordenarPorFecha() {
 
  tableData.sort((a, b) => parseFechaSpanish(b.fecha) - parseFechaSpanish(a.fecha));
 
}
 
 
 
/* =====================================================
 
   MENÚ HAMBURGUESA Y NAVEGACIÓN
 
\====================================================== */
 
function inicializarMenuHamburguesa() {
 
  const menuLateral = document.querySelector(".sidebar") || document.querySelector(".menu-lateral");
 
  const btnHamburguesa = $("btnHamburguesa");
 
  const fondoMenu = $("fondoMenu");
 
 
 
  if (btnHamburguesa && menuLateral) {
 
    btnHamburguesa.onclick = (e) => {
 
      e.stopPropagation();
 
      let abierto = menuLateral.classList.toggle("menu-abierto");
 
      document.querySelector(".main-content")?.classList.toggle("menu-desplazado", abierto);
 
      document.querySelector(".contenido")?.classList.toggle("menu-desplazado", abierto);
 
      fondoMenu?.classList.toggle("fondo-visible", false);
 
    };
 
  }
 
 
 
  if (fondoMenu) {
 
    fondoMenu.onclick = () => {
 
      menuLateral.classList.remove("menu-abierto");
 
      fondoMenu.classList.remove("fondo-visible");
 
      document.querySelector(".main-content")?.classList.remove("menu-desplazado");
 
      document.querySelector(".contenido")?.classList.remove("menu-desplazado");
 
    };
 
  }
 
}
 
 
 
function mostrarModulo(modulo, boton) {
 
  document.querySelectorAll(".menu-item").forEach(b => b.classList.remove("active", "activo"));
 
  if (boton) boton.classList.add("activo", "active");
 
 
 
  const menuLateral = document.querySelector(".sidebar") || document.querySelector(".menu-lateral");
 
  if (menuLateral) menuLateral.classList.remove("menu-abierto");
 
 
 
  const fondoMenu = $("fondoMenu");
 
  if (fondoMenu) fondoMenu.classList.remove("fondo-visible");
 
 
 
  document.querySelector(".main-content")?.classList.remove("menu-desplazado");
 
  document.querySelector(".contenido")?.classList.remove("menu-desplazado");
 
}
 
 
 
// Extrae la parte numérica de una string tipo "40 L"
 
function parseCantidad(cantStr) {
 
  if (!cantStr) return 0;
 
  const match = cantStr.toString().match(/[0-9]+(?:[.,][0-9]+)?/);
 
  return match ? parseFloat(match[0]) : 0;
 
}
 
 
 
// Extrae la unidad de medida (ej: L, Kg, pzas, lb)
 
function parseUnidad(cantStr) {
 
  if (!cantStr) return "";
 
  const match = cantStr.toString().trim().match(/[^0-9.,\s]+/);
 
  return match ? match[0] : "";
 
}
 
 
 
 
/* =====================================================
   SINCRONIZACIÓN CON EL INVENTARIO DEL MÓDULO ENTRADAS
   Se usan las mismas claves compartidas por las páginas.
====================================================== */
function normalizarTextoInventario(valor) {
  return String(valor ?? "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().toLowerCase();
}
 
function numeroCantidad(valor) {
  const match = String(valor ?? "").replace(/,/g, ".").match(/[0-9]+(?:.[0-9]+)?/);
  return match ? Number(match[0]) : NaN;
}
 
function unidadCantidad(valor) {
  const texto = String(valor ?? "").trim();
  const match = texto.match(/[^0-9.,\s]+/);
  return match ? normalizarTextoInventario(match[0]) : "";
}
 
function familiaUnidad(unidad) {
  const u = normalizarTextoInventario(unidad).replace(/./g, "");
  if (["lb", "lbs", "libra", "libras"].includes(u)) return "peso-lb";
  if (["kg", "kgs", "kilo", "kilos", "kilogramo", "kilogramos"].includes(u)) return "peso-kg";
  if (["g", "gr", "gramo", "gramos"].includes(u)) return "peso-g";
  if (["l", "lt", "lts", "litro", "litros"].includes(u)) return "volumen-l";
  if (["ml", "mililitro", "mililitros"].includes(u)) return "volumen-ml";
  if (["pza", "pzas", "pieza", "piezas", "unidad", "unidades", "ud", "uds"].includes(u)) return "unidad";
  return u;
}
 
function obtenerInventarioCompartido() {
  const claves = ["productosInventario", "productos", "inventarioEscolar"];
  for (const clave of claves) {
    try {
      const valor = JSON.parse(localStorage.getItem(clave) || "null");
      if (Array.isArray(valor)) return { clave, datos: valor };
    } catch (error) { console.warn("No se pudo leer " + clave, error); }
  }
  return { clave: "productosInventario", datos: [] };
}
 
function guardarInventarioCompartido(inventario) {
  const texto = JSON.stringify(inventario);
  // Guardar ambas claves permite que Entradas y el panel lean la misma existencia.
  localStorage.setItem("productosInventario", texto);
  localStorage.setItem("productos", texto);
  localStorage.setItem("inventarioEscolar", texto);
}
 
function buscarProductoInventario(inventario, nombre) {
  const buscado = normalizarTextoInventario(nombre);
  return inventario.find(p => normalizarTextoInventario(p.nombre ?? p.producto ?? p.nombreProducto) === buscado);
}
 
function leerCantidadExistente(producto) {
  const campos = ["cantidad", "existencia", "stock", "cantidadDisponible", "cantidadActual"];
  for (const campo of campos) {
    if (producto[campo] !== undefined && producto[campo] !== null && producto[campo] !== "") {
      const n = numeroCantidad(producto[campo]);
      if (Number.isFinite(n)) return { campo, cantidad: n, unidad: unidadCantidad(producto[campo]) || normalizarTextoInventario(producto.unidad || "") };
    }
  }
  return null;
}
 
function cantidadSalidaCompatible(cantidadTexto, unidadInventario) {
  const n = numeroCantidad(cantidadTexto);
  const unidadSalida = unidadCantidad(cantidadTexto);
  if (!Number.isFinite(n) || n <= 0) return { ok: false, mensaje: "Ingresá una cantidad mayor que cero." };
  const unidadInv = familiaUnidad(unidadInventario);
  const unidadOut = familiaUnidad(unidadSalida || unidadInventario);
  if (unidadInv && unidadOut && unidadInv !== unidadOut) {
    return { ok: false, mensaje: `La unidad de la salida (${unidadSalida}) no coincide con la unidad del inventario (${unidadInventario}).` };
  }
  return { ok: true, cantidad: n };
}
 
function modificarExistenciaPorSalida(nombre, cantidadTexto, operacion) {
  const { datos: inventario } = obtenerInventarioCompartido();
  const producto = buscarProductoInventario(inventario, nombre);
  if (!producto) {
    return { ok: false, mensaje: `No encontré “${nombre}” en Entradas. Registrá primero el producto y su existencia.` };
  }
  const info = leerCantidadExistente(producto);
  if (!info) return { ok: false, mensaje: `No pude leer la existencia actual de “${nombre}” en Entradas.` };
  const validacion = cantidadSalidaCompatible(cantidadTexto, info.unidad || producto.unidad || "");
  if (!validacion.ok) return validacion;
  const cantidad = validacion.cantidad;
  let nueva = info.cantidad;
  if (operacion === "restar") {
    if (cantidad > info.cantidad) {
      return { ok: false, mensaje: `No hay suficiente ${nombre}. Existencia actual: ${info.cantidad} ${info.unidad || producto.unidad || ""}.` };
    }
    nueva -= cantidad;
  } else {
    nueva += cantidad;
  }
  const unidad = info.unidad || producto.unidad || "";
  producto[info.campo] = `${Number(nueva.toFixed(3))}${unidad ? " " + unidad : ""}`;
  // Si la página de Entradas maneja cantidad numérica y unidad por separado, conservar ese formato.
  if (typeof producto[info.campo] === "string" && producto.unidad) {
    producto[info.campo] = Number(nueva.toFixed(3));
    producto.unidad = unidad;
  }
  guardarInventarioCompartido(inventario);
  return { ok: true, cantidadAnterior: info.cantidad, cantidadNueva: nueva };
}
 
function aplicarSalidaAInventario(item) {
  const resultado = modificarExistenciaPorSalida(item.producto, item.cantidad, "restar");
  if (resultado.ok) item.inventarioAplicado = true;
  return resultado;
}
 
function revertirSalidaDeInventario(item) {
  if (!item || item.inventarioAplicado !== true) return { ok: true, omitido: true };
  const resultado = modificarExistenciaPorSalida(item.producto, item.cantidad, "sumar");
  if (resultado.ok) item.inventarioAplicado = false;
  return resultado;
}
 
 
function saveTableData() {
 
  ordenarPorFecha();
 
  localStorage.setItem("salidasTableData", JSON.stringify(tableData));
 
  actualizarGraficasDesdeTabla();
 
}
 
 
 
function renderTable(data) {
 
  const tbody = document.getElementById("tableBody");
 
  if (!tbody) return;
 
  tbody.innerHTML = "";
 
 
 
  data.forEach((item) => {
 
    const row = document.createElement("tr");
 
    row.innerHTML = `
 
      <td>${item.producto}</td>
 
      <td class="qty-text">${item.cantidad}</td>
 
      <td>${item.fecha}</td>
 
      <td>${item.hora}</td>
 
      <td>${item.destino}</td>
 
      <td>${item.responsable}</td>
 
      <td>
 
        <button class="btn-action" title="Editar fila" onclick="editRow(${item.id})">✏️</button>
 
        <button class="btn-action" title="Eliminar fila" onclick="deleteRow(${item.id})">🗑️</button>
 
      </td>
 
    `;
 
    tbody.appendChild(row);
 
  });
 
}
 
 
 
function addNewRowPrompt() {
  const producto = prompt("Nombre del producto tal como aparece en Entradas:");
  if (!producto) return;
  const cantidad = prompt("Cantidad que salió (incluí la unidad, por ejemplo: 5 lb):", "5 lb");
  if (cantidad === null) return;
  const fecha = prompt("Fecha (ej: 22 May 2026):", "22 May 2026");
  if (fecha === null) return;
  const hora = prompt("Hora:", "08:30");
  if (hora === null) return;
  const destino = prompt("Destino:", "Cocina Escolar");
  if (destino === null) return;
  const responsable = prompt("Responsable:", "Ma. Antonieta López");
  if (responsable === null) return;
 
  const newItem = { id: Date.now(), producto: producto.trim(), cantidad: cantidad.trim(), fecha, hora, destino, responsable, inventarioAplicado: false };
  const resultado = aplicarSalidaAInventario(newItem);
  if (!resultado.ok) { alert(resultado.mensaje); return; }
  tableData.push(newItem);
  try {
    saveTableData();
    renderTable(tableData);
    alert(`Salida registrada. Existencia de ${producto}: ${resultado.cantidadNueva} ${unidadCantidad(cantidad) || "unidades"}.`);
  } catch (error) {
    modificarExistenciaPorSalida(newItem.producto, newItem.cantidad, "sumar");
    alert("No se pudo guardar la salida. Se intentó restaurar la existencia.");
    console.error(error);
  }
}
 
function filterTable() {
 
  const query = document.getElementById("filterInput").value.toLowerCase();
 
  const filtered = tableData.filter(item => 
 
    item.producto.toLowerCase().includes(query) ||
 
    item.responsable.toLowerCase().includes(query)
 
  );
 
  renderTable(filtered);
 
}
 
 
 
function editRow(id) {
  const item = tableData.find(d => d.id === id);
  if (!item) return;
 
  const original = { ...item };
  const newProd = prompt("Editar Producto:", item.producto);
  if (newProd === null) return;
  const newQty = prompt("Editar Cantidad (incluí unidad, ej: 5 lb):", item.cantidad);
  if (newQty === null) return;
  const newFecha = prompt("Editar Fecha:", item.fecha);
  if (newFecha === null) return;
  const newResp = prompt("Editar Responsable:", item.responsable);
  if (newResp === null) return;
 
  // Deshacer la salida anterior si este registro ya afectó el inventario.
  if (item.inventarioAplicado === true) {
    const revertido = revertirSalidaDeInventario(item);
    if (!revertido.ok) { alert("No se pudo actualizar el inventario: " + revertido.mensaje); return; }
  }
 
  item.producto = newProd.trim();
  item.cantidad = newQty.trim();
  item.fecha = newFecha;
  item.responsable = newResp;
  item.inventarioAplicado = false;
 
  const aplicado = aplicarSalidaAInventario(item);
  if (!aplicado.ok) {
    // Restaurar inventario y datos originales para evitar dejar cambios a medias.
    if (item.inventarioAplicado === true) revertirSalidaDeInventario(item);
    Object.assign(item, original);
    if (original.inventarioAplicado === true) modificarExistenciaPorSalida(original.producto, original.cantidad, "restar");
    alert(aplicado.mensaje);
    return;
  }
 
  try {
    saveTableData();
    renderTable(tableData);
    alert(`Registro actualizado. Existencia de ${item.producto}: ${aplicado.cantidadNueva}.`);
  } catch (error) {
    if (item.inventarioAplicado === true) revertirSalidaDeInventario(item);
    Object.assign(item, original);
    if (original.inventarioAplicado === true) modificarExistenciaPorSalida(original.producto, original.cantidad, "restar");
    console.error(error);
    alert("No se pudo guardar el cambio; se restauraron los datos anteriores.");
  }
}
 
function deleteRow(id) {
  const item = tableData.find(d => d.id === id);
  if (!item || !confirm("¿Deseas eliminar este registro?")) return;
  if (item.inventarioAplicado === true) {
    const resultado = revertirSalidaDeInventario(item);
    if (!resultado.ok) { alert("No se pudo devolver la cantidad al inventario: " + resultado.mensaje); return; }
  }
  tableData = tableData.filter(d => d.id !== id);
  saveTableData();
  renderTable(tableData);
}
 
/* =====================================================
 
   INTEGRACIÓN AUTOMÁTICA DESDE EL MÓDULO DE MENÚ
 
\====================================================== */
 
function registrarSalidaDesdeMenu(nombreAlimento, cantidadTotalLibras, responsableAsignado = "Ma. Antonieta López") {
  const cantidadTexto = typeof cantidadTotalLibras === "number"
    ? `${cantidadTotalLibras} lb`
    : String(cantidadTotalLibras ?? "").trim();
  const ahora = new Date();
  const fechaActual = ahora.toLocaleDateString('es-ES', { day: '2-digit', month: 'short', year: 'numeric' });
  const horaActual = ahora.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const nuevoRegistro = {
    id: Date.now() + Math.random(),
    producto: String(nombreAlimento ?? "").trim(),
    cantidad: cantidadTexto,
    fecha: fechaActual,
    hora: horaActual,
    destino: "Cocina Escolar",
    responsable: responsableAsignado,
    inventarioAplicado: false
  };
  const resultado = aplicarSalidaAInventario(nuevoRegistro);
  if (!resultado.ok) {
    alert("No se pudo registrar la salida del menú: " + resultado.mensaje);
    return false;
  }
  tableData.push(nuevoRegistro);
  saveTableData();
  renderTable(tableData);
  return true;
}
 
 
 
/* =====================================================
 
   CONTROL DE DESPLEGABLES Y EDICIÓN
 
\====================================================== */
 
function toggleEditDropdown(panelId, event) {
 
  if (event) event.stopPropagation();
 
  const panel = document.getElementById(panelId);
 
  const allPanels = document.querySelectorAll('.edit-dropdown');
 
 
 
  const estabaActivo = panel ? panel.classList.contains('active') : false;
 
 
 
  allPanels.forEach(p => {
 
    p.classList.remove('active');
 
    p.classList.add('hidden');
 
  });
 
 
 
  if (panel && !estabaActivo) {
 
    panel.classList.remove('hidden');
 
    panel.classList.add('active');
 
  }
 
}
 
 
 
function toggleTableColumn(colIndex) {
 
  const table = document.getElementById("salidasTable");
 
  for (let row of table.rows) {
 
    if (row.cells[colIndex]) {
 
      const isHidden = row.cells[colIndex].style.display === 'none';
 
      row.cells[colIndex].style.display = isHidden ? '' : 'none';
 
    }
 
  }
 
}
 
 
 
/* =====================================================
 
   GESTIÓN DE CUENTAS
 
\====================================================== */
 
let cuentas = JSON.parse(localStorage.getItem("cuentasInventario")) || [];
 
let cuentaActivaId = localStorage.getItem("cuentaActivaInventario");
 
let cuentaEditandoId = null;
 
let fotoTemporal = "";
 
 
 
if (cuentas.length === 0) {
 
  const cuentaInicial = {
 
    id: Date.now().toString(),
 
    nombre: "Admin",
 
    correo: "admin\@instituto.com",
 
    rol: "Administrador",
 
    foto: ""
 
  };
 
  cuentas.push(cuentaInicial);
 
  cuentaActivaId = cuentaInicial.id;
 
  guardarCuentas();
 
}
 
 
 
function guardarCuentas() {
 
  localStorage.setItem("cuentasInventario", JSON.stringify(cuentas));
 
  localStorage.setItem("cuentaActivaInventario", cuentaActivaId);
 
}
 
 
 
function obtenerCuentaActiva() {
 
  return cuentas.find(c => c.id === cuentaActivaId) || cuentas[0];
 
}
 
 
 
function actualizarPerfil() {
 
  const cuenta = obtenerCuentaActiva();
 
  if (!cuenta) return;
 
 
 
  if ($("nombreAdministrador")) $("nombreAdministrador").innerText = cuenta.nombre;
 
  if ($("rolAdministrador")) $("rolAdministrador").innerText = cuenta.rol;
 
  if ($("menuNombre")) $("menuNombre").innerText = cuenta.nombre;
 
  if ($("menuCorreo")) $("menuCorreo").innerText = cuenta.correo;
 
 
 
  const foto = $("fotoAdministrador");
 
  const icono = $("iconoAdministrador");
 
 
 
  if (foto && icono) {
 
    if (cuenta.foto) {
 
      foto.src = cuenta.foto;
 
      foto.classList.remove("hidden");
 
      icono.classList.add("hidden");
 
    } else {
 
      foto.classList.add("hidden");
 
      icono.classList.remove("hidden");
 
    }
 
  }
 
}
 
 
 
function toggleMenuCuenta(event) {
 
  if (event) event.stopPropagation();
 
  const menuCuenta = $("menuCuenta");
 
  if (!menuCuenta) return;
 
 
 
  const estabaActivo = menuCuenta.classList.contains('active');
 
 
 
  document.querySelectorAll('.edit-dropdown').forEach(p => {
 
    p.classList.remove('active');
 
    p.classList.add('hidden');
 
  });
 
 
 
  if (!estabaActivo) {
 
    menuCuenta.classList.remove('hidden');
 
    menuCuenta.classList.add('active');
 
  }
 
}
 
 
 
function cerrarModal(modalId) {
 
  const m = $(modalId);
 
  if (m) m.classList.add("hidden");
 
}
 
 
 
function abrirMiCuenta() {
 
  if ($("menuCuenta")) {
 
    $("menuCuenta").classList.add("hidden");
 
    $("menuCuenta").classList.remove("active");
 
  }
 
  const cuenta = obtenerCuentaActiva();
 
  if (!cuenta) return;
 
 
 
  cuentaEditandoId = cuenta.id;
 
  if ($("tituloCuenta")) $("tituloCuenta").innerText = "👤 Editar mi cuenta";
 
  if ($("cuentaNombre")) $("cuentaNombre").value = cuenta.nombre;
 
  if ($("cuentaCorreo")) $("cuentaCorreo").value = cuenta.correo;
 
  if ($("cuentaRol")) $("cuentaRol").value = cuenta.rol;
 
  fotoTemporal = cuenta.foto || "";
 
 
 
  mostrarPreview(fotoTemporal);
 
  if ($("btnEliminarCuenta")) $("btnEliminarCuenta").classList.remove("hidden");
 
  if ($("modalCuenta")) $("modalCuenta").classList.remove("hidden");
 
}
 
 
 
function abrirAgregarCuenta() {
 
  if ($("menuCuenta")) {
 
    $("menuCuenta").classList.add("hidden");
 
    $("menuCuenta").classList.remove("active");
 
  }
 
  cuentaEditandoId = null;
 
  fotoTemporal = "";
 
 
 
  if ($("tituloCuenta")) $("tituloCuenta").innerText = "➕ Agregar cuenta";
 
  if ($("cuentaNombre")) $("cuentaNombre").value = "";
 
  if ($("cuentaCorreo")) $("cuentaCorreo").value = "";
 
  if ($("cuentaRol")) $("cuentaRol").value = "Administrador";
 
  if ($("fotoCuenta")) $("fotoCuenta").value = "";
 
 
 
  mostrarPreview("");
 
  if ($("btnEliminarCuenta")) $("btnEliminarCuenta").classList.add("hidden");
 
  if ($("modalCuenta")) $("modalCuenta").classList.remove("hidden");
 
}
 
 
 
function previsualizarFoto(event) {
 
  const archivo = event.target.files[0];
 
  if (!archivo) return;
 
 
 
  const lector = new FileReader();
 
  lector.onload = function(e) {
 
    fotoTemporal = e.target.result;
 
    mostrarPreview(fotoTemporal);
 
  };
 
  lector.readAsDataURL(archivo);
 
}
 
 
 
function mostrarPreview(foto) {
 
  const imagen = $("previewFoto");
 
  const icono = $("previewIcono");
 
 
 
  if (imagen && icono) {
 
    if (foto) {
 
      imagen.src = foto;
 
      imagen.classList.remove("hidden");
 
      icono.classList.add("hidden");
 
    } else {
 
      imagen.classList.add("hidden");
 
      icono.classList.remove("hidden");
 
    }
 
  }
 
}
 
 
 
function guardarCuenta() {
 
  const nombre = $("cuentaNombre").value.trim();
 
  const correo = $("cuentaCorreo").value.trim();
 
  const rol = $("cuentaRol").value;
 
 
 
  if (!nombre) return alert("⚠️ Debes escribir el nombre.");
 
  if (!correo) return alert("⚠️ Debes escribir el correo.");
 
 
 
  if (cuentaEditandoId) {
 
    const cuenta = cuentas.find(c => c.id === cuentaEditandoId);
 
    if (cuenta) {
 
      cuenta.nombre = nombre;
 
      cuenta.correo = correo;
 
      cuenta.rol = rol;
 
      cuenta.foto = fotoTemporal;
 
    }
 
  } else {
 
    const nuevaCuenta = {
 
      id: Date.now().toString(),
 
      nombre: nombre,
 
      correo: correo,
 
      rol: rol,
 
      foto: fotoTemporal
 
    };
 
    cuentas.push(nuevaCuenta);
 
    cuentaActivaId = nuevaCuenta.id;
 
  }
 
 
 
  guardarCuentas();
 
  actualizarPerfil();
 
  cerrarModal("modalCuenta");
 
  alert("✅ Cuenta guardada correctamente.");
 
}
 
 
 
function abrirCambiarCuenta() {
 
  if ($("menuCuenta")) {
 
    $("menuCuenta").classList.add("hidden");
 
    $("menuCuenta").classList.remove("active");
 
  }
 
  const lista = $("listaCuentas");
 
  if (!lista) return;
 
  lista.innerHTML = "";
 
 
 
  cuentas.forEach(cuenta => {
 
    const boton = document.createElement("button");
 
    boton.className = "account-item-btn";
 
 
 
    const fotoHTML = cuenta.foto
 
      ? `<img src="${cuenta.foto}" alt="${cuenta.nombre}">`
 
      : `👤`;
 
 
 
    boton.innerHTML = `
 
      <div class="account-item-avatar">${fotoHTML}</div>
 
      <div class="account-item-info">
 
        <p class="account-name">${cuenta.nombre}</p>
 
        <p class="account-role">${cuenta.rol}</p>
 
      </div>
 
      ${cuenta.id === cuentaActivaId ? '<span class="active-check">✓</span>' : ''}
 
    `;
 
 
 
    boton.onclick = function() {
 
      cambiarCuenta(cuenta.id);
 
    };
 
 
 
    lista.appendChild(boton);
 
  });
 
 
 
  if ($("modalCambiarCuenta")) $("modalCambiarCuenta").classList.remove("hidden");
 
}
 
 
 
function cambiarCuenta(id) {
 
  cuentaActivaId = id;
 
  guardarCuentas();
 
  actualizarPerfil();
 
  cerrarModal("modalCambiarCuenta");
 
  alert("✅ Cuenta cambiada correctamente.");
 
}
 
 
 
function eliminarCuenta() {
 
  if (cuentas.length <= 1) {
 
    alert("⚠️ Debes tener al menos una cuenta.");
 
    return;
 
  }
 
 
 
  if (!confirm("¿Seguro que deseas eliminar esta cuenta?")) return;
 
 
 
  cuentas = cuentas.filter(c => c.id !== cuentaEditandoId);
 
  cuentaActivaId = cuentas[0].id;
 
 
 
  guardarCuentas();
 
  actualizarPerfil();
 
  cerrarModal("modalCuenta");
 
  alert("🗑️ Cuenta eliminada.");
 
}
 
 
 
function logoutAccount() {
 
  alert("Cerrando sesión de usuario...");
 
}
 
 
 
/* =====================================================
 
   PROCESAMIENTO DE DATOS CON UNIDADES Y FECHAS
 
\====================================================== */
 
function procesarDatosTabla() {
 
  const productosMap = {};
 
  const fechasMap = {};
 
 
 
  tableData.forEach(item => {
 
    const cant = parseCantidad(item.cantidad);
 
    const unidad = parseUnidad(item.cantidad);
 
 
 
    if (!productosMap[item.producto]) {
 
      productosMap[item.producto] = { total: 0, unidad: unidad };
 
    }
 
    productosMap[item.producto].total += cant;
 
 
 
    if (!fechasMap[item.fecha]) {
 
      fechasMap[item.fecha] = {
 
        total: 0,
 
        detalles: {}
 
      };
 
    }
 
 
 
    fechasMap[item.fecha].total += cant;
 
 
 
    if (!fechasMap[item.fecha].detalles[item.producto]) {
 
      fechasMap[item.fecha].detalles[item.producto] = { cantidad: 0, unidad: unidad };
 
    }
 
    fechasMap[item.fecha].detalles[item.producto].cantidad += cant;
 
  });
 
 
 
  const fechasLabels = Object.keys(fechasMap);
 
  const fechasTotales = fechasLabels.map(f => fechasMap[f].total);
 
  const fechasDetallesObj = fechasLabels.map(f => fechasMap[f].detalles);
 
 
 
  const prodLabels = Object.keys(productosMap);
 
  const prodTotales = prodLabels.map(p => productosMap[p].total);
 
  const prodUnidades = prodLabels.map(p => productosMap[p].unidad);
 
 
 
  return {
 
    productos: {
 
      labels: prodLabels,
 
      data: prodTotales,
 
      unidades: prodUnidades
 
    },
 
    fechas: {
 
      labels: fechasLabels,
 
      data: fechasTotales,
 
      detalles: fechasDetallesObj
 
    }
 
  };
 
}
 
 
 
function actualizarGraficasDesdeTabla() {
 
  if (!chartLineInstance || !chartDonutInstance) return;
 
 
 
  const datosProcesados = procesarDatosTabla();
 
 
 
  chartDonutInstance.data.labels = datosProcesados.productos.labels;
 
  chartDonutInstance.data.datasets[0].data = datosProcesados.productos.data;
 
  chartDonutInstance.data.datasets[0].unidades = datosProcesados.productos.unidades;
 
  chartDonutInstance.update();
 
 
 
  chartLineInstance.data.labels = datosProcesados.fechas.labels;
 
  chartLineInstance.data.datasets[0].data = datosProcesados.fechas.data;
 
  chartLineInstance.data.datasets[0].detalles = datosProcesados.fechas.detalles;
 
  chartLineInstance.update();
 
}
 
 
 
function changeLineChartType(newType) {
 
  if (!chartLineInstance) return;
 
  const currentData = chartLineInstance.data;
 
  chartLineInstance.destroy();
 
 
 
  const ctxLine = document.getElementById('lineChart').getContext('2d');
 
  chartLineInstance = createLineChart(ctxLine, newType, currentData);
 
 
 
  localStorage.setItem("lineChartType", newType);
 
}
 
 
 
function changeDonutChartType(newType) {
 
  if (!chartDonutInstance) return;
 
  const currentData = chartDonutInstance.data;
 
  chartDonutInstance.destroy();
 
 
 
  const ctxDonut = document.getElementById('donutChart').getContext('2d');
 
  chartDonutInstance = createDonutChart(ctxDonut, newType, currentData);
 
 
 
  localStorage.setItem("donutChartType", newType);
 
}
 
 
 
/* =====================================================
 
   CREACIÓN DE GRÁFICAS Y PERSONALIZACIÓN DE TOOLTIPS
 
\====================================================== */
 
function createLineChart(ctx, type, data) {
 
  return new Chart(ctx, {
 
    type: type,
 
    data: data,
 
    options: {
 
      responsive: true,
 
      maintainAspectRatio: false,
 
      plugins: {
 
        legend: { display: false },
 
        tooltip: {
 
          callbacks: {
 
            label: function(context) {
 
              const dataset = context.dataset;
 
              const index = context.dataIndex;
 
              const detalles = dataset.detalles ? dataset.detalles[index] : null;
 
 
 
              if (detalles) {
 
                let lineas = [];
 
                let totalSuma = 0;
 
 
 
                for (let prod in detalles) {
 
                  const item = detalles[prod];
 
                  lineas.push(`${prod}: ${item.cantidad} ${item.unidad}`.trim());
 
                  totalSuma += item.cantidad;
 
                }
 
 
 
                lineas.push(`Consumo Total: ${totalSuma}`);
 
                return lineas;
 
              }
 
              return `Consumo: ${context.parsed.y}`;
 
            }
 
          }
 
        }
 
      },
 
      scales: type === 'pie' || type === 'doughnut' ? {} : {
 
        y: { beginAtZero: true }
 
      }
 
    }
 
  });
 
}
 
 
 
function createDonutChart(ctx, type, data) {
 
  return new Chart(ctx, {
 
    type: type,
 
    data: data,
 
    options: {
 
      responsive: true,
 
      maintainAspectRatio: false,
 
      plugins: {
 
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
 
        tooltip: {
 
          callbacks: {
 
            label: function(context) {
 
              const dataset = context.dataset;
 
              const totalSum = dataset.data.reduce((a, b) => a + b, 0);
 
              const currentValue = context.parsed || dataset.data[context.dataIndex];
 
              const percentage = totalSum > 0 ? ((currentValue / totalSum) * 100).toFixed(1) : 0;
 
              const unidad = dataset.unidades ? dataset.unidades[context.dataIndex] : "";
 
 
 
              return ` ${context.label}: ${currentValue} ${unidad} (${percentage}%)`;
 
            }
 
          }
 
        }
 
      }
 
    }
 
  });
 
}
 
 
 
function initCharts() {
 
  const lineCanvas = document.getElementById('lineChart');
 
  const donutCanvas = document.getElementById('donutChart');
 
 
 
  if (!lineCanvas || !donutCanvas) return;
 
 
 
  const lineType = localStorage.getItem("lineChartType") || 'line';
 
  const donutType = localStorage.getItem("donutChartType") || 'doughnut';
 
 
 
  if (document.getElementById("selectLineChartType")) {
 
    document.getElementById("selectLineChartType").value = lineType;
 
  }
 
  if (document.getElementById("selectDonutChartType")) {
 
    document.getElementById("selectDonutChartType").value = donutType;
 
  }
 
 
 
  const datosIniciales = procesarDatosTabla();
 
 
 
  const ctxLine = lineCanvas.getContext('2d');
 
  chartLineInstance = createLineChart(ctxLine, lineType, {
 
    labels: datosIniciales.fechas.labels,
 
    datasets: [{
 
      label: 'Consumo por Fecha',
 
      data: datosIniciales.fechas.data,
 
      detalles: datosIniciales.fechas.detalles,
 
      borderColor: '#ff0000',
 
      backgroundColor: '#ff000033',
 
      borderWidth: 3,
 
      pointBackgroundColor: '#ff0000',
 
      pointRadius: 6,
 
      tension: 0.4,
 
      fill: false
 
    }]
 
  });
 
 
 
  const ctxDonut = donutCanvas.getContext('2d');
 
  chartDonutInstance = createDonutChart(ctxDonut, donutType, {
 
    labels: datosIniciales.productos.labels,
 
    datasets: [{
 
      data: datosIniciales.productos.data,
 
      unidades: datosIniciales.productos.unidades,
 
      backgroundColor: ['#4d88ff', '#ffe066', '#f2ad73', '#b388ff', '#82ca9d', '#ffc658']
 
    }]
 
  });
 
}
 
 
 
document.onclick = e => {
 
  if (!e.target.closest(".user-profile") && 
 
      !e.target.closest(".btn-corner-edit") && 
 
      !e.target.closest(".edit-dropdown")) {
 
    document.querySelectorAll('.edit-dropdown').forEach(p => {
 
      p.classList.remove('active');
 
      p.classList.add('hidden');
 
    });
 
  }
 
};
 
 
 
document.addEventListener("DOMContentLoaded", () => {
 
  inicializarMenuHamburguesa();
 
  ordenarPorFecha();
 
  renderTable(tableData);
 
  initCharts();
 
  actualizarPerfil();
 
});