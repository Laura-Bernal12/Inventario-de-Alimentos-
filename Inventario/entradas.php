<?php
// Incluir la conexión PDO centralizada
require_once 'conexion.php';
session_start();

// Validación estricta de sesión igual que en las demás vistas
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['nombre'])) {
    header("Location: index.php");
    exit();
}

$nombreUsuario = $_SESSION['nombre'];
$rolUsuario = $_SESSION['rol'] ?? 'Usuario';

// Consultar directamente el correo actualizado desde la base de datos de XAMPP
$emailUsuario = '';
try {
    $stmtMail = $conexion->prepare("SELECT email FROM usuarios WHERE id = ?");
    $stmtMail->execute([$_SESSION['usuario_id']]);
    $resMail = $stmtMail->fetch(PDO::FETCH_ASSOC);
    
    if ($resMail && !empty($resMail['email'])) {
        $emailUsuario = $resMail['email'];
        $_SESSION['email'] = $emailUsuario; // Actualiza la sesión con el correo real
    } else {
        $emailUsuario = $_SESSION['email'] ?? 'usuario@instituto.edu.sv';
    }
} catch (Exception $e) {
    $emailUsuario = $_SESSION['email'] ?? 'usuario@instituto.edu.sv';
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
                
                // Modificado a 10 días o menos
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

$productosBD = [];
try {
    // Consultamos directamente el catálogo en XAMPP
    $stmt = $conexion->query("SELECT producto, cantidad, categoria FROM catalogo");
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($resultados as $row) {
        $cantStr = trim($row['cantidad']);
        preg_match('/^([\d.,]+)\s*(.*)$/', $cantStr, $matches);
        $num = isset($matches[1]) ? floatval(str_replace(',', '.', $matches[1])) : floatval($cantStr);
        $unidad = isset($matches[2]) && !empty($matches[2]) ? trim($matches[2]) : '';

        // Si en el catálogo no le pusieron unidad
        if (empty($unidad)) {
            $prodLower = strtolower(trim($row['producto']));
            if ($prodLower === 'cereal' || $prodLower === 'frijoles' || $prodLower === 'arroz' || $prodLower === 'azúcar') {
                $unidad = 'lb';
            } elseif ($prodLower === 'leche' || $prodLower === 'aceite') {
                $unidad = 'L';
            } else {
                $unidad = 'unidades';
            }
        }

        $productosBD[] = [
            'nombre' => $row['producto'],
            'cantidad' => $num,
            'unidad' => $unidad
        ];
    }
} catch (Exception $e) {
    $productosBD = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entradas y Menús - Inventario Escolar</title>
    <link href="./src3/output3.css" rel="stylesheet">
    <style>
        html { box-sizing: border-box; }
        *, *::before, *::after { box-sizing: inherit; }
        body { overflow-x: hidden; background: #f8fafc; font-family: Arial, sans-serif; }
        .modal { display: none; }
        .modal.activo { display: flex; }
        #menuInventario {
          position: fixed !important; top: 0 !important; bottom: 0 !important; left: 0 !important; right: auto !important;
          width: min(320px, 86vw) !important; height: 100dvh !important; overflow-y: auto !important;
          border-radius: 0 14px 14px 0 !important; z-index: 9999 !important; background: #fff;
          box-shadow: 8px 0 28px rgba(0,0,0,.14);
        }
        .fondo-menu {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 9000;
        }
        .fondo-menu.fondo-visible { display: block; }
        .oculto { display: none !important; }
    </style>
</head>

<body class="bg-slate-100 min-h-screen text-slate-900">

    <div id="fondoMenu" class="fondo-menu"></div>

    <!-- ENCABEZADO SUPERIOR CON USUARIO SINCRONIZADO -->
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
                        <a href="entradas.php" onclick="cerrarMenuInventario()" class="flex items-center gap-3 px-5 py-4 bg-purple-100 text-purple-700 font-semibold">🍽️ <span>Menú</span></a>
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
                    <h2 class="text-xl font-bold">Entradas y Menús</h2>
                    <p class="text-xs text-gray-500">Registro de alimentación e inventario de alimentos</p>
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
                        <a href="index.php" style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #dc2626; text-decoration: none; font-size: 13px;">🚪 <span>Cerrar sesión</span></a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <h2 class="text-3xl font-bold text-slate-900">
            Registro de entradas y menús
        </h2>
        <p class="text-slate-500 mt-2">
            Seleccione el menú y registre la cantidad de estudiantes que asistieron.
        </p>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mt-8">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-700 font-bold shrink-0">
                    1
                </div>
                <div class="flex-1">
                    <h3 class="text-xl font-bold">
                        Buscar menú
                    </h3>
                    <p class="text-sm text-slate-500 mt-1">
                        Escriba el nombre del menú que desea consultar.
                    </p>
                </div>
            </div>

            <div class="flex gap-3 mt-5">
                <input
                    type="text"
                    id="buscarMenu"
                    placeholder="Ejemplo: pupusas, casamiento, cereal..."
                    onkeyup="buscarMenus()"
                    class="flex-1 border border-slate-300 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-emerald-500">
                <button
                    onclick="buscarMenus()"
                    class="bg-emerald-600 text-white px-8 rounded-xl font-bold hover:bg-emerald-700">
                    Buscar
                </button>
            </div>
        </section>

        <div class="flex justify-between items-center mt-7 mb-4">
            <h2 class="text-xl font-bold">
                Menús disponibles
            </h2>
            <button
                onclick="abrirAgregarMenu()"
                class="bg-emerald-600 text-white px-5 py-3 rounded-xl font-bold hover:bg-emerald-700">
                + Agregar menú
            </button>
        </div>

        <section
            id="listaMenus"
            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        </section>

        <section
            id="seccionCalculo"
            class="hidden bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mt-8">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold">
                        Registro del menú
                    </h2>
                    <p
                        id="nombreMenuSeleccionado"
                        class="text-emerald-700 font-semibold mt-1">
                    </p>
                </div>
                <button
                    onclick="cerrarCalculo()"
                    class="bg-slate-200 px-4 py-2 rounded-lg">
                    Cerrar
                </button>
            </div>

            <div class="grid md:grid-cols-3 gap-4 mt-6">
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-sm text-slate-500">
                        Fecha del menú
                    </p>
                    <p
                        id="fechaMenuSeleccionado"
                        class="font-bold mt-1">
                    </p>
                </div>
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-sm text-slate-500">
                        Raciones de referencia
                    </p>
                    <p
                        id="racionesReferencia"
                        class="font-bold mt-1">
                    </p>
                </div>
                <div class="bg-slate-50 rounded-xl p-4">
                    <p class="text-sm text-slate-500">
                        Fecha y hora actual
                    </p>
                    <p
                        id="fechaHoraActual"
                        class="font-bold mt-1">
                    </p>
                </div>
            </div>

            <div class="mt-6">
                <label class="font-bold">
                    ¿Cuántos estudiantes asistieron ese día?
                </label>
                <input
                    type="number"
                    id="estudiantes"
                    min="1"
                    placeholder="Escriba la cantidad de estudiantes"
                    class="w-full md:w-1/2 mt-2 border border-slate-300 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-emerald-500">
            </div>

            <button
                onclick="calcularRaciones()"
                class="bg-emerald-600 text-white px-6 py-3 rounded-xl font-bold mt-5 hover:bg-emerald-700">
                Calcular raciones
            </button>

            <div
                id="resultado"
                class="hidden mt-7">
                <div class="grid md:grid-cols-3 gap-4">
                    <div class="bg-blue-50 rounded-xl p-5">
                        <p class="text-sm text-slate-500">
                            Estudiantes
                        </p>
                        <p
                            id="totalEstudiantes"
                            class="text-2xl font-bold text-blue-700">
                        </p>
                    </div>
                    <div class="bg-emerald-50 rounded-xl p-5">
                        <p class="text-sm text-slate-500">
                            Porciones a preparar
                        </p>
                        <p
                            id="porcionesPreparar"
                            class="text-2xl font-bold text-emerald-700">
                        </p>
                    </div>
                    <div class="bg-purple-50 rounded-xl p-5">
                        <p class="text-sm text-slate-500">
                            Porciones a entregar
                        </p>
                        <p
                            id="porcionesEntregar"
                            class="text-2xl font-bold text-purple-700">
                        </p>
                    </div>
                </div>

                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5 mt-5">
                    <h3 class="font-bold text-yellow-800">
                        Ración que recibe cada estudiante
                    </h3>
                    <p class="text-sm text-slate-600 mt-1">
                        Aquí se muestra cuánto corresponde de cada alimento a un estudiante.
                    </p>
                </div>

                <div class="overflow-x-auto mt-6">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-slate-800 text-white">
                                <th class="border px-4 py-3">No.</th>
                                <th class="border px-4 py-3 text-left">Alimento</th>
                                <th class="border px-4 py-3 text-left">Cantidad de referencia</th>
                                <th class="border px-4 py-3 text-left">Ración por estudiante</th>
                                <th class="border px-4 py-3 text-left">Porciones</th>
                                <th class="border px-4 py-3 text-left">Cantidad total necesaria</th>
                            </tr>
                        </thead>
                        <tbody id="tablaAlimentos">
                        </tbody>
                    </table>
                </div>

                <div class="grid md:grid-cols-2 gap-4 mt-6">
                    <div class="bg-slate-50 rounded-xl p-4">
                        <p class="text-sm text-slate-500">Fecha del registro</p>
                        <p id="fechaRegistro" class="font-bold"></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-4">
                        <p class="text-sm text-slate-500">Hora del registro</p>
                        <p id="horaRegistro" class="font-bold"></p>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button
                        onclick="guardarRegistro()"
                        class="bg-emerald-600 text-white px-6 py-3 rounded-xl font-bold hover:bg-emerald-700">
                        Guardar registro
                    </button>
                    <button
                        onclick="mostrarRegistros()"
                        class="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold hover:bg-indigo-700">
                        Ver registros
                    </button>
                </div>
            </div>
        </section>

        <section
            id="historial"
            class="hidden bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mt-8">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-2xl font-bold">
                        Registros guardados
                    </h2>
                    <p class="text-slate-500">
                        Aquí puedes consultar lo que se dio cada día.
                    </p>
                </div>
                <button
                    onclick="cerrarHistorial()"
                    class="bg-slate-200 px-4 py-2 rounded-lg">
                    Cerrar
                </button>
            </div>
            <div id="listaRegistros" class="mt-6"></div>
        </section>
    </main>

    <div
        id="modalMenu"
        class="modal fixed inset-0 bg-black/50 items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b">
                <div class="flex justify-between items-center">
                    <h2 id="tituloModal" class="text-2xl font-bold">Agregar menú</h2>
                    <button onclick="cerrarModal()" class="text-2xl text-slate-500">×</button>
                </div>
            </div>
            <div class="p-6">
                <input type="hidden" id="indiceEditar">
                <label class="font-bold">Nombre del menú</label>
                <input id="nuevoNombre" type="text" placeholder="Ejemplo: Pupusas de arroz con frijoles y bebida" class="w-full border border-slate-300 rounded-xl px-4 py-3 mt-2 mb-5">
                <label class="font-bold">Fecha del menú</label>
                <input id="nuevaFecha" type="date" class="w-full border border-slate-300 rounded-xl px-4 py-3 mt-2 mb-5">
                <label class="font-bold">Raciones de referencia</label>
                <input id="nuevasRaciones" type="number" min="1" placeholder="Ejemplo: 197" class="w-full border border-slate-300 rounded-xl px-4 py-3 mt-2 mb-5">

                <div class="flex justify-between items-center mb-3">
                    <label class="font-bold">Alimentos</label>
                    <button onclick="agregarAlimento()" class="bg-emerald-600 text-white px-4 py-2 rounded-lg">+ Agregar alimento</button>
                </div>

                <div id="listaAlimentosModal"></div>

                <div class="flex justify-end gap-3 mt-6 pt-5 border-t">
                    <button onclick="cerrarModal()" class="bg-slate-200 px-5 py-3 rounded-xl font-bold">Cancelar</button>
                    <button onclick="guardarMenu()" class="bg-emerald-600 text-white px-5 py-3 rounded-xl font-bold">Guardar menú</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Inyectar productos reales desde la base de datos de XAMPP
        const productosDesdeDB = <?php echo json_encode($productosBD); ?>;

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

        const menusIniciales = [
            {
                id: "menu1",
                nombre: "Pupusas de arroz con frijoles y bebida",
                fecha: "02/02/2026",
                racionesBase: 197,
                alimentos: [
                    { nombre: "Arroz", cantidad: 3.74, unidad: "lb" },
                    { nombre: "Aceite", cantidad: 4.68, unidad: "L" },
                    { nombre: "Frijoles", cantidad: 2.81, unidad: "lb" },
                    { nombre: "Bebida", cantidad: 197, unidad: "porciones" }
                ]
            },
            {
                id: "menu2",
                nombre: "Casamiento y bebida",
                fecha: "03/02/2026",
                racionesBase: 202,
                alimentos: [
                    { nombre: "Arroz", cantidad: 3.03, unidad: "lb" },
                    { nombre: "Frijoles", cantidad: 4.04, unidad: "lb" },
                    { nombre: "Aceite", cantidad: 1.62, unidad: "L" },
                    { nombre: "Bebida", cantidad: 202, unidad: "porciones" }
                ]
            },
            {
                id: "menu3",
                nombre: "Arroz con leche",
                fecha: "04/02/2026",
                racionesBase: 198,
                alimentos: [
                    { nombre: "Arroz", cantidad: 4.95, unidad: "lb" },
                    { nombre: "Leche", cantidad: 4.95, unidad: "L" },
                    { nombre: "Azúcar", cantidad: 2.97, unidad: "lb" }
                ]
            },
            {
                id: "menu4",
                nombre: "Ensalada de frijoles con arroz, vegetales y bebida",
                fecha: "05/02/2026",
                racionesBase: 205,
                alimentos: [
                    { nombre: "Frijoles", cantidad: 3.08, unidad: "lb" },
                    { nombre: "Arroz", cantidad: 3.08, unidad: "lb" },
                    { nombre: "Vegetales", cantidad: 2, unidad: "lb" },
                    { nombre: "Bebida", cantidad: 205, unidad: "porciones" }
                ]
            },
            {
                id: "menu5",
                nombre: "Cereal con leche",
                fecha: "06/02/2026",
                racionesBase: 187,
                alimentos: [
                    { nombre: "Cereal", cantidad: 5.91, unidad: "lb" },
                    { nombre: "Leche", cantidad: 5.91, unidad: "L" }
                ]
            },
            {
                id: "menu6",
                nombre: "Frijoles molidos y bebida",
                fecha: "09/02/2026",
                racionesBase: 212,
                alimentos: [
                    { nombre: "Frijoles", cantidad: 5.30, unidad: "lb" },
                    { nombre: "Aceite", cantidad: 3.18, unidad: "L" },
                    { nombre: "Bebida", cantidad: 212, unidad: "porciones" }
                ]
            }
        ];

        let menus = JSON.parse(localStorage.getItem("menusAlimentacion"));

        if (!menus) {
            menus = menusIniciales.map(menu => ({
                ...menu,
                fechaAgregado: null,
                horaAgregado: null,
                fechaModificado: null,
                horaModificado: null
            }));
            guardarMenus();
        }

        let menuSeleccionado = null;
        let registroGuardado = false;

        function guardarMenus() {
            localStorage.setItem("menusAlimentacion", JSON.stringify(menus));
        }

        function obtenerFechaHora() {
            const ahora = new Date();
            return {
                fecha: ahora.toLocaleDateString("es-SV", { day: "2-digit", month: "2-digit", year: "numeric" }),
                hora: ahora.toLocaleTimeString("es-SV", { hour: "2-digit", minute: "2-digit", second: "2-digit" })
            };
        }

        const UNIDADES_MENU = {
            lb: { nombres: ["lb", "lbs", "libra", "libras"], tipo: "peso", factor: 453.592 },
            kg: { nombres: ["kg", "kgs", "kilo", "kilos", "kilogramo", "kilogramos"], tipo: "peso", factor: 1000 },
            g: { nombres: ["g", "gr", "gramo", "gramos"], tipo: "peso", factor: 1 },
            l: { nombres: ["l", "lt", "lts", "litro", "litros"], tipo: "volumen", factor: 1000 },
            ml: { nombres: ["ml", "mililitro", "mililitros"], tipo: "volumen", factor: 1 },
            un: { nombres: ["un", "pza", "pzas", "pieza", "piezas", "unidad", "unidades", "ud", "uds", "porcion", "porciones"], tipo: "unidad", factor: 1 }
        };

        function normalizarNombre(valor) {
            return String(valor ?? "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim().toLowerCase();
        }

        function numeroDe(valor) {
            if (typeof valor === "number") return valor;
            const coincidencia = String(valor ?? "").replace(/,/g, ".").match(/[0-9]+(?:\.[0-9]+)?/);
            return coincidencia ? Number(coincidencia[0]) : NaN;
        }

        function claveUnidad(unidad) {
            const u = normalizarNombre(unidad).replace(/\./g, "");
            for (const clave in UNIDADES_MENU) {
                if (UNIDADES_MENU[clave].nombres.includes(u)) return clave;
            }
            return u;
        }

        function convertirUnidad(cantidad, deUnidad, aUnidad) {
            const de = claveUnidad(deUnidad);
            const a = claveUnidad(aUnidad);
            if (!de || !a || de === a) return cantidad;
            const origen = UNIDADES_MENU[de];
            const destino = UNIDADES_MENU[a];
            if (!origen || !destino || origen.tipo !== destino.tipo) return null;
            return cantidad * origen.factor / destino.factor;
        }

        function leerInventario() {
            if (Array.isArray(productosDesdeDB) && productosDesdeDB.length > 0) {
                return productosDesdeDB;
            }
            const claves = ["productosInventario", "productos", "inventarioEscolar"];
            for (const clave of claves) {
                try {
                    const valor = JSON.parse(localStorage.getItem(clave) || "null");
                    if (Array.isArray(valor) && valor.length > 0) return valor;
                } catch (error) {}
            }
            return [];
        }

        function buscarEnInventario(lista, nombre) {
            const buscado = normalizarNombre(nombre);
            if (!buscado) return undefined;
            const nombreDe = p => normalizarNombre(p.nombre ?? p.producto ?? p.nombreProducto);
            const exacto = lista.find(p => nombreDe(p) === buscado);
            if (exacto) return exacto;
            const parecidos = lista.filter(p => {
                const n = nombreDe(p);
                return n && (n.startsWith(buscado + " ") || buscado.startsWith(n + " "));
            });
            return parecidos.length === 1 ? parecidos[0] : undefined;
        }

        function cantidadDeProducto(producto) {
            const numero = numeroDe(producto.cantidad ?? producto.existencia ?? producto.stock ?? 0);
            return Number.isFinite(numero) ? numero : 0;
        }

        function redondearCantidad(numero) {
            return Number(Number(numero).toFixed(3));
        }

        function verificarInventarioMenu(alimentos) {
            const lista = leerInventario();
            const faltan = [];
            const insuficientes = [];
            const necesario = new Map();

            alimentos.forEach(alimento => {
                const total = Number(alimento.total.toFixed(2));
                if (!(total > 0)) return;
                const producto = buscarEnInventario(lista, alimento.nombre);
                if (!producto) {
                    if (!faltan.includes(alimento.nombre)) faltan.push(alimento.nombre);
                    return;
                }
                const unidadInventario = producto.unidad || "";
                const enInventario = convertirUnidad(total, alimento.unidad, unidadInventario || alimento.unidad);
                if (enInventario === null) {
                    insuficientes.push(alimento.nombre + ": la unidad del menú (" + alimento.unidad + ") no se puede comparar con la del inventario (" + unidadInventario + ")");
                    return;
                }
                necesario.set(producto, (necesario.get(producto) || 0) + enInventario);
            });

            necesario.forEach((cantidad, producto) => {
                const disponible = cantidadDeProducto(producto);
                if (cantidad > disponible + 1e-9) {
                    const unidad = producto.unidad ? " " + producto.unidad : "";
                    insuficientes.push((producto.nombre ?? "Producto") + ": se necesitan " + redondearCantidad(cantidad) + unidad + " y solo hay " + redondearCantidad(disponible) + unidad);
                }
            });

            return { faltan, insuficientes };
        }

        function mostrarMenus(lista) {
            const contenedor = document.getElementById("listaMenus");
            contenedor.innerHTML = "";

            if (lista.length === 0) {
                contenedor.innerHTML = `
                    <div class="col-span-full bg-white rounded-2xl p-8 text-center">
                        No se encontraron menús.
                    </div>
                `;
                return;
            }

            lista.forEach(menu => {
                const tarjeta = document.createElement("div");
                tarjeta.className = "bg-white rounded-2xl border border-slate-200 shadow-sm p-6";

                let informacionFecha = "";
                if (menu.fechaAgregado) {
                    informacionFecha += `
                        <p class="text-xs text-slate-400 mt-3">
                            Agregado: ${menu.fechaAgregado} ${menu.horaAgregado}
                        </p>
                    `;
                }
                if (menu.fechaModificado) {
                    informacionFecha += `
                        <p class="text-xs text-orange-500 mt-1">
                            Modificado: ${menu.fechaModificado} ${menu.horaModificado}
                        </p>
                    `;
                }

                tarjeta.innerHTML = `
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-slate-500">Fecha</p>
                            <p class="font-semibold text-blue-900">${menu.fecha}</p>
                        </div>
                        <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-xs font-semibold">
                            Menú
                        </span>
                    </div>

                    <h3 class="text-lg font-bold mt-6">${menu.nombre}</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Receta de referencia: ${menu.racionesBase} raciones
                    </p>

                    ${informacionFecha}

                    <button onclick="seleccionarMenu('${menu.id}')" class="w-full bg-emerald-600 text-white py-3 rounded-xl font-bold mt-5 hover:bg-emerald-700">
                        Seleccionar menú
                    </button>

                    <div class="grid grid-cols-2 gap-2 mt-3">
                        <button onclick="editarMenu('${menu.id}')" class="bg-yellow-100 text-yellow-700 py-2 rounded-lg font-semibold hover:bg-yellow-200">
                            ✏️ Editar
                        </button>
                        <button onclick="eliminarMenu('${menu.id}')" class="bg-red-100 text-red-700 py-2 rounded-lg font-semibold hover:bg-red-200">
                            🗑️ Eliminar
                        </button>
                    </div>
                `;
                contenedor.appendChild(tarjeta);
            });
        }

        function buscarMenus() {
            const texto = document.getElementById("buscarMenu").value.toLowerCase().trim();
            const resultados = menus.filter(menu => menu.nombre.toLowerCase().includes(texto));
            mostrarMenus(resultados);
        }

        function abrirAgregarMenu() {
            document.getElementById("tituloModal").textContent = "Agregar menú";
            document.getElementById("indiceEditar").value = "";
            document.getElementById("nuevoNombre").value = "";
            document.getElementById("nuevaFecha").value = "";
            document.getElementById("nuevasRaciones").value = "";
            document.getElementById("listaAlimentosModal").innerHTML = "";
            agregarAlimento();
            document.getElementById("modalMenu").classList.add("activo");
        }

        function agregarAlimento(nombre = "", cantidad = "", unidad = "") {
            const contenedor = document.getElementById("listaAlimentosModal");
            const fila = document.createElement("div");
            fila.className = "grid grid-cols-1 md:grid-cols-4 gap-2 mb-3";
            fila.innerHTML = `
                <input class="nombreAlimento border border-slate-300 rounded-lg px-3 py-2" placeholder="Alimento" value="${nombre}">
                <input class="cantidadAlimento border border-slate-300 rounded-lg px-3 py-2" type="number" step="0.01" placeholder="Cantidad" value="${cantidad}">
                <input class="unidadAlimento border border-slate-300 rounded-lg px-3 py-2" placeholder="Unidad" value="${unidad}">
                <button type="button" onclick="this.parentElement.remove()" class="bg-red-100 text-red-700 rounded-lg py-2 font-semibold">
                    🗑️ Quitar
                </button>
            `;
            contenedor.appendChild(fila);
        }

        function guardarMenu() {
            const nombre = document.getElementById("nuevoNombre").value.trim();
            const fechaInput = document.getElementById("nuevaFecha").value;
            const raciones = parseInt(document.getElementById("nuevasRaciones").value);

            if (!nombre) { alert("Escribe el nombre del menú."); return; }
            if (!fechaInput) { alert("Selecciona la fecha del menú."); return; }
            if (!raciones || raciones <= 0) { alert("Escribe las raciones de referencia."); return; }

            const nombres = document.querySelectorAll(".nombreAlimento");
            const cantidades = document.querySelectorAll(".cantidadAlimento");
            const unidades = document.querySelectorAll(".unidadAlimento");
            const alimentos = [];

            for (let i = 0; i < nombres.length; i++) {
                if (nombres[i].value.trim() && cantidades[i].value && unidades[i].value.trim()) {
                    alimentos.push({
                        nombre: nombres[i].value.trim(),
                        cantidad: parseFloat(cantidades[i].value),
                        unidad: unidades[i].value.trim()
                    });
                }
            }

            if (alimentos.length === 0) {
                alert("Agrega por lo menos un alimento.");
                return;
            }

            const partes = fechaInput.split("-");
            const fecha = `${partes[2]}/${partes[1]}/${partes[0]}`;
            const fechaHora = obtenerFechaHora();
            const indice = document.getElementById("indiceEditar").value;

            if (indice !== "") {
                const posicion = parseInt(indice);
                menus[posicion].nombre = nombre;
                menus[posicion].fecha = fecha;
                menus[posicion].racionesBase = raciones;
                menus[posicion].alimentos = alimentos;
                menus[posicion].fechaModificado = fechaHora.fecha;
                menus[posicion].horaModificado = fechaHora.hora;
                alert("✅ Menú actualizado correctamente.");
            } else {
                menus.push({
                    id: "menu-" + Date.now(),
                    nombre: nombre,
                    fecha: fecha,
                    racionesBase: raciones,
                    alimentos: alimentos,
                    fechaAgregado: fechaHora.fecha,
                    horaAgregado: fechaHora.hora,
                    fechaModificado: null,
                    horaModificado: null
                });
                alert("✅ Menú agregado correctamente.");
            }

            guardarMenus();
            mostrarMenus(menus);
            cerrarModal();
        }

        function editarMenu(id) {
            const indice = menus.findIndex(menu => menu.id === id);
            if (indice === -1) return;

            const menu = menus[indice];
            document.getElementById("tituloModal").textContent = "Editar menú";
            document.getElementById("indiceEditar").value = indice;
            document.getElementById("nuevoNombre").value = menu.nombre;

            const partes = menu.fecha.split("/");
            document.getElementById("nuevaFecha").value = `${partes[2]}-${partes[1]}-${partes[0]}`;
            document.getElementById("nuevasRaciones").value = menu.racionesBase;
            document.getElementById("listaAlimentosModal").innerHTML = "";

            menu.alimentos.forEach(alimento => {
                agregarAlimento(alimento.nombre, alimento.cantidad, alimento.unidad);
            });

            document.getElementById("modalMenu").classList.add("activo");
        }

        function eliminarMenu(id) {
            const indice = menus.findIndex(menu => menu.id === id);
            if (indice === -1) return;

            const confirmar = confirm("¿Seguro que deseas eliminar este menú?");
            if (!confirmar) return;

            menus.splice(indice, 1);
            guardarMenus();
            mostrarMenus(menus);
            alert("🗑️ Menú eliminado correctamente.");
        }

        function cerrarModal() {
            document.getElementById("modalMenu").classList.remove("activo");
        }

        function seleccionarMenu(id) {
            menuSeleccionado = menus.find(menu => menu.id === id);
            if (!menuSeleccionado) return;

            document.getElementById("seccionCalculo").classList.remove("hidden");
            document.getElementById("historial").classList.add("hidden");
            document.getElementById("nombreMenuSeleccionado").textContent = menuSeleccionado.nombre;
            document.getElementById("fechaMenuSeleccionado").textContent = menuSeleccionado.fecha;
            document.getElementById("racionesReferencia").textContent = menuSeleccionado.racionesBase;

            actualizarFechaHora();

            window.scrollTo({
                top: document.getElementById("seccionCalculo").offsetTop - 20,
                behavior: "smooth"
            });
        }

        function actualizarFechaHora() {
            const datos = obtenerFechaHora();
            document.getElementById("fechaHoraActual").textContent = datos.fecha + " " + datos.hora;
        }

        function calcularRaciones() {
            if (!menuSeleccionado) {
                alert("Selecciona un menú.");
                return;
            }

            const estudiantes = parseInt(document.getElementById("estudiantes").value);
            if (!estudiantes || estudiantes <= 0) {
                alert("Escribe una cantidad válida de estudiantes.");
                return;
            }

            registroGuardado = false;

            document.getElementById("totalEstudiantes").textContent = estudiantes;
            document.getElementById("porcionesPreparar").textContent = estudiantes;
            document.getElementById("porcionesEntregar").textContent = estudiantes;

            const tabla = document.getElementById("tablaAlimentos");
            tabla.innerHTML = "";

            menuSeleccionado.alimentos.forEach((alimento, indice) => {
                const racionIndividual = alimento.cantidad / menuSeleccionado.racionesBase;
                const total = racionIndividual * estudiantes;

                tabla.innerHTML += `
                    <tr>
                        <td class="border px-4 py-3">${indice + 1}</td>
                        <td class="border px-4 py-3 font-semibold">${alimento.nombre}</td>
                        <td class="border px-4 py-3">${alimento.cantidad.toFixed(2)} ${alimento.unidad}</td>
                        <td class="border px-4 py-3 text-emerald-700 font-bold">${racionIndividual.toFixed(3)} ${alimento.unidad}</td>
                        <td class="border px-4 py-3">${estudiantes}</td>
                        <td class="border px-4 py-3 font-bold">${total.toFixed(2)} ${alimento.unidad}</td>
                    </tr>
                `;
            });

            const datos = obtenerFechaHora();
            document.getElementById("fechaRegistro").textContent = datos.fecha;
            document.getElementById("horaRegistro").textContent = datos.hora;
            document.getElementById("resultado").classList.remove("hidden");
        }

        function guardarRegistro() {
            if (!menuSeleccionado) {
                alert("Selecciona un menú.");
                return;
            }

            const estudiantes = parseInt(document.getElementById("estudiantes").value);
            if (!estudiantes) {
                alert("Escribe primero la cantidad de estudiantes.");
                return;
            }

            if (registroGuardado) {
                alert("Este registro ya fue guardado.");
                return;
            }

            const datos = obtenerFechaHora();
            const alimentos = menuSeleccionado.alimentos.map(alimento => {
                const racion = alimento.cantidad / menuSeleccionado.racionesBase;
                return {
                    nombre: alimento.nombre,
                    unidad: alimento.unidad,
                    racionIndividual: racion,
                    total: racion * estudiantes
                };
            });

            const revision = verificarInventarioMenu(alimentos);
            if (revision.faltan.length > 0 || revision.insuficientes.length > 0) {
                let mensaje = "⚠️ No se guardó el registro y no salió nada del inventario.";
                if (revision.faltan.length > 0) {
                    mensaje += "\n\nEstos alimentos no están registrados en el inventario:\n" +
                        revision.faltan.map(nombre => "• " + nombre).join("\n");
                }
                if (revision.insuficientes.length > 0) {
                    mensaje += "\n\nNo alcanza el inventario:\n" +
                        revision.insuficientes.map(texto => "• " + texto).join("\n");
                }
                alert(mensaje);
                return;
            }

            let nombreResponsable = "<?php echo addslashes($nombreUsuario); ?>";

            fetch('guardar_salida.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json' 
                },
                body: JSON.stringify({
                    alimentos: alimentos,
                    responsable: nombreResponsable,
                    fecha: datos.fecha,
                    hora: datos.hora
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error("Error en el servidor HTTP: " + response.status);
                }
                return response.json();
            })
            .then(resultado => {
                if (resultado.success) {
                    registroGuardado = true;

                    const registros = JSON.parse(localStorage.getItem("registrosAlimentacion")) || [];
                    registros.push({
                        id: Date.now(),
                        menu: menuSeleccionado.nombre,
                        fechaMenu: menuSeleccionado.fecha,
                        fechaRegistro: datos.fecha,
                        horaRegistro: datos.hora,
                        estudiantes: estudiantes,
                        alimentos: alimentos
                    });
                    localStorage.setItem("registrosAlimentacion", JSON.stringify(registros));

                    alert("✅ ¡Registro guardado con éxito! Se descontó del catálogo en XAMPP y se registró en salidas.");
                    location.reload();
                } else {
                    alert("❌ Error al guardar en la base de datos: " + (resultado.error || 'Desconocido'));
                }
            })
            .catch(error => {
                console.error("Error detallado:", error);
                alert("❌ Ocurrió un error de conexión con el servidor de XAMPP.");
            });
        }

        function mostrarRegistros() {
            const historial = document.getElementById("historial");
            const lista = document.getElementById("listaRegistros");
            const registros = JSON.parse(localStorage.getItem("registrosAlimentacion")) || [];

            historial.classList.remove("hidden");
            lista.innerHTML = "";

            if (registros.length === 0) {
                lista.innerHTML = `
                    <div class="text-center text-slate-500 py-8">
                        No hay registros guardados todavía.
                    </div>
                `;
                return;
            }

            registros.slice().reverse().forEach(registro => {
                let filas = "";
                registro.alimentos.forEach((alimento, indice) => {
                    filas += `
                        <tr>
                            <td class="border px-3 py-2">${indice + 1}</td>
                            <td class="border px-3 py-2">${alimento.nombre}</td>
                            <td class="border px-3 py-2">${alimento.racionIndividual.toFixed(3)} ${alimento.unidad}</td>
                            <td class="border px-3 py-2 font-bold">${alimento.total.toFixed(2)} ${alimento.unidad}</td>
                        </tr>
                    `;
                });

                lista.innerHTML += `
                    <div class="border border-slate-200 rounded-xl p-5 mb-5">
                        <h3 class="text-xl font-bold text-emerald-700">${registro.menu}</h3>
                        <div class="grid md:grid-cols-3 gap-3 mt-3 text-sm">
                            <p>📅 Menú: <strong>${registro.fechaMenu}</strong></p>
                            <p>🗓️ Registro: <strong>${registro.fechaRegistro}</strong></p>
                            <p>🕐 Hora: <strong>${registro.horaRegistro}</strong></p>
                        </div>
                        <p class="mt-3">👩‍🎓 Estudiantes: <strong>${registro.estudiantes}</strong></p>
                        <div class="overflow-x-auto mt-4">
                            <table class="w-full border-collapse">
                                <thead>
                                    <tr class="bg-slate-100">
                                        <th class="border px-3 py-2">No.</th>
                                        <th class="border px-3 py-2 text-left">Alimento</th>
                                        <th class="border px-3 py-2 text-left">Ración individual</th>
                                        <th class="border px-3 py-2 text-left">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${filas}
                                </tbody>
                            </table>
                        </div>
                        <button onclick="eliminarRegistro(${registro.id})" class="bg-red-100 text-red-700 px-4 py-2 rounded-lg mt-4 font-semibold">
                            🗑️ Eliminar registro
                        </button>
                    </div>
                `;
            });
        }

        function eliminarRegistro(id) {
            if (!confirm("¿Seguro que deseas eliminar este registro?")) return;
            let registros = JSON.parse(localStorage.getItem("registrosAlimentacion")) || [];
            registros = registros.filter(registro => registro.id !== id);
            localStorage.setItem("registrosAlimentacion", JSON.stringify(registros));
            mostrarRegistros();
        }

        function cerrarHistorial() {
            document.getElementById("historial").classList.add("hidden");
        }

        function cerrarCalculo() {
            document.getElementById("seccionCalculo").classList.add("hidden");
        }

        mostrarMenus(menus);
    </script>
</body>
</html>