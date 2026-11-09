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

// Consultas para las alertas de la campanita (Stock bajo desde 10 hacia abajo y Vencimientos en 10 días o menos)
$alertasStockBajo = [];
$alertasVencimiento = [];

try {
    // 1. Stock bajo (estado 'Stock Bajo' O cantidad numérica menor o igual a 10)
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
                
                // Configurado exactamente para 10 días o menos
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
    // En caso de error en la BD
}

$totalAlertas = count($alertasStockBajo) + count($alertasVencimiento);

// Obtener todos los productos para el selector desplegable superior
try {
    $stmtLista = $conexion->query("SELECT codigo, producto FROM catalogo ORDER BY id DESC");
    $todosProductos = $stmtLista ? $stmtLista->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $todosProductos = [];
}

// Determinar el producto actual
$codigoActual = $_GET['codigo'] ?? '';
if (empty($codigoActual) && !empty($todosProductos)) {
    $codigoActual = $todosProductos[0]['codigo'];
}

// Consultar los detalles completos del producto seleccionado
$productoDetalle = null;
if (!empty($codigoActual)) {
    try {
        $stmtDet = $conexion->prepare("SELECT * FROM catalogo WHERE codigo = ?");
        $stmtDet->execute([$codigoActual]);
        $productoDetalle = $stmtDet->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $productoDetalle = null;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle del Producto - Inventario Escolar</title>
    <link href="./output.css" rel="stylesheet">
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
                            <a href="indext.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">⬆️ <span>Entradas</span></a>    
                            <a href="entradas.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">🍽️ <span>Menú</span></a>
                            <a href="index1.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 text-gray-600 hover:bg-gray-100 transition">⬇️ <span>Salidas</span></a>
                        </div>
                    </div>

                    <!-- SELECTOR DE PRODUCTOS -->
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-500 hidden sm:inline">Seleccionar Producto:</span>
                        <select id="selectorProducto" onchange="cambiarProducto(this.value)" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:border-purple-600 font-medium">
                            <?php foreach ($todosProductos as $p): ?>
                                <option value="<?php echo $p['codigo']; ?>" <?php echo ($p['codigo'] === $codigoActual) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['codigo'] . ' ' . $p['producto']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- PERFIL DEL USUARIO Y CAMPANA DE NOTIFICACIONES -->
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
        <main class="p-8 max-w-6xl mx-auto">
            
            <div class="mb-6">
                <a href="catalogo.php" class="text-red-500 text-sm font-semibold hover:underline">&lt; Volver al catálogo</a>
                <div class="flex justify-between items-center mt-2">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Detalle del Producto</h2>
                        <p class="text-xs text-gray-500">Información completa del producto</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="alert('Función de edición en desarrollo')" class="border border-blue-500 text-blue-600 hover:bg-blue-50 px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-1">
                            ✏️ Editar
                        </button>
                        <a href="catalogo.php?eliminar_id=<?php echo $productoDetalle['id'] ?? 0; ?>" onclick="return confirm('¿Deseas eliminar este producto?');" class="border border-red-300 text-red-600 hover:bg-red-50 px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-1">
                            🗑️ Eliminar
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($productoDetalle): ?>
                <!-- TARJETAS SUPERIORES -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    
                    <!-- INFORMACIÓN GENERAL -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                        <h3 class="font-bold text-base text-gray-800 mb-4">Información General</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Nombre del Producto</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-medium text-gray-800">
                                    <?php echo htmlspecialchars($productoDetalle['producto']); ?>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Categoría</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-medium text-gray-800">
                                    <?php echo htmlspecialchars($productoDetalle['categoria']); ?>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Proveedor</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-medium text-gray-800">
                                    Ministerio
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Ubicación</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-medium text-gray-800">
                                    <?php echo htmlspecialchars($productoDetalle['ubicacion']); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- INVENTARIO -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                        <h3 class="font-bold text-base text-gray-800 mb-4">Inventario</h3>
                        <div class="grid grid-cols-3 gap-3 mb-4">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Cantidad Actual</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-bold text-gray-800">
                                    <?php echo htmlspecialchars($productoDetalle['cantidad']); ?>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Stock Mínimo</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-medium text-gray-800">10</div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Stock Máximo</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-medium text-gray-800">100</div>
                            </div>
                        </div>
                        <div class="text-xs font-semibold text-blue-600 mb-1">Nivel de stock óptimo</div>
                        <div class="w-full bg-gray-100 h-2 rounded-full overflow-hidden">
                            <div class="bg-blue-500 h-full rounded-full" style="width: 65%;"></div>
                        </div>
                    </div>

                </div>

                <!-- SECCIÓN CENTRAL -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    
                    <!-- INFORMACIÓN ADICIONAL -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                        <h3 class="font-bold text-base text-gray-800 mb-4">Información Adicional</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Fecha de Vencimiento</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-medium text-gray-800">
                                    <?php echo htmlspecialchars($productoDetalle['vencimiento'] ?? '2026-11-19'); ?>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Fecha de Registro</label>
                                <div class="bg-gray-100 rounded-lg px-3 py-2 text-sm font-medium text-gray-800">
                                    2026-11-05
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RESUMEN -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                        <h3 class="font-bold text-base text-gray-800 mb-4">Resumen</h3>
                        <div class="space-y-2 text-sm">
                            <div><span class="text-gray-500">ID Producto:</span> <span class="font-bold text-gray-800"><?php echo htmlspecialchars($productoDetalle['codigo']); ?></span></div>
                            <div><span class="text-gray-500">Estado:</span> <span class="px-2 py-0.5 rounded text-xs font-semibold bg-green-50 text-green-600"><?php echo htmlspecialchars($productoDetalle['estado']); ?></span></div>
                            <div><span class="text-gray-500">Ocupación:</span> <span class="font-semibold text-gray-800">65%</span></div>
                        </div>
                    </div>

                </div>

                <!-- SECCIÓN INFERIOR: ACCIONES RÁPIDAS Y NOTAS -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    
                    <!-- ACCIONES RÁPIDAS -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                        <h3 class="font-bold text-base text-gray-800 mb-4">Acciones Rápidas</h3>
                        <div class="flex gap-4">
                            <button type="button" onclick="abrirReporte()" style="background-color: #0d9488 !important; color: #ffffff !important;" class="hover:bg-teal-700 font-semibold px-5 py-2.5 rounded-lg text-sm transition shadow-sm flex items-center justify-center">
                                Generar Reporte
                            </button>
                            <button type="button" onclick="alert('Historial de movimientos de este producto')" class="bg-rose-300 hover:bg-rose-400 text-gray-900 font-semibold px-5 py-2.5 rounded-lg text-sm transition">
                                Ver Historial
                            </button>
                        </div>
                    </div>

                    <!-- NOTAS -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
                        <h3 class="font-bold text-base text-gray-800 mb-4">Notas</h3>
                        <textarea id="inputNota" placeholder="Escribe observaciones del producto..." class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:outline-none focus:border-purple-600 h-20 mb-3"></textarea>
                        <div class="flex justify-end">
                            <button type="button" onclick="alert('Nota guardada con éxito')" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-lg text-xs transition">
                                Guardar Notas
                            </button>
                        </div>
                    </div>

                </div>
            <?php else: ?>
                <div class="bg-white border border-gray-200 rounded-xl p-8 text-center shadow-sm mt-6">
                    <p class="text-red-500 font-semibold text-base">No se encontró información del producto seleccionado.</p>
                    <a href="catalogo.php" class="inline-block mt-4 text-purple-600 font-semibold hover:underline">Regresar al catálogo</a>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- MODAL DE REPORTE DEL PRODUCTO -->
    <div id="modalReporte" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 relative">
            <div class="flex justify-between items-center border-b border-gray-100 pb-3 mb-4">
                <h3 class="text-lg font-bold text-gray-800">Reporte de Producto: <span id="repNombre" class="text-purple-700"></span></h3>
                <button type="button" onclick="cerrarReporte()" class="text-gray-400 hover:text-gray-600 text-xl font-bold">×</button>
            </div>
            <div class="space-y-3 text-sm text-gray-700">
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">ID de Producto:</span> <span id="repId"></span></div>
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">Nombre:</span> <span id="repNombreProd"></span></div>
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">Categoría:</span> <span id="repCategoria"></span></div>
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">Proveedor:</span> <span>Ministerio</span></div>
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">Ubicación:</span> <span id="repUbicacion"></span></div>
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">Cantidad Actual:</span> <span id="repCantidad"></span></div>
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">Stock Mínimo / Máximo:</span> <span>13 / 100</span></div>
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">Fecha de Vencimiento:</span> <span id="repVencimiento" class="font-bold text-purple-700"></span></div>
                <div class="flex justify-between border-b pb-1.5"><span class="font-semibold text-gray-500">Fecha de Registro:</span> <span>2026-11-05</span></div>
                <div class="flex justify-between pb-1.5"><span class="font-semibold text-gray-500">Notas:</span> <span id="repNotaTexto">Sin observaciones</span></div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="cerrarReporte()" class="bg-gray-100 text-gray-700 font-semibold px-4 py-2 rounded-lg text-sm">Cerrar</button>
                <button type="button" onclick="window.print()" class="bg-teal-600 hover:bg-teal-700 text-white font-semibold px-4 py-2 rounded-lg text-sm transition flex items-center gap-1.5">
                    🖨️ Imprimir / Descargar Reporte
                </button>
            </div>
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

        // Cambiar de producto al seleccionarlo
        function cambiarProducto(codigo) {
            window.location.href = "detalle.php?codigo=" + encodeURIComponent(codigo);
        }

        // ABRIR REPORTE CON LOS DATOS EXACTOS DEL PRODUCTO ACTUAL
        function abrirReporte() {
            document.getElementById('repNombre').innerText = "<?php echo htmlspecialchars($productoDetalle['producto'] ?? ''); ?>";
            document.getElementById('repId').innerText = "<?php echo htmlspecialchars($productoDetalle['codigo'] ?? ''); ?>";
            document.getElementById('repNombreProd').innerText = "<?php echo htmlspecialchars($productoDetalle['producto'] ?? ''); ?>";
            document.getElementById('repCategoria').innerText = "<?php echo htmlspecialchars($productoDetalle['categoria'] ?? ''); ?>";
            document.getElementById('repUbicacion').innerText = "<?php echo htmlspecialchars($productoDetalle['ubicacion'] ?? ''); ?>";
            document.getElementById('repCantidad').innerText = "<?php echo htmlspecialchars($productoDetalle['cantidad'] ?? ''); ?>";
            document.getElementById('repVencimiento').innerText = "<?php echo htmlspecialchars($productoDetalle['vencimiento'] ?? 'N/A'); ?>";
            
            const nota = document.getElementById('inputNota').value;
            if(nota.trim() !== "") {
                document.getElementById('repNotaTexto').innerText = nota;
            }

            document.getElementById('modalReporte').classList.remove('hidden');
        }

        function cerrarReporte() {
            document.getElementById('modalReporte').classList.add('hidden');
        }
    </script>
</body>
</html>