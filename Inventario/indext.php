<?php
session_start();
include 'conexion.php';

// Validación estricta de sesión
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['nombre'])) {
    header("Location: index.php");
    exit();
}

$nombreUsuario = $_SESSION['nombre'];
$rolUsuario = $_SESSION['rol'] ?? 'Usuario';
$emailUsuario = $_SESSION['email'] ?? '';

if (empty($emailUsuario)) {
    try {
        $stmtMail = $conexion->prepare("SELECT email FROM usuarios WHERE id = ?");
        $stmtMail->execute([$_SESSION['usuario_id']]);
        $resMail = $stmtMail->fetch(PDO::FETCH_ASSOC);
        $emailUsuario = $resMail['email'] ?? 'usuario@instituto.edu.sv';
        $_SESSION['email'] = $emailUsuario;
    } catch (Exception $e) {
        $emailUsuario = 'usuario@instituto.edu.sv';
    }
}

// Consultas para las alertas de la campanita (Stock bajo desde 10 hacia abajo y Próximo a vencer en 10 días o menos)
$alertasStockBajo = [];
$alertasVencimiento = [];

try {
    // 1. Consultar productos con stock bajo (estado 'Stock Bajo' O cantidad numérica menor o igual a 10)
    $stmtQueryBajo = $conexion->query("SELECT id, producto, cantidad, estado FROM catalogo");
    if ($stmtQueryBajo) {
        $todosCatalogoBajo = $stmtQueryBajo->fetchAll(PDO::FETCH_ASSOC);
        foreach ($todosCatalogoBajo as $prod) {
            preg_match('/[0-9]+(\.[0-9]+)?/', $prod['cantidad'], $matches);
            $numCantidad = isset($matches[0]) ? floatval($matches[0]) : 99;

            if (strcasecmp($prod['estado'], 'Stock Bajo') === 0 || $numCantidad <= 10) {
                $alertasStockBajo[] = [
                    'producto' => $prod['producto'],
                    'cantidad' => $prod['cantidad']
                ];
            }
        }
    }

    // 2. Próximos a vencer (en los próximos 10 días o vencidos) con cálculo exacto
    $stmtQueryVence = $conexion->query("SELECT producto, vencimiento FROM catalogo WHERE vencimiento IS NOT NULL AND vencimiento != ''");
    if ($stmtQueryVence) {
        $todosProds = $stmtQueryVence->fetchAll(PDO::FETCH_ASSOC);
        $hoy = new DateTime();
        $hoy->setTime(0, 0, 0); // Normalizar a medianoche

        foreach ($todosProds as $p) {
            if (!empty($p['vencimiento'])) {
                $fechaVenc = new DateTime($p['vencimiento']);
                $fechaVenc->setTime(0, 0, 0);
                
                $diferencia = $hoy->diff($fechaVenc);
                $diasRestantes = (int)$diferencia->format('%r%a'); // Días absolutos exactos
                
                if ($diasRestantes <= 10) {
                    $alertasVencimiento[] = [
                        'producto' => $p['producto'],
                        'dias' => $diasRestantes
                    ];
                }
            }
        }
    }
} catch (Exception $e) {
    // Manejo de errores silencioso para la BD
}

$totalAlertas = count($alertasStockBajo) + count($alertasVencimiento);

$mensajeExito = "";
$mensajeError = "";

