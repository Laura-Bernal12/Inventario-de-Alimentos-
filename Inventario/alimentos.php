<?php
session_start();
include 'conexion.php';

// Validación estricta: Si no hay una sesión activa, redirige al index
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['nombre'])) {
    header("Location: index.php");
    exit();
}

// Datos del usuario autenticado
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

$mensajeError = "";
$mensajeExito = "";

// 1. ELIMINAR PRODUCTO DESDE EL PANEL
if (isset($_GET['eliminar_id'])) {
    $idEliminar = intval($_GET['eliminar_id']);
    if ($idEliminar > 0) {
        try {
            $stmtGet = $conexion->prepare("SELECT producto FROM registroentradas WHERE id = ?");
            $stmtGet->execute([$idEliminar]);
            $reg = $stmtGet->fetch(PDO::FETCH_ASSOC);

            if ($reg) {
                $nombreProd = $reg['producto'];

                $conexion->beginTransaction();

                $stmtDelEntrada = $conexion->prepare("DELETE FROM registroentradas WHERE id = ?");
                $stmtDelEntrada->execute([$idEliminar]);

                $stmtDelCat = $conexion->prepare("DELETE FROM catalogo WHERE producto = ?");
                $stmtDelCat->execute([$nombreProd]);

                $conexion->commit();
                header("Location: alimentos.php?msg=eliminado");
                exit();
            } else {
                header("Location: alimentos.php?msg=eliminado");
                exit();
            }
        } catch (Exception $e) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            $mensajeError = "Error al eliminar el producto: " . $e->getMessage();
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado') {
    $mensajeExito = "Producto eliminado correctamente de Entradas, Catálogo y Panel.";
}

// Procesar el guardado en la base de datos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'agregar_alimento_directo') {
    $producto = trim($_POST['producto'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'Granos');
    $cantidadVal = floatval($_POST['cantidad'] ?? 0);
    $unidad = trim($_POST['unidad'] ?? 'unidades');
    $proveedor = trim($_POST['proveedor'] ?? 'Ministerio de Educación');
    $vencimiento = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : NULL;
    $ubicacion = trim($_POST['ubicacion'] ?? 'Bodega Principal');
    $responsable = $nombreUsuario;
    
    $fechaActual = date('Y-m-d');
    $horaActual = date('H:i:s');

    if (!empty($producto) && $cantidadVal > 0) {
        try {
            $conexion->beginTransaction();

            $cantidadFinal = $cantidadVal . ' ' . $unidad;
            $estado = ($cantidadVal <= 10) ? 'Stock Bajo' : 'Disponible';

            $resId = $conexion->query("SELECT id FROM catalogo ORDER BY id DESC LIMIT 1");
            $ultimoId = $resId ? $resId->fetch(PDO::FETCH_ASSOC) : false;
            $siguienteId = $ultimoId ? $ultimoId['id'] + 1 : 1;
            $codigo = "P" . str_pad($siguienteId, 3, "0", STR_PAD_LEFT);

            $stmtInsertCat = $conexion->prepare("INSERT INTO catalogo (codigo, producto, categoria, cantidad, estado, ubicacion, vencimiento) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtInsertCat->execute([$codigo, $producto, $categoria, $cantidadFinal, $estado, $ubicacion, $vencimiento]);

            $stmtEntrada = $conexion->prepare("INSERT INTO registroentradas (producto, cantidad, fecha, hora, proveedor, responsable) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtEntrada->execute([$producto, $cantidadFinal, $fechaActual, $horaActual, $proveedor, $responsable]);

            $conexion->commit();
            header("Location: alimentos.php?exito=1");
            exit;
        } catch (Exception $e) {
            $conexion->rollBack();
            $mensajeError = "Error al guardar en la BD: " . $e->getMessage();
        }
    } else {
        $mensajeError = "Por favor ingresa un nombre de producto y una cantidad válida.";
    }
}

// Alertas, estadísticas y lectura por categoría desde la BD
$alertasStockBajo = [];
$alertasVencimiento = [];
$listaCategoriasBD = [];

try {
    $stmtTotal = $conexion->query("SELECT COUNT(*) as total FROM catalogo");
    $totalProductos = $stmtTotal ? $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'] : 0;

    // Consulta para leer productos agrupados por categoría de la base de datos
    $stmtCatBD = $conexion->query("SELECT categoria, cantidad FROM catalogo");
    if ($stmtCatBD) {
        $todosProdsCat = $stmtCatBD->fetchAll(PDO::FETCH_ASSOC);
        $agrupadas = [];
        
        foreach ($todosProdsCat as $prod) {
            $cat = !empty($prod['categoria']) ? $prod['categoria'] : 'General';
            preg_match('/[0-9]+(\.[0-9]+)?/', $prod['cantidad'], $matches);
            $numVal = isset($matches[0]) ? floatval($matches[0]) : 0;

            if (!isset($agrupadas[$cat])) {
                $agrupadas[$cat] = ['total_unidades' => 0, 'total_productos' => 0];
            }
            $agrupadas[$cat]['total_unidades'] += $numVal;
            $agrupadas[$cat]['total_productos'] += 1;
        }

        foreach ($agrupadas as $nombreCat => $datosCat) {
            $listaCategoriasBD[] = [
                'nombre' => $nombreCat,
                'unidades' => $datosCat['total_unidades'],
                'productos' => $datosCat['total_productos']
            ];
        }
    }

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
    $stockBajo = count($alertasStockBajo);

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
                
                if ($diasRestantes <= 10) {
                    $alertasVencimiento[] = [
                        'producto' => $p['producto'],
                        'dias' => $diasRestantes
                    ];
                }
            }
        }
    }

    $stmtCatalogo = $conexion->query("SELECT * FROM catalogo ORDER BY id DESC LIMIT 5");
    $listaCatalogo = $stmtCatalogo ? $stmtCatalogo->fetchAll(PDO::FETCH_ASSOC) : [];

    $stmtEntradas = $conexion->query("SELECT * FROM registroentradas ORDER BY id DESC LIMIT 5");
    $listaEntradas = $stmtEntradas ? $stmtEntradas->fetchAll(PDO::FETCH_ASSOC) : [];

    $maxValorGrafica = 10;
    foreach ($listaCatalogo as $prod) {
        preg_match('/[0-9]+(\.[0-9]+)?/', $prod['cantidad'], $matches);
        $num = isset($matches[0]) ? floatval($matches[0]) : 1;
        if ($num > $maxValorGrafica) {
            $maxValorGrafica = $num;
        }
    }
} catch (Exception $e) {
    $totalProductos = 0;
    $stockBajo = 0;
    $listaCatalogo = [];
    $listaEntradas = [];
    $maxValorGrafica = 100;
}

