<?php
session_start();
include 'conexion.php';

// Validación estricta de sesión
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['nombre'])) {
    header("Location: index.php");
    exit();
}

$nombreUsuario = $_SESSION['nombre'];
$rolUsuario = $_SESSION['rol'] ?? 'Encargado de inventario';
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

// Consultas para las alertas de la campanita (Stock bajo y Vencimientos exactos)
$alertasStockBajo = [];
$alertasVencimiento = [];

try {
    $stmtQueryBajo = $conexion->query("SELECT id, producto, cantidad, estado FROM catalogo");
    if ($stmtQueryBajo) {
        $todosCatalogo = $stmtQueryBajo->fetchAll(PDO::FETCH_ASSOC);
        foreach ($todosCatalogo as $prod) {
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

    $stmtQueryVence = $conexion->query("SELECT producto, vencimiento FROM catalogo WHERE vencimiento IS NOT NULL AND vencimiento != ''");
    if ($stmtQueryVence) {
        $todosProds = $stmtQueryVence->fetchAll(PDO::FETCH_ASSOC);
        $hoy = new DateTime();
        $hoy->setTime(0, 0, 0);

        foreach ($todosProds as $p) {
            if (!empty($p['vencimiento'])) {
                $fechaVenc = new DateTime($p['vencimiento']);
                $fechaVenc->setTime(0, 0, 0);
                
                $diferencia = $hoy->diff($fechaVenc);
                $diasRestantes = (int)$diferencia->format('%r%a');
                
                if ($diasRestantes <= 30) {
                    $alertasVencimiento[] = [
                        'producto' => $p['producto'],
                        'dias' => $diasRestantes
                    ];
                }
            }
        }
    }
} catch (Exception $e) {}

$totalAlertas = count($alertasStockBajo) + count($alertasVencimiento);

$mensajeExito = "";
$mensajeError = "";

if (isset($_GET['eliminar_id'])) {
    $idEliminar = intval($_GET['eliminar_id']);
    if ($idEliminar > 0) {
        try {
            $stmtDel = $conexion->prepare("DELETE FROM catalogo WHERE id = ?");
            $stmtDel->execute([$idEliminar]);
            header("Location: catalogo.php?msg=eliminado");
            exit();
        } catch (Exception $e) {
            $mensajeError = "Error al eliminar producto: " . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'nuevo_producto') {
    $producto = trim($_POST['producto'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'Granos');
    $cantidadVal = floatval($_POST['cantidad'] ?? 0);
    $unidad = trim($_POST['unidad'] ?? 'Libras');
    $vencimiento = !empty($_POST['vencimiento']) ? $_POST['vencimiento'] : NULL;
    $ubicacion = trim($_POST['ubicacion'] ?? 'Bodega Principal');
    $proveedor = trim($_POST['proveedor'] ?? 'Ministerio de Educación');
    $fechaActual = date('Y-m-d');
    $horaActual = date('H:i:s');

    if (!empty($producto) && $cantidadVal > 0) {
        try {
            $cantidadFinal = $cantidadVal . ' ' . $unidad;
            $estado = ($cantidadVal <= 10) ? 'Stock Bajo' : 'Normal';

            $resId = $conexion->query("SELECT id FROM catalogo ORDER BY id DESC LIMIT 1");
            $ultimoId = $resId ? $resId->fetch(PDO::FETCH_ASSOC) : false;
            $siguienteId = $ultimoId ? $ultimoId['id'] + 1 : 1;
            $codigo = "P" . str_pad($siguienteId, 3, "0", STR_PAD_LEFT);

            $stmtIns = $conexion->prepare("INSERT INTO catalogo (codigo, producto, categoria, cantidad, estado, ubicacion, vencimiento) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([$codigo, $producto, $categoria, $cantidadFinal, $estado, $ubicacion, $vencimiento]);

            $stmtEnt = $conexion->prepare("INSERT INTO registroentradas (producto, cantidad, fecha, hora, proveedor, responsable) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtEnt->execute([$producto, $cantidadFinal, $fechaActual, $horaActual, $proveedor, $nombreUsuario]);

            header("Location: catalogo.php?msg=creado");
            exit();
        } catch (Exception $e) {
            $mensajeError = "Error al registrar el producto: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Por favor completa el nombre y una cantidad válida.";
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'eliminado') $mensajeExito = "Producto eliminado correctamente del catálogo.";
    if ($_GET['msg'] === 'creado') $mensajeExito = "¡Nuevo producto agregado al Catálogo, Panel y Registro de Entradas!";
}

try {
    $stmtCatalogo = $conexion->query("SELECT * FROM catalogo ORDER BY id DESC");
    $listaCatalogo = $stmtCatalogo ? $stmtCatalogo->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $listaCatalogo = [];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Productos - Inventario Escolar</title>
    <link href="./output.css" rel="stylesheet">
<style>
html { box-sizing: border-box; }
*, *::before, *::after { box-sizing: inherit; }
body { overflow-x: hidden; background: #f7f5fa; font-family: Arial, Helvetica, sans-serif; }
#menuInventario {
  position: fixed !important; top: 0 !important; bottom: 0 !important; left: 0 !important; right: auto !important;
  width: min(320px, 86vw) !important; height: 100dvh !important; overflow-y: auto !important;
  border-radius: 0 14px 14px 0 !important; z-index: 9999 !important; background: #fff;
  box-shadow: 8px 0 28px rgba(0,0,0,.14);
}

/* Estilos personalizados con tonos lila/pastel muy suaves y elegantes */
.card-estetica {
  background: rgba(250, 245, 255, 0.9);
  border: 1px solid #f3e8ff;
  box-shadow: 0 10px 30px rgba(126, 34, 206, 0.04);
  backdrop-filter: blur(8px);
}
.tabla-personalizada tr {
  background-color: rgba(248, 245, 255, 0.6);
  border-bottom: 1px solid #f3e8ff;
}
.tabla-personalizada tr:hover {
  background-color: rgba(243, 232, 255, 0.8);
}
.tabla-personalizada th {
  background-color: #f3e8ff;
  color: #6b21a8;
  font-weight: 700;
}

:root{--primary:#6f42c1;--primary-dark:#5a32a3;--bg-light:#f8fafc;--text-main:#334155;--border-color:#cbd5e1;}
.ayuda-body{background:#f8fafc;color:var(--text-main)}
.ayuda-intro{background:linear-gradient(135deg,#ede9fe,#f3e8ff 55%,#e0e7ff);color:#3b0764;border:1px solid #d8b4fe;border-radius:20px;padding:30px;margin-bottom:24px;position:relative}
.ayuda-intro h1{font-size:clamp(24px,3.5vw,34px);margin:0 0 10px;color:#4c1d95}
.ayuda-intro p{margin:0;line-height:1.6;color:#5b21b6;font-size:14px;max-width:700px}
.ayuda-layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr);gap:22px}
.ayuda-panel{background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 4px 15px rgba(0,0,0,0.05)}
.ayuda-panel h2{font-size:18px;margin:0 0 8px;color:#1e293b}
.ayuda-muted{color:#64748b;font-size:13px;line-height:1.55}
.ayuda-issues{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:18px 0}
.ayuda-issue{background:#f8fafc;border:1px solid #cbd5e1;border-radius:12px;padding:14px;text-align:left;cursor:pointer;min-height:75px;color:#334155;font-size:13px;font-weight:700;transition:all .2s}
.ayuda-issue span{display:block;font-size:20px;margin-bottom:6px}
.ayuda-issue[aria-pressed="true"]{border:2px solid #7c3aed;background:#ede9fe;color:#4c1d95}
.ayuda-solution{background:#f1f5f9;border:1px solid #cbd5e1;border-radius:12px;padding:17px;margin-top:16px}
.ayuda-solution h3{margin:0 0 10px;font-size:16px;color:#1e293b}
.ayuda-solution ol{padding-left:21px;line-height:1.65;margin:8px 0;font-size:13px;color:#334155}
.ayuda-form label{display:block;font-weight:700;font-size:13px;margin:14px 0 6px;color:#1e293b}
.ayuda-form select,.ayuda-form input,.ayuda-form textarea{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;background:#fff;color:#334155;font-size:13px}
.ayuda-form textarea{min-height:95px;resize:vertical}
.ayuda-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.ayuda-status{font-size:12px;line-height:1.5;margin-top:10px;color:#4c1d95}
.ayuda-faq{border-top:1px solid #e2e8f0;padding:12px 0}
.ayuda-faq summary{font-weight:700;cursor:pointer;font-size:13px;color:#1e293b}
.ayuda-faq p{color:#64748b;line-height:1.55;font-size:13px;margin:6px 0 0}
@media(max-width:760px){.ayuda-layout{grid-template-columns:1fr}.ayuda-issues{grid-template-columns:1fr}}
</style>
</head>

<body class="bg-[#f7f5fa] text-gray-800">

    <div class="min-h-screen">
        <!-- ENCABEZADO SUPERIOR CON USUARIO Y CAMPANA -->
        <header class="bg-white border-b border-gray-200 px-6 py-4 sticky top-0 z-40 shadow-sm">
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
                            <a href="catalogo.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 bg-purple-100 text-purple-700 font-semibold">📦 <span>Catálogo</span></a>
                            <a href="indext.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">⬆️ <span>Entradas</span></a>    
                            <a href="entradas.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">🍽️ <span>Menú</span></a>
                            <a href="index1.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">⬇️ <span>Salidas</span></a>
                            
                            <!-- AYUDA EN EL MENÚ -->
                            <a href="#" onclick="abrirCentroAyuda(); cerrarMenuInventario(); return false;" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">
                                ❓ <span class="font-semibold text-purple-700">Ayuda</span>
                            </a>
                            
                            <div class="border-t border-gray-200 mt-2">
                                <a href="index.php" onclick="cerrarMenuInventario();" class="w-full flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-red-50 hover:text-red-600 transition">
                                    🚪 <span>Cerrar sesión</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h2 class="text-xl font-extrabold text-purple-950">Inventario Escolar</h2>
                        <p class="text-xs text-purple-900 font-medium">Gestiona el inventario de alimentos</p>
                    </div>
                </div>

                <!-- PERFIL DEL USUARIO Y CAMPANA -->
                <div style="display: flex; align-items: center; gap: 20px; position: relative;">
                    
                    <!-- CAMPANITA DE NOTIFICACIONES INTERACTIVA -->
                    <div style="position: relative;">
                        <div onclick="toggleNotificaciones(event)" style="position: relative; cursor: pointer; width: 38px; height: 38px; border-radius: 50%; background: #f8fafc; display: flex; align-items: center; justify-content: center; border: 1px solid #cbd5e1;" class="hover:bg-gray-100">
                            <?php if ($totalAlertas > 0): ?>
                                <span style="background: #dc3545; color: white; border-radius: 50%; padding: 1px 6px; font-size: 10px; font-weight: bold; position: absolute; top: -3px; right: -3px;"><?php echo $totalAlertas; ?></span>
                            <?php endif; ?>
                            <span style="font-size: 18px;">🔔</span>
                        </div>

                        <!-- MENÚ DESPLEGABLE DE NOTIFICACIONES -->
                        <div id="dropdownNotificaciones" class="hidden" style="position: absolute; top: 50px; right: 0; width: 320px; background: white; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); z-index: 1000; padding: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 10px;">
                                <span style="font-size: 14px; font-weight: 700; color: #1e293b;">Notificaciones</span>
                                <span style="font-size: 11px; background: #f3f4f6; padding: 2px 8px; border-radius: 10px; color: #64748b; font-weight: 600;"><?php echo $totalAlertas; ?> alertas</span>
                            </div>

                            <div style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
                                <?php if ($totalAlertas == 0): ?>
                                    <p style="font-size: 12px; color: #64748b; text-align: center; padding: 15px 0;">No hay notificaciones pendientes.</p>
                                <?php else: ?>
                                    <?php foreach ($alertasStockBajo as $sb): ?>
                                        <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #dc2626;">
                                            <span style="font-size: 14px;">🔴</span>
                                            <div>
                                                <div style="font-size: 12px; font-weight: 700; color: #1e293b;">Stock bajo</div>
                                                <div style="font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($sb['producto']); ?>: <?php echo htmlspecialchars($sb['cantidad']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

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

                    <div onclick="toggleUserDropdown(event)" style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 5px 10px; border-radius: 8px;" class="hover:bg-purple-100 transition">
                        <div style="width: 35px; height: 35px; border-radius: 50%; background-color: #6f42c1; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 15px;">
                            👤
                        </div>
                        <div style="text-align: left;">
                            <div style="font-size: 13px; font-weight: 700; color: #1e293b; line-height: 1.2;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                            <div style="font-size: 11px; color: #64748b; line-height: 1.2;"><?php echo htmlspecialchars($rolUsuario); ?></div>
                        </div>
                        <span style="font-size: 10px; color: #64748b; margin-left: 2px;">▼</span>
                    </div>

                    <div id="userDropdownMenu" class="hidden" style="position: absolute; top: 55px; right: 0; width: 280px; background: white; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1000; padding: 15px;">
                        <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 10px;">
                            <div style="font-size: 14px; font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                            <div style="font-size: 12px; color: #64748b; word-break: break-all;"><?php echo htmlspecialchars($emailUsuario); ?></div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <a href="#" onclick="alert('Sección de Cuenta'); return false;" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">👤 <span>Mi cuenta</span></a>
                            <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">➕ <span>Agregar cuenta</span></a>
                            <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">🔄 <span>Cambiar cuenta</span></a>
                            <a href="#" onclick="abrirCentroAyuda(); return false;" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">❓ <span class="font-semibold text-purple-700">Ayuda</span></a>
                            <div style="border-top: 1px solid #f1f5f9; margin-top: 4px; padding-top: 4px;">
                                <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #dc2626; text-decoration: none; font-size: 13px;">🚪 <span>Cerrar sesión</span></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- CONTENIDO DEL CATÁLOGO -->
        <main class="p-8 max-w-7xl mx-auto">

            <?php if (!empty($mensajeExito)): ?>
                <div style="background:#d1fae5;color:#065f46;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-weight:bold;border:1px solid #a7f3d0;">
                    <?php echo htmlspecialchars($mensajeExito); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($mensajeError)): ?>
                <div style="background:#fee2e2;color:#991b1b;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-weight:bold;border:1px solid #fecaca;">
                    <?php echo htmlspecialchars($mensajeError); ?>
                </div>
            <?php endif; ?>

            <div class="card-estetica rounded-2xl p-6 md:p-8">
                
                <h3 class="font-extrabold text-2xl mb-5 flex items-center gap-3 text-purple-950">
                    <span class="w-10 h-10 rounded-xl bg-purple-200 text-purple-800 flex items-center justify-center text-xl shadow-inner">📦</span> Catálogo de Productos
                </h3>

                <!-- BARRA DE BÚSQUEDA, FILTRO Y BOTÓN NUEVO PRODUCTO -->
                <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6 bg-purple-100/60 p-4 rounded-xl border border-purple-200">
                    <div class="w-full md:w-1/2 relative">
                        <input type="text" id="inputBuscar" onkeyup="filtrarTabla()" placeholder="🔎 Buscar productos..." class="w-full border border-purple-300 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-purple-600 bg-white shadow-sm">
                    </div>
                    <div class="flex items-center gap-3 w-full md:w-auto justify-end">
                        <select id="selectCategoria" onchange="filtrarTabla()" class="border border-purple-300 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-purple-600 shadow-sm font-medium text-purple-950">
                            <option value="">Todas las categorías</option>
                            <option value="Granos">Granos</option>
                            <option value="Lácteos">Lácteos</option>
                            <option value="Proteínas">Proteínas</option>
                            <option value="Verduras">Verduras</option>
                        </select>
                        <button type="button" onclick="abrirModalNuevo()" style="background-color: #7c3aed !important; color: #ffffff !important;" class="hover:bg-purple-800 font-bold px-5 py-2.5 rounded-xl text-sm transition flex items-center gap-1 shadow-md cursor-pointer shrink-0">
                            + Nuevo Producto
                        </button>
                    </div>
                </div>

                <!-- TABLA DE PRODUCTOS -->
                <?php if (empty($listaCatalogo)): ?>
                    <p class="text-sm text-purple-900 py-8 text-center font-medium">No hay productos registrados en el catálogo todavía.</p>
                <?php else: ?>
                    <div class="overflow-x-auto rounded-xl border border-purple-300 shadow-sm">
                        <table class="w-full text-left border-collapse tabla-personalizada" id="tablaCatalogo">
                            <thead>
                                <tr class="text-xs uppercase tracking-wider">
                                    <th class="p-3.5 pl-4">Código</th>
                                    <th class="p-3.5">Producto</th>
                                    <th class="p-3.5">Categoría</th>
                                    <th class="p-3.5">Cantidad</th>
                                    <th class="p-3.5">Estado</th>
                                    <th class="p-3.5">Ubicación</th>
                                    <th class="p-3.5">Vencimiento</th>
                                    <th class="p-3.5 text-center pr-4">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <?php foreach ($listaCatalogo as $prod): ?>
                                    <tr class="transition">
                                        <td class="p-3.5 pl-4 font-bold text-purple-900 text-xs"><?php echo htmlspecialchars($prod['codigo']); ?></td>
                                        <td class="p-3.5">
                                            <div class="font-extrabold text-purple-950"><?php echo htmlspecialchars($prod['producto']); ?></div>
                                            <div class="text-[11px] text-purple-800 font-medium">Inventario Escolar</div>
                                        </td>
                                        <td class="p-3.5 text-purple-900 font-medium"><?php echo htmlspecialchars($prod['categoria']); ?></td>
                                        <td class="p-3.5 font-bold text-purple-950"><?php echo htmlspecialchars($prod['cantidad']); ?></td>
                                        <td class="p-3.5">
                                            <?php 
                                                preg_match('/[0-9]+(\.[0-9]+)?/', $prod['cantidad'], $matchCant);
                                                $valCantNum = isset($matchCant[0]) ? floatval($matchCant[0]) : 99;
                                                $esBajo = (strcasecmp($prod['estado'], 'Stock Bajo') === 0 || $valCantNum <= 10);
                                            ?>
                                            <span class="px-3 py-1 text-xs rounded-full font-bold shadow-sm inline-block <?php echo $esBajo ? 'bg-red-100 text-red-700 border border-red-300' : 'bg-emerald-100 text-emerald-800 border border-emerald-300'; ?>">
                                                ✓ <?php echo $esBajo ? 'Stock Bajo' : htmlspecialchars($prod['estado']); ?>
                                            </span>
                                        </td>
                                        <td class="p-3.5 text-purple-900 font-medium"><?php echo htmlspecialchars($prod['ubicacion']); ?></td>
                                        <td class="p-3.5 text-purple-900 font-medium"><?php echo htmlspecialchars($prod['vencimiento'] ?? 'N/A'); ?></td>
                                        <td class="p-3.5 text-center pr-4">
                                            <div class="flex items-center justify-center gap-2">
                                                <button onclick="verDetalle('<?php echo htmlspecialchars($prod['codigo']); ?>')" class="bg-purple-200 hover:bg-purple-300 text-purple-900 font-bold px-3 py-1.5 rounded-lg text-xs transition shadow-sm">
                                                    Ver
                                                </button>
                                                <a href="catalogo.php?eliminar_id=<?php echo $prod['id']; ?>" onclick="return confirm('¿Estás segura de que deseas eliminar este producto del catálogo?');" class="bg-red-100 hover:bg-red-200 text-red-700 p-1.5 rounded-lg text-sm transition shadow-sm" title="Eliminar producto">
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

    <!-- MODAL PARA AGREGAR NUEVO PRODUCTO -->
    <div id="modalNuevo" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 relative border border-slate-200">
            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-100 pb-2">+ Nuevo Producto en Catálogo</h3>
            <form method="POST" action="catalogo.php">
                <input type="hidden" name="accion" value="nuevo_producto">
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Nombre del Producto</label>
                        <input type="text" name="producto" required placeholder="Ej. Frijoles" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Cantidad</label>
                            <input type="number" step="0.01" name="cantidad" required placeholder="Ej. 5" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Unidad</label>
                            <input type="text" name="unidad" value="Libras" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                        </div>
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
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Ubicación</label>
                        <input type="text" name="ubicacion" value="bodega3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Fecha de Vencimiento</label>
                        <input type="date" name="vencimiento" value="2026-11-19" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-purple-600">
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="cerrarModalNuevo()" class="bg-gray-100 text-gray-700 font-semibold px-4 py-2 rounded-lg text-sm hover:bg-gray-200 transition">Cancelar</button>
                    <button type="submit" style="background-color: #7c3aed !important; color: #ffffff !important;" class="hover:bg-purple-800 font-semibold px-4 py-2 rounded-lg text-sm transition cursor-pointer">Guardar Producto</button>
                </div>
            </form>
        </div>
    </div>

    <!-- VENTANA MODAL DEL CENTRO DE AYUDA -->
    <div id="modalCentroAyuda" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[10000] flex items-center justify-center p-4 overflow-y-auto ayuda-body">
        <div class="bg-white w-full max-w-5xl rounded-2xl shadow-2xl overflow-hidden border border-gray-300 my-auto">
            
            <header style="position:relative; z-index:20; background:#f3e8ff; border-bottom:1px solid #d8b4fe; padding:16px 5%; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; min-height:78px;">
                <div style="font-size:20px; font-weight:800; color:#5b21b6;">
                    Inventario Escolar 
                    <small style="display:block; font-size:12px; font-weight:500; color:#6b7280; margin-top:4px;">Centro de ayuda y soluciones</small>
                </div>
                <button type="button" onclick="cerrarCentroAyuda()" style="background:#ffffff!important; color:#5b21b6!important; border:2px solid #c084fc!important; box-shadow:0 4px 14px rgba(91,33,182,0.15)!important; white-space:nowrap; min-height:42px; padding:10px 16px; border-radius:12px; font-weight:700; cursor:pointer; font-size:13px;" class="transition hover:bg-purple-50">
                    ← Volver al catálogo
                </button>
            </header>

            <main style="max-width:1280px; margin:0 auto; padding:22px 4% 30px;">
                <section class="ayuda-intro">
                    <h1>¿En qué podemos ayudarte?</h1>
                    <p>Selecciona el problema para ver una guía de solución. Si los pasos no funcionan, describe lo ocurrido y prepara una consulta para el equipo de soporte.</p>
                    
                    <button type="button" onclick="cerrarCentroAyuda()" style="position:absolute; right:20px; top:24px; z-index:5; background:#ffffff; color:#5b21b6; border:1px solid #c084fc; padding:10px 16px; border-radius:12px; font-weight:700; font-size:13px; cursor:pointer; box-shadow:0 3px 10px rgba(91,33,182,0.1);" class="hover:bg-purple-50 transition">
                        ← Volver al catálogo
                    </button>
                </section>

                <div class="ayuda-layout">
                    <section class="ayuda-panel">
                        <h2>Elige el problema</h2>
                        <p class="ayuda-muted">Pulsa una opción y aquí aparecerán los pasos recomendados.</p>
                        
                        <div class="ayuda-issues" id="issues">
                            <button class="ayuda-issue" data-issue="guardar" aria-pressed="true"><span>💾</span>No puedo guardar cambios</button>
                            <button class="ayuda-issue" data-issue="productos" aria-pressed="false"><span>📦</span>Problemas con productos</button>
                            <button class="ayuda-issue" data-issue="cuenta" aria-pressed="false"><span>👤</span>Problemas con mi cuenta</button>
                            <button class="ayuda-issue" data-issue="alertas" aria-pressed="false"><span>🔔</span>No aparecen las alertas</button>
                            <button class="ayuda-issue" data-issue="acceso" aria-pressed="false"><span>🔐</span>No puedo entrar al sistema</button>
                            <button class="ayuda-issue" data-issue="otro" aria-pressed="false"><span>🛠️</span>Otro problema</button>
                        </div>

                        <div class="ayuda-solution" id="solution" aria-live="polite">
                            <h3 id="solutionTitle">💾 No puedo guardar cambios</h3>
                            <div id="solutionBody"></div>
                        </div>
                    </section>

                    <section class="ayuda-panel">
                        <h2>Enviar una consulta</h2>
                        <p class="ayuda-muted">Cuéntanos qué estabas intentando hacer y qué ocurrió en el catálogo.</p>
                        
                        <form id="helpForm" class="ayuda-form">
                            <label for="topic">Tipo de problema</label>
                            <select id="topic" required>
                                <option value="">Selecciona una opción</option>
                                <option>No puedo guardar cambios</option>
                                <option>Problemas con productos</option>
                                <option>Problemas con mi cuenta</option>
                                <option>No aparecen las alertas</option>
                                <option>No puedo entrar al sistema</option>
                                <option>Otro problema</option>
                            </select>

                            <label for="detail">¿Qué sucede?</label>
                            <textarea id="detail" required minlength="8" placeholder="Ejemplo: no puedo eliminar un producto del catálogo..."></textarea>

                            <label for="contact">Correo o forma de contacto (opcional)</label>
                            <input id="contact" type="text" placeholder="Tu correo, si deseas incluirlo">

                            <div class="ayuda-actions">
                                <button style="background:#7c3aed; color:#ffffff; border:0; border-radius:10px; padding:10px 14px; font-weight:700; cursor:pointer; font-size:13px;" type="submit" class="hover:bg-purple-800 transition">Preparar consulta</button>
                                <button style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:10px; padding:10px 14px; font-weight:700; cursor:pointer; font-size:13px;" type="button" id="clearForm" class="hover:bg-slate-200 transition">Limpiar</button>
                            </div>

                            <div class="ayuda-status" id="formStatus" role="status"></div>

                            <div class="ayuda-actions" id="sendActions" hidden>
                                <a style="background:#25D366; color:#fff; text-decoration:none; border-radius:10px; padding:10px 14px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:6px;" id="whatsappLink" href="#" target="_blank" rel="noopener">Abrir WhatsApp</a>
                                <button style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:10px; padding:10px 14px; font-weight:700; cursor:pointer; font-size:13px;" type="button" id="copyMessage">Copiar mensaje</button>
                            </div>
                        </form>
                    </section>
                </div>
            </main>
            <footer style="text-align:center; padding:16px; color:#6b7280; font-size:12px; background:#f3e8ff; border-top:1px solid #d8b4fe;">Inventario Escolar · Centro de ayuda</footer>
        </div>
    </div>

    <!-- BOTÓN FLOTANTE DE WHATSAPP FIJO -->
    <button type="button" onclick="abrirCentroAyuda()" class="fixed bottom-6 right-6 z-[95] flex items-center gap-2 px-5 py-3 rounded-full bg-[#d1fae5] text-[#065f46] font-extrabold shadow-2xl transition hover:scale-105 hover:bg-[#a7f3d0] cursor-pointer border border-[#6ee7b7]" style="box-shadow:0 8px 25px rgba(5,150,105,0.25)">
        <span class="flex items-center justify-center w-6 h-6 rounded-full bg-white text-[#25D366] text-base font-black">💬</span> WhatsApp
    </button>

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

        function toggleNotificaciones(event) {
            event.stopPropagation();
            const notif = document.getElementById("dropdownNotificaciones");
            const dropdown = document.getElementById("userDropdownMenu");
            if (dropdown) dropdown.classList.add("hidden");
            if (notif) {
                notif.classList.toggle("hidden");
            }
        }

        function abrirCentroAyuda() {
            const dropdown = document.getElementById("userDropdownMenu");
            if (dropdown) dropdown.classList.add("hidden");
            const modal = document.getElementById("modalCentroAyuda");
            if (modal) modal.classList.remove("hidden");
        }

        function cerrarCentroAyuda() {
            const modal = document.getElementById("modalCentroAyuda");
            if (modal) modal.classList.add("hidden");
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

        const helpData = {
            guardar:{title:"💾 No puedo guardar cambios",steps:[
                "Comprueba que todos los campos obligatorios del producto estén completos.",
                "Pulsa Guardar una sola vez y espera la recarga con confirmación.",
                "Verifica que el servidor MySQL en XAMPP esté encendido."
            ]},
            productos:{title:"📦 Problemas con productos",steps:[
                "Revisa que el nombre del producto y la cantidad sean válidos.",
                "Comprueba que el filtro o buscador no esté ocultando el producto."
            ]},
            cuenta:{title:"👤 Problemas con mi cuenta",steps:[
                "Verifica tu sesión activa en el sistema escolar.",
                "Cierra sesión y vuelve a ingresar si experimentas bloqueos."
            ]},
            alertas:{title:"🔔 No aparecen las alertas",steps:[
                "Verifica que el stock sea de 10 unidades o menos.",
                "Comprueba las fechas de vencimiento de los productos."
            ]},
            acceso:{title:"🔐 No puedo entrar al sistema",steps:[
                "Comprueba que estés abriendo catalogo.php en tu servidor local.",
                "Verifica que la base de datos esté conectada correctamente."
            ]},
            otro:{title:"🛠️ Otro problema",steps:[
                "Describe detalladamente el problema que experimentas en el catálogo.",
                "Usa el formulario para preparar tu consulta y enviarla por WhatsApp."
            ]}
        };

        function renderIssue(key) {
            const item = helpData[key] || helpData.otro;
            document.getElementById("solutionTitle").textContent = item.title;
            const ol = document.createElement("ol");
            item.steps.forEach(step => {
                const li = document.createElement("li");
                li.textContent = step;
                ol.appendChild(li);
            });
            document.getElementById("solutionBody").replaceChildren(ol);
            
            document.querySelectorAll(".ayuda-issue").forEach(btn => {
                btn.setAttribute("aria-pressed", String(btn.dataset.issue === key));
            });
            
            const map = {guardar: "No puedo guardar cambios", productos: "Problemas con productos", cuenta: "Problemas con mi cuenta", alertas: "No aparecen las alertas", acceso: "No puedo entrar al sistema", otro: "Otro problema"};
            document.getElementById("topic").value = map[key] || "";
        }

        document.querySelectorAll(".ayuda-issue").forEach(btn => {
            btn.addEventListener("click", () => renderIssue(btn.dataset.issue));
        });

        const helpForm = document.getElementById("helpForm");
        if (helpForm) {
            helpForm.addEventListener("submit", e => {
                e.preventDefault();
                const topic = document.getElementById("topic").value.trim();
                const detail = document.getElementById("detail").value.trim();
                const contact = document.getElementById("contact").value.trim();
                const status = document.getElementById("formStatus");
                const actions = document.getElementById("sendActions");
                
                if (!topic || detail.length < 8) {
                    status.textContent = "Selecciona el problema y escribe al menos 8 caracteres.";
                    actions.hidden = true;
                    return;
                }
                
                let preparedMessage = "Hola, necesito ayuda con el Catálogo de Inventario Escolar.\nProblema: " + topic + "\nDetalle: " + detail + (contact ? "\nContacto: " + contact : "");
                document.getElementById("whatsappLink").href = "https://wa.me/5030000000?text=" + encodeURIComponent(preparedMessage);
                status.textContent = "Consulta lista. Haz clic en Abrir WhatsApp:";
                actions.hidden = false;
            });
        }

        document.getElementById("clearForm")?.addEventListener("click", () => {
            document.getElementById("helpForm").reset();
            document.getElementById("formStatus").textContent = "Formulario limpio.";
            document.getElementById("sendActions").hidden = true;
        });

        renderIssue("guardar");

        function verDetalle(codigo) {
            window.location.href = "detalle.php?codigo=" + encodeURIComponent(codigo);
        }

        function abrirModalNuevo() {
            document.getElementById('modalNuevo').classList.remove('hidden');
        }
        function cerrarModalNuevo() {
            document.getElementById('modalNuevo').classList.add('hidden');
        }

        function filtrarTabla() {
            const texto = document.getElementById('inputBuscar').value.toLowerCase();
            const catSelec = document.getElementById('selectCategoria').value.toLowerCase();
            const filas = document.querySelectorAll('#tablaCatalogo tbody tr');

            filas.forEach(fila => {
                const prodTexto = fila.cells[1].innerText.toLowerCase();
                const catTexto = fila.cells[2].innerText.toLowerCase();

                const coincideTexto = prodTexto.includes(texto);
                const coincideCat = catSelec === "" || catTexto.includes(catSelec);

                if (coincideTexto && coincideCat) {
                    fila.style.display = "";
                } else {
                    fila.style.display = "none";
                }
            });
        }
    </script>
</body>
</html>