// 1. ELIMINAR ENTRADA Y SU PRODUCTO ASOCIADO EN EL CATÁLOGO (Y SE ACTUALIZA EN EL PANEL)
if (isset($_GET['eliminar_id'])) {
    $idEliminar = intval($_GET['eliminar_id']);
    if ($idEliminar > 0) {
        try {
            // Primero obtenemos el nombre del producto de la entrada a eliminar
            $stmtGet = $conexion->prepare("SELECT producto FROM registroentradas WHERE id = ?");
            $stmtGet->execute([$idEliminar]);
            $entradaAEliminar = $stmtGet->fetch(PDO::FETCH_ASSOC);

            if ($entradaAEliminar) {
                $nombreProd = $entradaAEliminar['producto'];

                $conexion->beginTransaction();

                // Eliminar de registroentradas (esto lo quita de la Actividad Reciente en alimentos.php)
                $stmtDelEntrada = $conexion->prepare("DELETE FROM registroentradas WHERE id = ?");
                $stmtDelEntrada->execute([$idEliminar]);

                // Eliminar del catálogo el producto que coincida con ese nombre (esto lo quita del Catálogo y del Panel)
                $stmtDelCat = $conexion->prepare("DELETE FROM catalogo WHERE producto = ?");
                $stmtDelCat->execute([$nombreProd]);

                $conexion->commit();
                header("Location: indext.php?msg=eliminado");
                exit();
            } else {
                header("Location: indext.php?msg=eliminado");
                exit();
            }
        } catch (Exception $e) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            $mensajeError = "Error al eliminar la entrada: " . $e->getMessage();
        }
    }
}