$totalAlertas = count($alertasStockBajo) + count($alertasVencimiento);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control - Inventario Escolar</title>
    <link href="./output.css" rel="stylesheet">
<style>
html { box-sizing: border-box; }
*, *::before, *::after { box-sizing: inherit; }
body { overflow-x: hidden; background: #f4f6f9; font-family: Arial, Helvetica, sans-serif; }
#menuInventario {
  position: fixed !important; top: 0 !important; bottom: 0 !important; left: 0 !important; right: auto !important;
  width: min(320px, 86vw) !important; height: 100dvh !important; overflow-y: auto !important;
  border-radius: 0 14px 14px 0 !important; z-index: 9999 !important; background: #fff;
  box-shadow: 8px 0 28px rgba(0,0,0,.14);
}
#toastAlerta {
  position: fixed;
  top: 80px;
  right: 25px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  box-shadow: 0 10px 25px rgba(0,0,0,0.12);
  border-radius: 12px;
  padding: 14px 18px;
  z-index: 10000;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  width: 310px;
  animation: slideIn 0.3s ease-out;
}
@keyframes slideIn {
  from { transform: translateX(100%); opacity: 0; }
  to { transform: translateX(0); opacity: 1; }
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

<body class="bg-gray-100 text-gray-800">

    <?php if ($totalAlertas > 0): ?>
    <div id="toastAlerta">
        <div style="width: 28px; height: 28px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">🔴</div>
        <div style="flex-grow: 1;">
            <div style="font-size: 13px; font-weight: 700; color: #1e293b; display: flex; justify-content: space-between; align-items: center;">
                <span>Nueva alerta de inventario</span>
                <button onclick="cerrarToast()" style="background: none; border: none; font-size: 16px; color: #94a3b8; cursor: pointer;">×</button>
            </div>
            <div style="font-size: 12px; color: #64748b; margin-top: 2px;"><?php echo $totalAlertas; ?> alerta(s) activa(s)</div>
            <div style="font-size: 11px; color: #475569; margin-top: 4px;">Pulsa la campana para verlas.</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="min-h-screen">
        <header class="bg-white border-b border-gray-200 px-6 py-4 sticky top-0 z-40 shadow-sm">
            <div class="flex justify-between items-center w-full">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <button onclick="toggleMenuInventario()" class="w-11 h-11 rounded-lg bg-purple-100 text-purple-700 text-2xl flex items-center justify-center hover:bg-purple-200 transition">☰</button>

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
                             
                            <a href="alimentos.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 bg-purple-100 text-purple-700 font-semibold">🏠 <span>Panel de Control</span></a>
                            <a href="catalogo.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">📦 <span>Catálogo</span></a>
                            <a href="indext.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">⬆️ <span>Entradas</span></a>    
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
                        <h2 class="text-xl font-bold">Inventario Escolar</h2>
                        <p class="text-xs text-gray-500">Panel de Control</p>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 20px; position: relative;">
                    <div style="position: relative;">
                        <div onclick="toggleNotificaciones(event)" style="position: relative; cursor: pointer; width: 38px; height: 38px; border-radius: 50%; background: #f8fafc; display: flex; align-items: center; justify-content: center; border: 1px solid #cbd5e1;" class="hover:bg-gray-100">
                            <?php if ($totalAlertas > 0): ?>
                                <span style="background: #dc3545; color: white; border-radius: 50%; padding: 1px 6px; font-size: 10px; font-weight: bold; position: absolute; top: -3px; right: -3px;"><?php echo $totalAlertas; ?></span>
                            <?php endif; ?>
                            <span style="font-size: 18px;">🔔</span>
                        </div>

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

                    <div id="userDropdownMenu" class="hidden" style="position: absolute; top: 55px; right: 0; width: 280px; background: white; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1000; padding: 15px;">
                        <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 10px;">
                            <div style="font-size: 14px; font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                            <div style="font-size: 12px; color: #64748b; word-break: break-all;"><?php echo htmlspecialchars($emailUsuario); ?></div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <a href="#" onclick="alert('Sección de Cuenta'); return false;" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">👤 <span>Mi cuenta</span></a>
                            <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">➕ <span>Agregar cuenta</span></a>
                            <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-gray-50">🔄 <span>Cambiar cuenta</span></a>
                            
                            <!-- OPCIÓN DE AYUDA -->
                            <a href="#" onclick="abrirCentroAyuda(); return false;" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;" class="hover:bg-purple-50">❓ <span class="font-semibold text-purple-700">Ayuda</span></a>
                            
                            <div style="border-top: 1px solid #f1f5f9; margin-top: 4px; padding-top: 4px;">
                                <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #dc2626; text-decoration: none; font-size: 13px;">🚪 <span>Cerrar sesión</span></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-8">
            <?php if (isset($_GET['exito'])): ?>
                <div style="background:#d1fae5;color:#065f46;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-weight:bold;border:1px solid #a7f3d0;">
                    ¡Producto guardado exitosamente con su código en el Catálogo y Entradas!
                </div>
            <?php endif; ?>

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

            <div class="flex justify-end mb-5">
                <button class="bg-purple-600 hover:bg-purple-700 text-white px-5 py-2.5 rounded-lg font-semibold transition shadow-sm">
                    ✏️ Editar panel
                </button>
            </div>

            <!-- TARJETAS SUPERIORES -->
            <div class="grid gap-4" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));">
                <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <div class="flex justify-between">
                        <div class="w-9 h-9 rounded-lg bg-purple-100 flex items-center justify-center text-xl">📦</div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Total Productos</p>
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo $totalProductos; ?></h3>
                </div>

                <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <div class="flex justify-between">
                        <div class="w-9 h-9 rounded-lg bg-red-50 flex items-center justify-center text-xl">⚠️</div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Stock Bajo</p>
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo $stockBajo; ?></h3>
                </div>

                <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                    <div class="flex justify-between">
                        <div class="w-9 h-9 rounded-lg bg-yellow-50 flex items-center justify-center text-xl">📅</div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Próximos a vencer</p>
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo count($alertasVencimiento); ?></h3>
                </div>
            </div>

            <!-- SECCIÓN CENTRAL (PRODUCTOS POR CATEGORÍA CONECTADO A LA BASE DE DATOS) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg flex items-center gap-2 text-gray-800">📊 Productos por Categoría</h3>
                        <a href="catalogo.php" class="text-purple-600 text-xs font-semibold hover:underline">Ver Catálogo</a>
                    </div>
                    
                    <div class="space-y-4">
                        <?php if (empty($listaCategoriasBD)): ?>
                            <p class="text-xs text-gray-500 py-3 text-center">No hay categorías registradas en la base de datos.</p>
                        <?php else: ?>
                            <?php 
                            // Calcular el valor máximo para las barras de progreso proporcionales
                            $maxCatVal = 1;
                            foreach ($listaCategoriasBD as $cat) {
                                if ($cat['unidades'] > $maxCatVal) $maxCatVal = $cat['unidades'];
                            }

                            foreach ($listaCategoriasBD as $cat): 
                                $porcentaje = ($cat['unidades'] / $maxCatVal) * 100;
                                if ($porcentaje < 15) $porcentaje = 15; // Mínimo visible para estética
                            ?>
                                <div>
                                    <div class="flex justify-between text-xs text-gray-600 mb-1">
                                        <span class="font-medium"><?php echo htmlspecialchars($cat['nombre']); ?></span>
                                        <span><?php echo $cat['unidades']; ?> unidades · <?php echo $cat['productos']; ?> producto(s)</span>
                                    </div>
                                    <div class="w-full bg-gray-100 h-2.5 rounded-full overflow-hidden">
                                        <div class="bg-purple-600 h-full rounded-full transition-all duration-300" style="width: <?php echo $porcentaje; ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg flex items-center gap-2 text-gray-800">⚠️ Alertas de Inventario</h3>
                        <a href="#" class="text-purple-600 text-xs font-semibold hover:underline">Editar</a>
                    </div>
                    <?php if ($totalAlertas == 0): ?>
                        <div class="text-xs text-green-700 font-medium flex items-center gap-1.5 mt-2 bg-green-50 p-3 rounded-lg border border-green-200">
                            ✓ No hay alertas de inventario pendientes.
                        </div>
                    <?php else: ?>
                        <div class="text-xs text-red-700 font-medium flex items-center gap-1.5 mt-2 bg-red-50 p-3 rounded-lg border border-red-200">
                            ⚠ Hay <?php echo $totalAlertas; ?> alerta(s) activa(s) entre stock bajo y fechas de vencimiento.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECCIÓN INFERIOR -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg text-gray-800">Actividad Reciente</h3>
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" onclick="agregarActividad()" style="background-color: #059669 !important; color: #ffffff !important;" class="font-semibold px-3 py-1.5 rounded-lg transition shadow-sm cursor-pointer hover:bg-emerald-700">
                                + Agregar
                            </button>
                        </div>
                    </div>
                    <?php if (empty($listaEntradas)): ?>
                        <p class="text-xs text-gray-500 py-4 text-center">No hay actividad reciente.</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($listaEntradas as $entrada): ?>
                                <div class="flex justify-between items-center bg-gray-50 p-3 rounded-lg border border-gray-200 hover:bg-gray-100 transition">
                                    <div>
                                        <span class="font-semibold text-gray-800 block text-sm">📦 <?php echo htmlspecialchars($entrada['producto']); ?></span>
                                        <span class="text-xs text-gray-500">Entrada · <?php echo htmlspecialchars($entrada['fecha'] ?? ''); ?> (Proveedor: <?php echo htmlspecialchars($entrada['proveedor'] ?? 'N/A'); ?>)</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="font-bold text-purple-700 text-sm"><?php echo htmlspecialchars($entrada['cantidad']); ?></span>
                                        <a href="alimentos.php?eliminar_id=<?php echo $entrada['id']; ?>" onclick="return confirm('¿Estás segura de eliminar este producto? Se borrará del Panel, del Catálogo y de Entradas.');" class="bg-red-500 hover:bg-red-600 text-white px-2.5 py-1.5 rounded-lg text-xs font-bold transition shadow-sm flex items-center gap-1" title="Eliminar">
                                            🗑️ Eliminar
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg text-gray-800">📊 Productos en Inventario</h3>
                        <a href="catalogo.php" class="text-purple-600 text-xs font-semibold hover:underline">Editar</a>
                    </div>
                    
                    <div class="flex items-end justify-around h-56 border-b border-gray-200 pb-2 pt-8 px-2">
                        <?php if (empty($listaCatalogo)): ?>
                            <p class="text-xs text-gray-400 m-auto">Sin datos para mostrar en la gráfica</p>
                        <?php else: ?>
                            <?php foreach ($listaCatalogo as $prod): 
                                preg_match('/[0-9]+(\.[0-9]+)?/', $prod['cantidad'], $matches);
                                $valNum = isset($matches[0]) ? floatval($matches[0]) : 5;
                                
                                $porcentajeAltura = ($valNum / $maxValorGrafica) * 100;
                                if ($porcentajeAltura < 15) {
                                    $porcentajeAltura = 15;
                                }
                            ?>
                                <div class="flex flex-col items-center gap-1 h-full justify-end">
                                    <span class="text-[11px] font-bold text-gray-700"><?php echo htmlspecialchars($prod['cantidad']); ?></span>
                                    <div class="w-12 bg-purple-600 hover:bg-purple-700 rounded-t-lg transition-all shadow-sm" style="height: <?php echo $porcentajeAltura; ?>%;"></div>
                                    <span class="text-xs text-gray-600 truncate max-w-[70px] mt-1"><?php echo htmlspecialchars($prod['producto']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- CENTRO DE AYUDA INTEGRADO -->
    <div id="modalCentroAyuda" class="hidden fixed inset-0 z-[10000] flex items-stretch justify-stretch bg-white" onclick="if(event.target===this) cerrarCentroAyuda()">
      <section class="w-full h-full flex flex-col overflow-hidden bg-white" style="height:100dvh;" role="dialog" aria-modal="true" aria-label="Centro de ayuda">
        <iframe title="Centro de ayuda y soluciones" style="display:block;width:100%;height:100dvh;min-height:100%;flex:1 1 auto;border:0;background:#fff9fc;" srcdoc="&lt;!DOCTYPE html&gt;
&lt;html lang=&quot;es&quot;&gt;
&lt;head&gt;
&lt;meta charset=&quot;UTF-8&quot;&gt;
&lt;meta name=&quot;viewport&quot; content=&quot;width=device-width, initial-scale=1.0&quot;&gt;
&lt;title&gt;Centro de Ayuda | Inventario Escolar&lt;/title&gt;
&lt;style&gt;
:root{--green:#a8b5eb;--green2:#626fae;--pale:#f0edff;--ink:#45445a;--muted:#77768c;--line:#e8e3f0}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:#fff9fc;color:var(--ink);font-family:Arial,Helvetica,sans-serif}
header{position:sticky;top:0;z-index:20;background:#f4f0ff;border-bottom:1px solid var(--line);padding:16px 5%;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;min-height:78px}.back-panel{position:sticky;top:8px;z-index:30;display:inline-flex;align-items:center;justify-content:center;background:#fff!important;color:#514b79!important;border:2px solid #aaa2df!important;box-shadow:0 4px 14px #514b7940!important;white-space:nowrap;min-height:46px;padding:12px 18px;flex-shrink:0;border-radius:12px}.back-panel:hover{background:#e9e5ff!important;transform:translateY(-1px)}header{position:sticky;top:0;z-index:20;padding-right:5%;}@media(max-width:600px){.back-panel{top:4px;min-height:42px;padding:10px 12px;font-size:12px}header{padding-right:5%;min-height:72px}.brand{font-size:16px}}
.brand{font-size:20px;font-weight:800;color:#626fae}.brand small{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-top:4px}
a.btn,button.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;border-radius:10px;padding:11px 15px;font-weight:700;cursor:pointer;font-size:14px}
.primary{background:#a8b5eb;color:#30375e}.secondary{background:#fff0f5;color:#62536a;border:1px solid #ead9e8!important}
main{max-width:1280px;margin:0 auto;padding:26px 4% 36px}.intro{background:linear-gradient(135deg,#dcd8ff,#f9dce9 55%,#d8eff0);color:#4c4a69;border:1px solid #eee3f4;border-radius:20px;padding:30px;margin-bottom:24px}.intro p{color:#64617d}
.intro h1{font-size:clamp(26px,4vw,36px);margin:0 0 10px}.intro p{margin:0;line-height:1.6;max-width:700px;color:#64617d}
.layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr);gap:22px}
.panel{background:#fff;border:1px solid #eee4f0;border-radius:16px;padding:22px;box-shadow:0 5px 20px #aa9eb51a}
.panel h2{font-size:20px;margin:0 0 8px}.muted{color:var(--muted);font-size:14px;line-height:1.55}
.issues{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:18px 0}
.issue{background:#fff9fc;border:1px solid #e9dfef;border-radius:12px;padding:14px;text-align:left;cursor:pointer;min-height:80px;color:var(--ink);font-size:14px;font-weight:700}
.issue span{display:block;font-size:23px;margin-bottom:8px}.issue[aria-pressed=&quot;true&quot;]{border:2px solid #a8a0e4;background:#f0edff}
.solution{background:#f4f8ff;border:1px solid #dfe8f7;border-radius:12px;padding:17px;margin-top:16px}
.solution h3{margin:0 0 10px;font-size:17px}.solution ol{padding-left:21px;line-height:1.65;margin:8px 0}.solution p{line-height:1.6}
label{display:block;font-weight:700;font-size:14px;margin:14px 0 7px}select,input,textarea{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;background:#fff;color:var(--ink)}textarea{min-height:110px;resize:vertical}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.status{font-size:13px;line-height:1.5;margin-top:12px;color:#626fae}
.faq{border-top:1px solid var(--line);padding:14px 0}.faq summary{font-weight:700;cursor:pointer}.faq p{color:var(--muted);line-height:1.55;font-size:14px}
footer{text-align:center;padding:28px 16px;color:var(--muted);font-size:13px;background:#f4f0ff}
.wa{position:fixed;right:20px;bottom:20px;background:#c9e9df;color:#315e53;border-radius:999px;padding:14px 18px;text-decoration:none;font-weight:800;box-shadow:0 8px 25px #16653440}
@media(max-width:760px){.layout{grid-template-columns:1fr}.issues{grid-template-columns:repeat(2,minmax(0,1fr))}main{margin:0 auto;padding:18px 12px 30px}.intro{padding:24px}.panel{padding:18px}header{padding:14px 16px}}
@media(max-width:420px){.issues{grid-template-columns:1fr}.wa{right:12px;bottom:12px}}
&lt;/style&gt;
&lt;/head&gt;
&lt;body&gt;
&lt;header&gt;
  &lt;div class=&quot;brand&quot;&gt;Inventario Escolar &lt;small&gt;Centro de ayuda y soluciones&lt;/small&gt;&lt;/div&gt;
&lt;/header&gt;
&lt;main&gt;
  &lt;section class=&quot;intro&quot; style=&quot;position:relative;padding-right:220px;min-height:132px;&quot;&gt;
    &lt;h1&gt;¿En qué podemos ayudarte?&lt;/h1&gt;
    &lt;p&gt;Selecciona el problema para ver una guía de solución. Si los pasos no funcionan, describe lo ocurrido y prepara una consulta para el equipo de soporte.&lt;/p&gt;
    &lt;button class=&quot;btn secondary back-panel&quot; style=&quot;position:absolute;right:20px;top:24px;z-index:5;white-space:nowrap;display:inline-flex;align-items:center;justify-content:center;min-height:44px;box-shadow:0 3px 10px #9b8bd833;&quot; type=&quot;button&quot; onclick=&quot;parent.cerrarCentroAyuda()&quot; aria-label=&quot;Volver al panel de control&quot;&gt;← Volver al panel&lt;/button&gt;
  &lt;/section&gt;
  &lt;div class=&quot;layout&quot;&gt;
    &lt;section class=&quot;panel&quot;&gt;
      &lt;h2&gt;Elige el problema&lt;/h2&gt;
      &lt;p class=&quot;muted&quot;&gt;Pulsa una opción y aquí aparecerán los pasos recomendados.&lt;/p&gt;
      &lt;div class=&quot;issues&quot; id=&quot;issues&quot;&gt;
        &lt;button class=&quot;issue&quot; data-issue=&quot;guardar&quot; aria-pressed=&quot;true&quot;&gt;&lt;span&gt;💾&lt;/span&gt;No puedo guardar cambios&lt;/button&gt;
        &lt;button class=&quot;issue&quot; data-issue=&quot;productos&quot; aria-pressed=&quot;false&quot;&gt;&lt;span&gt;📦&lt;/span&gt;Problemas con productos&lt;/button&gt;
        &lt;button class=&quot;issue&quot; data-issue=&quot;cuenta&quot; aria-pressed=&quot;false&quot;&gt;&lt;span&gt;👤&lt;/span&gt;Problemas con mi cuenta&lt;/button&gt;
        &lt;button class=&quot;issue&quot; data-issue=&quot;alertas&quot; aria-pressed=&quot;false&quot;&gt;&lt;span&gt;🔔&lt;/span&gt;No aparecen las alertas&lt;/button&gt;
        &lt;button class=&quot;issue&quot; data-issue=&quot;acceso&quot; aria-pressed=&quot;false&quot;&gt;&lt;span&gt;🔐&lt;/span&gt;No puedo entrar al sistema&lt;/button&gt;
        &lt;button class=&quot;issue&quot; data-issue=&quot;otro&quot; aria-pressed=&quot;false&quot;&gt;&lt;span&gt;🛠️&lt;/span&gt;Otro problema&lt;/button&gt;
      &lt;/div&gt;
      &lt;div class=&quot;solution&quot; id=&quot;solution&quot; aria-live=&quot;polite&quot;&gt;
        &lt;h3 id=&quot;solutionTitle&quot;&gt;💾 No puedo guardar cambios&lt;/h3&gt;
        &lt;div id=&quot;solutionBody&quot;&gt;&lt;/div&gt;
      &lt;/div&gt;
    &lt;/section&gt;
    &lt;section class=&quot;panel&quot;&gt;
      &lt;h2&gt;Enviar una consulta&lt;/h2&gt;
      &lt;p class=&quot;muted&quot;&gt;Cuéntanos qué estabas intentando hacer y qué ocurrió. El formulario comprobará que los datos estén completos antes de preparar el mensaje.&lt;/p&gt;
      &lt;form id=&quot;helpForm&quot;&gt;
        &lt;label for=&quot;topic&quot;&gt;Tipo de problema&lt;/label&gt;
        &lt;select id=&quot;topic&quot; required&gt;
          &lt;option value=&quot;&quot;&gt;Selecciona una opción&lt;/option&gt;
          &lt;option&gt;No puedo guardar cambios&lt;/option&gt;&lt;option&gt;Problemas con productos&lt;/option&gt;
          &lt;option&gt;Problemas con mi cuenta&lt;/option&gt;&lt;option&gt;No aparecen las alertas&lt;/option&gt;
          &lt;option&gt;No puedo entrar al sistema&lt;/option&gt;&lt;option&gt;Otro problema&lt;/option&gt;
        &lt;/select&gt;
        &lt;label for=&quot;detail&quot;&gt;¿Qué sucede?&lt;/label&gt;
        &lt;textarea id=&quot;detail&quot; required minlength=&quot;8&quot; placeholder=&quot;Ejemplo: pulso Guardar, pero los datos no se actualizan...&quot;&gt;&lt;/textarea&gt;
        &lt;label for=&quot;contact&quot;&gt;Correo o forma de contacto (opcional)&lt;/label&gt;
        &lt;input id=&quot;contact&quot; type=&quot;text&quot; placeholder=&quot;Tu correo, si deseas incluirlo&quot;&gt;
        &lt;div class=&quot;actions&quot;&gt;
          &lt;button class=&quot;btn primary&quot; type=&quot;submit&quot;&gt;Preparar consulta&lt;/button&gt;
          &lt;button class=&quot;btn secondary&quot; type=&quot;button&quot; id=&quot;clearForm&quot;&gt;Limpiar&lt;/button&gt;
        &lt;/div&gt;
        &lt;div class=&quot;status&quot; id=&quot;formStatus&quot; role=&quot;status&quot;&gt;&lt;/div&gt;
        &lt;div class=&quot;actions&quot; id=&quot;sendActions&quot; hidden&gt;
          &lt;a class=&quot;btn primary&quot; id=&quot;whatsappLink&quot; href=&quot;#&quot; target=&quot;_blank&quot; rel=&quot;noopener&quot;&gt;Abrir WhatsApp&lt;/a&gt;
          &lt;button class=&quot;btn secondary&quot; type=&quot;button&quot; id=&quot;copyMessage&quot;&gt;Copiar mensaje&lt;/button&gt;
        &lt;/div&gt;
      &lt;/form&gt;
    &lt;/section&gt;
  &lt;/div&gt;
  &lt;section class=&quot;panel&quot; style=&quot;margin-top:22px&quot;&gt;
    &lt;h2&gt;Preguntas frecuentes&lt;/h2&gt;
    &lt;details class=&quot;faq&quot;&gt;&lt;summary&gt;¿Por qué no se guardan los datos?&lt;/summary&gt;&lt;p&gt;Comprueba que los campos obligatorios estén completos y que el navegador no muestre errores. Si la aplicación guarda datos localmente, usa el mismo navegador y no borres sus datos del sitio.&lt;/p&gt;&lt;/details&gt;
    &lt;details class=&quot;faq&quot;&gt;&lt;summary&gt;¿Por qué no aparece un producto o una alerta?&lt;/summary&gt;&lt;p&gt;Revisa que el producto se haya guardado, que la fecha de vencimiento sea correcta y que no haya filtros activos. Recarga la página después de comprobarlo.&lt;/p&gt;&lt;/details&gt;
    &lt;details class=&quot;faq&quot;&gt;&lt;summary&gt;¿Qué hago si los pasos no funcionan?&lt;/summary&gt;&lt;p&gt;Describe el paso exacto, lo que esperabas que ocurriera y el mensaje de error, si aparece.&lt;/p&gt;&lt;/details&gt;
  &lt;/section&gt;
&lt;/main&gt;
&lt;footer&gt;Inventario Escolar · Centro de ayuda&lt;/footer&gt;
&lt;a class=&quot;wa&quot; id=&quot;floatingWhatsApp&quot; href=&quot;https://wa.me/5030000000?text=Hola%2C%20necesito%20ayuda%20con%20el%20Inventario%20Escolar.&quot; target=&quot;_blank&quot; rel=&quot;noopener&quot; aria-label=&quot;Contactar por WhatsApp&quot;&gt;&lt;span aria-hidden=&quot;true&quot; style=&quot;display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#fff;color:#25D366;font-weight:800;font-size:19px;margin-right:8px&quot;&gt;◔&lt;/span&gt; WhatsApp&lt;/a&gt;
&lt;script&gt;
const helpData = {
 guardar:{title:&quot;💾 No puedo guardar cambios&quot;,steps:[&quot;Comprueba que todos los campos obligatorios estén completos y que no haya mensajes de validación.&quot;,&quot;Pulsa Guardar una sola vez y espera el aviso de confirmación.&quot;,&quot;Si no ocurre nada, recarga la página solo después de copiar los datos que no quieras perder.&quot;]},
 productos:{title:&quot;📦 Problemas con productos&quot;,steps:[&quot;Revisa que el nombre del producto esté escrito y que la cantidad sea un número válido.&quot;,&quot;Comprueba que la categoría y la fecha de vencimiento sean correctas.&quot;,&quot;Busca si el producto ya aparece en la tabla o si hay un filtro activo.&quot;]},
 cuenta:{title:&quot;👤 Problemas con mi cuenta&quot;,steps:[&quot;Revisa el nombre, correo y demás campos antes de guardar.&quot;,&quot;Guarda los cambios y comprueba si aparece un aviso de confirmación.&quot;]},
 alertas:{title:&quot;🔔 No aparecen las alertas&quot;,steps:[&quot;Comprueba que cada producto tenga una fecha de vencimiento válida.&quot;,&quot;Revisa si el producto vence dentro del período de alerta configurado.&quot;]},
 acceso:{title:&quot;🔐 No puedo entrar al sistema&quot;,steps:[&quot;Comprueba que estés abriendo el archivo correcto y que tu usuario esté bien escrito.&quot;]},
 otro:{title:&quot;🛠️ Otro problema&quot;,steps:[&quot;Describe qué estabas intentando hacer y qué ocurrió en realidad.&quot;]}
};
let currentIssue=&quot;guardar&quot;, preparedMessage=&quot;&quot;;
function renderIssue(key){
 currentIssue=key; const item=helpData[key]||helpData.otro;
 document.getElementById(&quot;solutionTitle&quot;).textContent=item.title;
 const ol=document.createElement(&quot;ol&quot;);
 item.steps.forEach(step=&gt;{const li=document.createElement(&quot;li&quot;);li.textContent=step;ol.appendChild(li)});
 const body=document.getElementById(&quot;solutionBody&quot;);body.replaceChildren(ol);
 document.querySelectorAll(&quot;.issue&quot;).forEach(btn=&gt;btn.setAttribute(&quot;aria-pressed&quot;,String(btn.dataset.issue===key)));
 const topic=document.getElementById(&quot;topic&quot;);
 const map={guardar:&quot;No puedo guardar cambios&quot;,productos:&quot;Problemas con productos&quot;,cuenta:&quot;Problemas con mi cuenta&quot;,alertas:&quot;No aparecen las alertas&quot;,acceso:&quot;No puedo entrar al sistema&quot;,otro:&quot;Otro problema&quot;};
 topic.value=map[key]||&quot;&quot;;
}
document.querySelectorAll(&quot;.issue&quot;).forEach(btn=&gt;btn.addEventListener(&quot;click&quot;,()=&gt;renderIssue(btn.dataset.issue)));
document.getElementById(&quot;helpForm&quot;).addEventListener(&quot;submit&quot;,e=&gt;{
 e.preventDefault();
 const topic=document.getElementById(&quot;topic&quot;).value.trim(), detail=document.getElementById(&quot;detail&quot;).value.trim(), contact=document.getElementById(&quot;contact&quot;).value.trim();
 const status=document.getElementById(&quot;formStatus&quot;), actions=document.getElementById(&quot;sendActions&quot;);
 if(!topic||detail.length&lt;8){status.textContent=&quot;Selecciona el tipo de problema y escribe al menos 8 caracteres para explicar lo ocurrido.&quot;;actions.hidden=true;return}
 preparedMessage=&quot;Hola, necesito ayuda con el Inventario Escolar.\\nProblema: &quot;+topic+&quot;\\nDetalle: &quot;+detail+(contact?&quot;\\nContacto: &quot;+contact:&quot;&php&quot;);
 document.getElementById(&quot;whatsappLink&quot;).href=&quot;https://wa.me/5030000000?text=&quot;+encodeURIComponent(preparedMessage);
 status.textContent=&quot;Consulta preparada. Puedes abrir WhatsApp o copiar el mensaje.&quot;;
 actions.hidden=false;
});
document.getElementById(&quot;clearForm&quot;).addEventListener(&quot;click&quot;,()=&gt;{
 document.getElementById(&quot;helpForm&quot;).reset();document.getElementById(&quot;formStatus&quot;).textContent=&quot;Formulario limpiado.&quot;;document.getElementById(&quot;sendActions&quot;).hidden=true;
});
document.getElementById(&quot;copyMessage&quot;).addEventListener(&quot;click&quot;,async()=&gt;{
 const status=document.getElementById(&quot;formStatus&quot;);
 try{await navigator.clipboard.writeText(preparedMessage);status.textContent=&quot;Mensaje copiado. Ya puedes pegarlo donde necesites.&quot;}
 catch(err){const area=document.getElementById(&quot;detail&quot;);area.focus();status.textContent=&quot;No se pudo copiar automáticamente. Selecciona y copia el texto.&quot;}
});
renderIssue(&quot;guardar&quot;);
&lt;/script&gt;
&lt;/body&gt;
&lt;/html&gt;" class="w-full h-full border-0" loading="eager"></iframe>
      </section>
    </div>

    <!-- FORMULARIO OCULTO PARA AGREGAR PRODUCTO -->
    <form id="formPHPDirecto" method="POST" action="alimentos.php" style="display:none;">
        <input type="hidden" name="accion" value="agregar_alimento_directo">
        <input type="hidden" name="producto" id="php_producto">
        <input type="hidden" name="cantidad" id="php_cantidad">
        <input type="hidden" name="unidad" id="php_unidad">
        <input type="hidden" name="categoria" id="php_categoria">
        <input type="hidden" name="proveedor" id="php_proveedor">
        <input type="hidden" name="fecha_vencimiento" id="php_vencimiento">
        <input type="hidden" name="ubicacion" id="php_ubicacion">
    </form>

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

        function cerrarToast() {
            const toast = document.getElementById("toastAlerta");
            if (toast) toast.remove();
        }

        function abrirCentroAyuda() {
            const modal = document.getElementById("modalCentroAyuda");
            if (modal) modal.classList.remove("hidden");
            document.body.style.overflow = "hidden";
            const dropdown = document.getElementById("userDropdownMenu");
            if (dropdown) dropdown.classList.add("hidden");
        }

        function cerrarCentroAyuda() {
            const modal = document.getElementById("modalCentroAyuda");
            if (modal) modal.classList.add("hidden");
            document.body.style.overflow = "";
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

        function agregarActividad() {
            const nombre = prompt("Nombre del producto:");
            if (!nombre || !nombre.trim()) return;

            const cantidadTexto = prompt("Cantidad numérica (ej. 25):");
            if (!cantidadTexto || !cantidadTexto.trim()) return;

            const unidad = prompt("Unidad de medida (ej. Kg, Libras, Unidades):", "Kg");
            if (!unidad) return;

            const proveedor = prompt("Proveedor (ej. Ministerio de Educación, Empresa X):", "Ministerio de Educación");
            if (!proveedor) return;

            const categoria = prompt("Categoría (Granos, Lácteos, Proteínas, Verduras, etc.):", "Granos");
            const fechaVencimiento = prompt("Fecha de vencimiento (AAAA-MM-DD):", "2026-12-31");
            const ubicacion = prompt("Ubicación:", "Bodega Principal");

            document.getElementById("php_producto").value = nombre.trim();
            document.getElementById("php_cantidad").value = cantidadTexto.trim();
            document.getElementById("php_unidad").value = unidad.trim();
            document.getElementById("php_proveedor").value = proveedor.trim();
            document.getElementById("php_categoria").value = categoria ? categoria.trim() : "Granos";
            document.getElementById("php_vencimiento").value = fechaVencimiento ? fechaVencimiento.trim() : "";
            document.getElementById("php_ubicacion").value = ubicacion ? ubicacion.trim() : "Bodega Principal";

            document.getElementById("formPHPDirecto").submit();
        }
    </script>
</body>
</html>