// 2. AGREGAR NUEVA ENTRADA (GUARDA EN ENTRADAS Y EN EL CATÁLOGO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'agregar_entrada') {
    $producto = trim($_POST['producto'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'Granos');
    $cantidad = trim($_POST['cantidad'] ?? '');
    $fecha = !empty($_POST['fecha']) ? $_POST['fecha'] : date('Y-m-d');
    $hora = !empty($_POST['hora']) ? $_POST['hora'] : date('H:i:s');
    $proveedor = trim($_POST['proveedor'] ?? 'Ministerio de Educación');
    $responsable = trim($_POST['responsable'] ?? $nombreUsuario);
    $ubicacion = trim($_POST['ubicacion'] ?? 'Bodega Principal');
    $vencimiento = !empty($_POST['vencimiento']) ? $_POST['vencimiento'] : NULL;

    if (!empty($producto) && !empty($cantidad)) {
        try {
            // Guardar en Registro de Entradas
            $stmtIns = $conexion->prepare("INSERT INTO registroentradas (producto, cantidad, fecha, hora, proveedor, responsable) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([$producto, $cantidad, $fecha, $hora, $proveedor, $responsable]);

            // Generar código automático para el catálogo
            $resId = $conexion->query("SELECT id FROM catalogo ORDER BY id DESC LIMIT 1");
            $ultimoId = $resId ? $resId->fetch(PDO::FETCH_ASSOC) : false;
            $siguienteId = $ultimoId ? $ultimoId['id'] + 1 : 1;
            $codigo = "P" . str_pad($siguienteId, 3, "0", STR_PAD_LEFT);

            // Verificar si el stock de la nueva entrada es menor o igual a 10 para asignar el estado
            preg_match('/[0-9]+(\.[0-9]+)?/', $cantidad, $matches);
            $numCantidadNueva = isset($matches[0]) ? floatval($matches[0]) : 99;
            $estado = ($numCantidadNueva <= 10) ? 'Stock Bajo' : 'Normal';

            // Guardar también en el Catálogo
            $stmtCat = $conexion->prepare("INSERT INTO catalogo (codigo, producto, categoria, cantidad, estado, ubicacion, vencimiento) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtCat->execute([$codigo, $producto, $categoria, $cantidad, $estado, $ubicacion, $vencimiento]);

            header("Location: indext.php?msg=creado");
            exit();
        } catch (Exception $e) {
            $mensajeError = "Error al registrar el producto: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Por favor completa los campos obligatorios (Producto y Cantidad).";
    }
}

// 3. EDITAR ENTRADA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_entrada') {
    $idEdit = intval($_POST['id_entrada'] ?? 0);
    $producto = trim($_POST['producto'] ?? '');
    $cantidad = trim($_POST['cantidad'] ?? '');
    $proveedor = trim($_POST['proveedor'] ?? '');

    if ($idEdit > 0 && !empty($producto)) {
        try {
            $stmtUpd = $conexion->prepare("UPDATE registroentradas SET producto = ?, cantidad = ?, proveedor = ? WHERE id = ?");
            $stmtUpd->execute([$producto, $cantidad, $proveedor, $idEdit]);
            header("Location: indext.php?msg=editado");
            exit();
        } catch (Exception $e) {
            $mensajeError = "Error al actualizar la entrada: " . $e->getMessage();
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'eliminado') $mensajeExito = "Registro eliminado correctamente de Entradas, Catálogo y Panel de Control.";
    if ($_GET['msg'] === 'creado') $mensajeExito = "¡Nuevo producto agregado a Entradas, Catálogo y Panel!";
    if ($_GET['msg'] === 'editado') $mensajeExito = "Entrada actualizada correctamente.";
}

// Consultar registro de entradas
try {
    $stmtEntradas = $conexion->query("SELECT * FROM registroentradas ORDER BY id DESC");
    $listaEntradas = $stmtEntradas ? $stmtEntradas->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $listaEntradas = [];
}

// Preparar datos para las gráficas
$nombresProductos = [];
$cantidadesProductos = [];
$fechasEntradas = [];

foreach ($listaEntradas as $ent) {
    $nombresProductos[] = $ent['producto'];
    preg_match('/[0-9]+(\.[0-9]+)?/', $ent['cantidad'], $match);
    $cantidadesProductos[] = isset($match[0]) ? floatval($match[0]) : 0;
    $fechasEntradas[] = $ent['fecha'] ?? date('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes de Entradas - Inventario Escolar</title>
    <link href="./output.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
html { box-sizing: border-box; }
*, *::before, *::after { box-sizing: inherit; }
body { overflow-x: hidden; background: #f8fafc; font-family: inherit; }
#menuInventario {
  position: fixed !important; top: 0 !important; bottom: 0 !important; left: 0 !important; right: auto !important;
  width: min(320px, 86vw) !important; height: 100dvh !important; overflow-y: auto !important;
  border-radius: 0 14px 14px 0 !important; z-index: 9999 !important; background: #fff;
  box-shadow: 8px 0 28px rgba(0,0,0,.14);
}
</style>
</head>

<body class="bg-gray-50 text-gray-800">

    <div class="min-h-screen">
        <!-- ENCABEZADO SUPERIOR -->
        <header class="bg-white border-b border-gray-200 px-6 py-4 sticky top-0 z-40">
            <div class="flex justify-between items-center w-full">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <button onclick="toggleMenuInventario()" class="w-11 h-11 rounded-lg bg-purple-100 text-purple-700 text-2xl flex items-center justify-center hover:bg-purple-200 transition">☰</button>

                        <!-- MENÚ DESPLEGABLE LATERAL -->
                        <div id="menuInventario" class="hidden fixed inset-0 w-screen h-screen bg-white overflow-y-auto z-[9999]">
                            <div class="p-5 border-b border-gray-200 flex items-center justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 rounded-xl overflow-hidden border border-gray-200 bg-white flex items-center justify-center">
                                        <img src="./img/logo-instituto.jpg" alt="Logo" class="w-full h-full object-contain">
                                    </div>
                                    <div>
                                        <h1 class="font-bold text-gray-800">Inventario</h1>
                                        <p class="text-xs text-gray-500">Escolar</p>
                                    </div>
                                </div>
                                <button type="button" onclick="cerrarMenuInventario()" class="w-10 h-10 rounded-xl bg-gray-100 text-xl text-gray-700">×</button>
                            </div>
                             
                            <a href="alimentos.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">🏠 <span>Panel de Control</span></a>
                            <a href="catalogo.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">📦 <span>Catálogo</span></a>
                            <a href="indext.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 bg-purple-100 text-purple-700 font-semibold">⬆️ <span>Entradas</span></a>    
                            <a href="entradas.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">🍽️ <span>Menú</span></a>
                            <a href="index1.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">⬇️ <span>Salidas</span></a>
                            
                            <!-- CONFIGURACIÓN Y CERRAR SESIÓN AÑADIDOS AL MENÚ LATERAL -->
                            <button onclick="alert('⚙️ Configuración'); cerrarMenuInventario();" class="w-full flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition text-left">
                                ⚙️ <span>Configuración</span>
                            </button>
                            
                            <div class="border-t border-gray-200 mt-2">
                                <a href="index.php" onclick="cerrarMenuInventario();" class="w-full flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-red-50 hover:text-red-600 transition">
                                    🚪 <span>Cerrar sesión</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h2 class="text-xl font-bold">Reportes de Entradas</h2>
                        <p class="text-xs text-gray-500">Sistema de Control de Alimentos Escolares</p>
                    </div>
                </div>

                <!-- PERFIL Y CAMPANA DE NOTIFICACIONES -->
                <div style="display: flex; align-items: center; gap: 20px; position: relative;">
                    
                    <!-- CAMPANITA DE NOTIFICACIONES INTERACTIVA -->
                    <div style="position: relative;">
                        <div onclick="toggleNotificaciones(event)" style="position: relative; cursor: pointer; width: 38px; height: 38px; border-radius: 50%; background: #f8fafc; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;" class="hover:bg-gray-100">
                            <?php if ($totalAlertas > 0): ?>
                                <span style="background: #dc3545; color: white; border-radius: 50%; padding: 1px 6px; font-size: 10px; font-weight: bold; position: absolute; top: -3px; right: -3px;"><?php echo $totalAlertas; ?></span>
                            <?php endif; ?>
                            <span style="font-size: 18px;">🔔</span>
                        </div>

                        <!-- MENÚ DESPLEGABLE DE NOTIFICACIONES -->
                        <div id="dropdownNotificaciones" class="hidden" style="position: absolute; top: 50px; right: 0; width: 320px; background: white; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); z-index: 1000; padding: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 10px;">
                                <span style="font-size: 14px; font-weight: 700; color: #1e293b;">Notificaciones</span>
                                <span style="font-size: 11px; background: #f3f4f6; padding: 2px 8px; border-radius: 10px; color: #64748b; font-weight: 600;"><?php echo $totalAlertas; ?> alertas</span>
                            </div>

                            <div style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
                                <?php if ($totalAlertas == 0): ?>
                                    <p style="font-size: 12px; color: #64748b; text-align: center; padding: 15px 0;">No hay notificaciones pendientes.</p>
                                <?php else: ?>
                                    <!-- Alertas de Stock Bajo -->
                                    <?php foreach ($alertasStockBajo as $sb): ?>
                                        <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #dc2626;">
                                            <span style="font-size: 14px;">🔴</span>
                                            <div>
                                                <div style="font-size: 12px; font-weight: 700; color: #1e293b;">Stock bajo</div>
                                                <div style="font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($sb['producto']); ?>: <?php echo htmlspecialchars($sb['cantidad']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <!-- Alertas de Próximo a Vencer -->
                                    <?php foreach ($alertasVencimiento as $av): ?>
                                        <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #3b82f6;">
                                            <span style="font-size: 14px;">📅</span>
                                            <div>
                                                <div style="font-size: 12px; font-weight: 700; color: #1e293b;">Próximo a vencer</div>
                                                <div style="font-size: 11px; color: #64748b;">
                                                    <?php echo htmlspecialchars($av['producto']); ?>: 
                                                    <?php 
                                                        if ($av['dias'] < 0) {
                                                            echo "¡Vencido hace " . abs($av['dias']) . " día(s)!";
                                                        } elseif ($av['dias'] == 0) {
                                                            echo "Vence hoy";
                                                        } else {
                                                            echo "vence en " . $av['dias'] . " día(s)";
                                                        }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div onclick="toggleUserDropdown(event)" style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 5px 10px; border-radius: 8px;" class="hover:bg-gray-100">
                        <div style="width: 35px; height: 35px; border-radius: 50%; background-color: #6f42c1; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 15px;">
                            👤
                        </div>
                        <div style="text-align: left;">
                            <div style="font-size: 13px; font-weight: 700; color: #1e293b; line-height: 1.2;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                            <div style="font-size: 11px; color: #64748b; line-height: 1.2;"><?php echo htmlspecialchars($rolUsuario); ?></div>
                        </div>
                        <span style="font-size: 10px; color: #64748b; margin-left: 2px;">▼</span>
                    </div>

                    <!-- MENÚ FLOTANTE DEL USUARIO -->
                    <div id="userDropdownMenu" class="hidden" style="position: absolute; top: 55px; right: 0; width: 280px; background: white; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1000; padding: 15px;">
                        <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 10px;">
                            <div style="font-size: 14px; font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                            <div style="font-size: 12px; color: #64748b; word-break: break-all;"><?php echo htmlspecialchars($emailUsuario); ?></div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <a href="#" onclick="alert('Sección de Cuenta'); return false;" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">👤 <span>Mi cuenta</span></a>
                            <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">➕ <span>Agregar cuenta</span></a>
                            <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">🔄 <span>Cambiar cuenta</span></a>
                            <a href="#" onclick="alert('Sistema de Inventario Escolar - INPBF'); return false;" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">❓ <span>Ayuda</span></a>
                            <div style="border-top: 1px solid #f1f5f9; margin-top: 4px; padding-top: 4px;">
                                <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #dc2626; text-decoration: none; font-size: 13px;">🚪 <span>Cerrar sesión</span></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- CONTENIDO PRINCIPAL -->
        <main class="p-8">
            
            <?php if (!empty($mensajeExito)): ?>
                <div style="background:#e3f6ea;color:#16803c;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-weight:bold;">
                    <?php echo htmlspecialchars($mensajeExito); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($mensajeError)): ?>
                <div style="background:#fde8e8;color:#c81e1e;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-weight:bold;">
                    <?php echo htmlspecialchars($mensajeError); ?>
                </div>
            <?php endif; ?>

            <!-- SECCIÓN DE GRÁFICAS -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                    <h3 class="font-bold text-lg mb-4 text-gray-800">Entradas de alimentos</h3>
                    <div class="h-64 relative flex items-center justify-center">
                        <canvas id="graficaLinea"></canvas>
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                    <h3 class="font-bold text-lg mb-4 text-gray-800">Productos recibidos</h3>
                    <div class="h-64 relative flex items-center justify-center">
                        <canvas id="graficaDona"></canvas>
                    </div>
                </div>
            </div>

            <!-- TABLA DE REGISTRO DE ENTRADAS -->
            <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6">
                    <h3 class="font-bold text-lg text-gray-800">Registro de Entradas</h3>
                    <div class="flex items-center gap-3 w-full md:w-auto justify-end">
                        <input type="text" id="inputFiltrar" onkeyup="filtrarTablaEntradas()" placeholder="🔍 Filtrar..." class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                        <button type="button" onclick="abrirModalAgregar()" style="background-color: #059669 !important; color: #ffffff !important;" class="hover:bg-emerald-700 font-semibold px-4 py-2 rounded-lg text-sm transition flex items-center gap-1 shadow-sm cursor-pointer shrink-0">
                            + Agregar
                        </button>
                    </div>
                </div>

                <?php if (empty($listaEntradas)): ?>
                    <div class="py-8 text-center">
                        <p class="text-sm text-gray-500 mb-4">No hay entradas registradas en el sistema.</p>
                        <button type="button" onclick="abrirModalAgregar()" style="background-color: #059669 !important; color: #ffffff !important;" class="hover:bg-emerald-700 font-semibold px-5 py-2.5 rounded-lg text-sm transition shadow-sm inline-flex items-center gap-1">
                            + Agregar tu primer producto
                        </button>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse" id="tablaEntradas">
                            <thead>
                                <tr class="border-b border-gray-200 text-xs text-gray-500 uppercase bg-gray-50">
                                    <th class="p-3">Producto</th>
                                    <th class="p-3">Cantidad</th>
                                    <th class="p-3">Fecha</th>
                                    <th class="p-3">Hora</th>
                                    <th class="p-3">Proveedor</th>
                                    <th class="p-3">Responsable</th>
                                    <th class="p-3 text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm divide-y divide-gray-100">
                                <?php foreach ($listaEntradas as $ent): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="p-3 font-semibold text-gray-800"><?php echo htmlspecialchars($ent['producto']); ?></td>
                                        <td class="p-3 font-medium text-purple-600"><?php echo htmlspecialchars($ent['cantidad']); ?></td>
                                        <td class="p-3 text-gray-600"><?php echo htmlspecialchars($ent['fecha'] ?? ''); ?></td>
                                        <td class="p-3 text-gray-600"><?php echo htmlspecialchars($ent['hora'] ?? ''); ?></td>
                                        <td class="p-3 text-gray-600"><?php echo htmlspecialchars($ent['proveedor'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-gray-600"><?php echo htmlspecialchars($ent['responsable'] ?? ''); ?></td>
                                        <td class="p-3 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <button type="button" onclick="abrirModalEditar(<?php echo $ent['id']; ?>, '<?php echo htmlspecialchars($ent['producto']); ?>', '<?php echo htmlspecialchars($ent['cantidad']); ?>', '<?php echo htmlspecialchars($ent['proveedor'] ?? ''); ?>')" class="bg-blue-500 hover:bg-blue-600 text-white p-2 rounded-lg text-xs transition shadow-sm" title="Editar">
                                                    ✏️
                                                </button>
                                                <a href="indext.php?eliminar_id=<?php echo $ent['id']; ?>" onclick="return confirm('¿Estás segura de eliminar este registro? Se borrará de Entradas, del Catálogo y del Panel.');" class="bg-red-500 hover:bg-red-600 text-white p-2 rounded-lg text-xs transition shadow-sm" title="Eliminar">
                                                    🗑️
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- MODAL AGREGAR NUEVO PRODUCTO -->
    <div id="modalAgregar" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 relative my-8">
            <div class="flex justify-between items-center border-b pb-3 mb-4">
                <h3 class="text-lg font-bold text-gray-800">Agregar Nuevo Producto</h3>
                <button type="button" onclick="cerrarModalAgregar()" class="text-gray-400 hover:text-gray-600 text-xl font-bold">×</button>
            </div>
            <form method="POST" action="indext.php">
                <input type="hidden" name="accion" value="agregar_entrada">
                <div class="space-y-4 max-h-[70vh] overflow-y-auto pr-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Producto</label>
                        <input type="text" name="producto" required placeholder="Ej. Frijoles" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Categoría</label>
                        <select name="categoria" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-purple-600">
                            <option value="Granos">Granos</option>
                            <option value="Lácteos">Lácteos</option>
                            <option value="Proteínas">Proteínas</option>
                            <option value="Verduras">Verduras</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Cantidad</label>
                        <input type="text" name="cantidad" required placeholder="Ej. 50 L o 30 Kg" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Fecha</label>
                            <input type="date" name="fecha" value="<?php echo date('Y-m-d'); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Hora</label>
                            <input type="time" name="hora" value="<?php echo date('H:i'); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Proveedor</label>
                        <input type="text" name="proveedor" value="Ministerio de Educación" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Responsable</label>
                        <input type="text" name="responsable" value="<?php echo htmlspecialchars($nombreUsuario); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Ubicación</label>
                        <input type="text" name="ubicacion" value="Bodega Principal" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Fecha de Vencimiento</label>
                        <input type="date" name="vencimiento" value="<?php echo date('Y-m-d', strtotime('+6 months')); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                </div>
                <!-- BOTONES DE ACCIÓN DEL MODAL CON BOTÓN VERDE FORZADO -->
                <div class="mt-6 flex justify-end gap-3 border-t pt-4">
                    <button type="button" onclick="cerrarModalAgregar()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold px-4 py-2 rounded-lg text-sm transition">Cancelar</button>
                    <button type="submit" style="background-color: #059669 !important; color: #ffffff !important;" class="hover:bg-emerald-700 font-semibold px-5 py-2 rounded-lg text-sm transition shadow-sm cursor-pointer">Guardar Registro</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR ENTRADA -->
    <div id="modalEditar" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6 relative">
            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">✏️ Editar Entrada</h3>
            <form method="POST" action="indext.php">
                <input type="hidden" name="accion" value="editar_entrada">
                <input type="hidden" name="id_entrada" id="edit_id">
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Nombre del Producto</label>
                        <input type="text" name="producto" id="edit_producto" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Cantidad</label>
                        <input type="text" name="cantidad" id="edit_cantidad" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Proveedor</label>
                        <input type="text" name="proveedor" id="edit_proveedor" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="cerrarModalEditar()" class="bg-gray-100 text-gray-700 font-semibold px-4 py-2 rounded-lg text-sm">Cancelar</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-lg text-sm transition">Actualizar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleMenuInventario() {
            const menu = document.getElementById("menuInventario");
            if (menu) menu.classList.toggle("hidden");
        }
        function cerrarMenuInventario() {
            const menu = document.getElementById("menuInventario");
            if (menu) menu.classList.add("hidden");
        }

        function toggleUserDropdown(event) {
            event.stopPropagation();
            const dropdown = document.getElementById("userDropdownMenu");
            const notif = document.getElementById("dropdownNotificaciones");
            if (notif) notif.classList.add("hidden");
            if (dropdown) {
                dropdown.classList.toggle("hidden");
            }
        }

        // Función para mostrar/ocultar el menú de notificaciones de la campana
        function toggleNotificaciones(event) {
            event.stopPropagation();
            const notif = document.getElementById("dropdownNotificaciones");
            const dropdown = document.getElementById("userDropdownMenu");
            if (dropdown) dropdown.classList.add("hidden");
            if (notif) {
                notif.classList.toggle("hidden");
            }
        }

        window.addEventListener('click', () => {
            const dropdown = document.getElementById("userDropdownMenu");
            const notif = document.getElementById("dropdownNotificaciones");
            if (dropdown && !dropdown.classList.contains("hidden")) {
                dropdown.classList.add("hidden");
            }
            if (notif && !notif.classList.contains("hidden")) {
                notif.classList.add("hidden");
            }
        });

        // MODAL AGREGAR
        function abrirModalAgregar() {
            document.getElementById('modalAgregar').classList.remove('hidden');
        }
        function cerrarModalAgregar() {
            document.getElementById('modalAgregar').classList.add('hidden');
        }

        // MODAL EDITAR
        function abrirModalEditar(id, producto, cantidad, proveedor) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_producto').value = producto;
            document.getElementById('edit_cantidad').value = cantidad;
            document.getElementById('edit_proveedor').value = proveedor;
            document.getElementById('modalEditar').classList.remove('hidden');
        }
        function cerrarModalEditar() {
            document.getElementById('modalEditar').classList.add('hidden');
        }

        // FILTRAR TABLA EN TIEMPO REAL
        function filtrarTablaEntradas() {
            const texto = document.getElementById('inputFiltrar').value.toLowerCase();
            const filas = document.querySelectorAll('#tablaEntradas tbody tr');

            filas.forEach(fila => {
                const prodTexto = fila.cells[0].innerText.toLowerCase();
                const provTexto = fila.cells[4].innerText.toLowerCase();
                if (prodTexto.includes(texto) || provTexto.includes(texto)) {
                    fila.style.display = "";
                } else {
                    fila.style.display = "none";
                }
            });
        }

        // GRÁFICAS DINÁMICAS
        const labelsLinea = <?php echo json_encode(array_values($fechasEntradas)); ?>;
        const dataLinea = <?php echo json_encode(array_values($cantidadesProductos)); ?>;

        const ctxLinea = document.getElementById('graficaLinea').getContext('2d');
        new Chart(ctxLinea, {
            type: 'line',
            data: {
                labels: labelsLinea,
                datasets: [{
                    label: 'Cantidad de Entradas',
                    data: dataLinea,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });

        const labelsDona = <?php echo json_encode(array_values($nombresProductos)); ?>;
        const dataDona = <?php echo json_encode(array_values($cantidadesProductos)); ?>;

        const ctxDona = document.getElementById('graficaDona').getContext('2d');
        new Chart(ctxDona, {
            type: 'doughnut',
            data: {
                labels: labelsDona,
                datasets: [{
                    data: dataDona,
                    backgroundColor: ['#3b82f6', '#f97316', '#a855f7', '#10b981', '#ef4444', '#06b6d4'],
                    borderWidth: 2
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
        });
    </script>
</body>
</html>