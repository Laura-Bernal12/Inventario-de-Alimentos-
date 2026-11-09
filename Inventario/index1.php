<?php
// Incluir tu archivo de conexión PDO
include 'conexion.php';
session_start();

// Validación estricta de sesión
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
        $_SESSION['email'] = $emailUsuario;
    } else {
        $emailUsuario = $_SESSION['email'] ?? 'josselinemiranda52@gmail.com';
    }
} catch (Exception $e) {
    $emailUsuario = $_SESSION['email'] ?? 'josselinemiranda52@gmail.com';
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
    // Manejo de errores silencioso para la BD
}

$totalAlertas = count($alertasStockBajo) + count($alertasVencimiento);

// Consultar los registros existentes para mostrarlos en la tabla web
$listaSalidas = [];
$errorBD = "";
try {
    $stmtSalidas = $conexion->query("SELECT * FROM reportes_salida_menu ORDER BY id DESC");
    $listaSalidas = $stmtSalidas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorBD = $e->getMessage();
}

// 1. Datos reales para la Gráfica de Dona (Agrupado por Producto desde las salidas)
$labelsDonut = [];
$valuesDonut = [];
if (!empty($listaSalidas)) {
    try {
        $stmtDonut = $conexion->query("SELECT Producto, SUM(CAST(REPLACE(REPLACE(Cantidad, ' L', ''), ' lb', '') AS DECIMAL(10,2))) as total_cant FROM reportes_salida_menu GROUP BY Producto");
        $datosDonut = $stmtDonut->fetchAll(PDO::FETCH_ASSOC);
        foreach ($datosDonut as $d) {
            $labelsDonut[] = $d['Producto'] ?? 'General';
            $valuesDonut[] = (float)$d['total_cant'];
        }
    } catch (Exception $e) {
        $labelsDonut = [];
        $valuesDonut = [];
    }
}

// 2. Datos reales para la Gráfica de Línea (Fechas y cantidades de salidas)
$labelsLine = [];
$valuesLine = [];
if (!empty($listaSalidas)) {
    try {
        $stmtLine = $conexion->query("SELECT Fecha, SUM(CAST(REPLACE(REPLACE(Cantidad, ' L', ''), ' lb', '') AS DECIMAL(10,2))) as total_cant FROM reportes_salida_menu GROUP BY Fecha ORDER BY Fecha ASC");
        $datosLine = $stmtLine->fetchAll(PDO::FETCH_ASSOC);
        foreach ($datosLine as $l) {
            $labelsLine[] = $l['Fecha'] ?? '';
            $valuesLine[] = (float)$l['total_cant'];
        }
    } catch (Exception $e) {
        $labelsLine = [];
        $valuesLine = [];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventario Escolar - Reportes de Salidas</title>
  <link rel="stylesheet" href="Estilos1.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    * { box-sizing: border-box; }
    body {
      background-color: #f1f5f9; /* Fondo general con tono profesional */
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      color: #1e293b;
      margin: 0;
    }
    .dashboard-container, .sistema, .main-content, .contenido { min-width: 0; margin-left: 0 !important; width: 100% !important; }
    .table-section, .table-card, .table-wrapper { max-width: 100%; min-width: 0; }
    .table-wrapper { width: 100%; overflow-x: auto; }
    #salidasTable { width: 100%; border-collapse: collapse; }
    #salidasTable th, #salidasTable td { padding: 12px 15px; text-align: left; white-space: nowrap; }
    .hidden { display: none !important; }

    #menuInventario {
      position: fixed !important; top: 0 !important; bottom: 0 !important; left: 0 !important; right: auto !important;
      width: min(320px, 86vw) !important; height: 100dvh !important; overflow-y: auto !important;
      border-radius: 0 14px 14px 0 !important; z-index: 9999 !important; background: #fff;
      box-shadow: 8px 0 28px rgba(0,0,0,.14);
      transform: translateX(-105%); visibility: hidden; transition: transform .25s ease, visibility .25s;
    }
    #menuInventario.abierto { transform: translateX(0); visibility: visible; }
    .fondo-menu { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, .4); z-index: 9000; }
    .fondo-menu.fondo-visible { display: block; }
    .btn-delete { background: #fee2e2; color: #dc2626; border: 0; padding: 6px 12px; border-radius: 6px; cursor: pointer; transition: background 0.2s; font-weight: 600; }
    .btn-delete:hover { background: #fca5a5; }
  </style>
</head>

<body>
  <div id="fondoMenu" class="fondo-menu" onclick="hambCerrar()"></div>

  <!-- MENÚ LATERAL ACTUALIZADO -->
  <div id="menuInventario" aria-hidden="true">
    <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between;">
      <div style="display: flex; align-items: center; gap: 12px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb; background: #fff; display: flex; align-items: center; justify-content: center;">
          <img src="./img/logo-instituto.jpg" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
        </div>
        <div>
          <p style="margin: 0; font-size: 16px; font-weight: 700; color: #1f2937;">Inventario</p>
          <p style="margin: 0; font-size: 12px; color: #6b7280;">Escolar</p>
        </div>
      </div>
      <button type="button" onclick="hambCerrar()" style="border: 0; background: #f3f4f6; font-size: 24px; width: 40px; height: 40px; border-radius: 10px; cursor: pointer;">×</button>
    </div>

    <a href="alimentos.php" style="display: flex; align-items: center; gap: 12px; padding: 16px 20px; color: #4b5563; text-decoration: none;" onclick="hambCerrar()">🏠 <span>Panel de Control</span></a>
    <a href="catalogo.php" style="display: flex; align-items: center; gap: 12px; padding: 16px 20px; color: #4b5563; text-decoration: none;" onclick="hambCerrar()">📦 <span>Catálogo</span></a>
    <a href="indext.php" style="display: flex; align-items: center; gap: 12px; padding: 16px 20px; color: #4b5563; text-decoration: none;" onclick="hambCerrar()">⬆️ <span>Entradas</span></a>
    <a href="entradas.php" style="display: flex; align-items: center; gap: 12px; padding: 16px 20px; color: #4b5563; text-decoration: none;" onclick="hambCerrar()">🍽️ <span>Menú</span></a>
    <a href="index1.php" style="display: flex; align-items: center; gap: 12px; padding: 16px 20px; background: #f3e8ff; color: #7e22ce; font-weight: 600; text-decoration: none;" onclick="hambCerrar()">⬇️ <span>Salidas</span></a>
    
    <!-- CONFIGURACIÓN AÑADIDA AL MENÚ LATERAL -->
    <button onclick="alert('⚙️ Configuración'); hambCerrar();" style="width: 100%; display: flex; align-items: center; gap: 12px; padding: 16px 20px; background: none; border: 0; color: #4b5563; font-size: 15px; text-align: left; cursor: pointer;">
      ⚙️ <span>Configuración</span>
    </button>

    <div style="border-top: 1px solid #e5e7eb; margin-top: 10px;">
      <a href="index.php" style="display: flex; align-items: center; gap: 12px; padding: 16px 20px; color: #dc2626; text-decoration: none;" onclick="hambCerrar()">🚪 <span>Cerrar sesión</span></a>
    </div>
  </div>

  <div class="dashboard-container sistema">
    <main class="main-content contenido">
      
      <header class="top-header cabecera" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 32px; background: #ffffff; border-bottom: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
        <div style="display: flex; align-items: center; gap: 16px;">
          <button type="button" onclick="hambAbrir()" style="width: 42px; height: 42px; border-radius: 10px; background: #f3e8ff; color: #7e22ce; font-size: 22px; border: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s;">☰</button>
          <div class="header-titles titulo">
            <h1 id="tituloModulo" style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0;">Reportes de Salidas</h1>
            <p id="subtituloModulo" style="font-size: 13px; color: #64748b; margin: 0;">Sistema de Control de Alimentos Escolares</p>
          </div>
        </div>

        <!-- PERFIL DE USUARIO Y CAMPANA DE NOTIFICACIONES -->
        <div style="display: flex; align-items: center; gap: 20px; position: relative;">
          
          <!-- CAMPANITA DE NOTIFICACIONES INTERACTIVA -->
          <div style="position: relative;">
              <div onclick="toggleNotificaciones(event)" style="position: relative; cursor: pointer; width: 40px; height: 40px; border-radius: 50%; background: #f8fafc; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;" class="hover:bg-gray-100">
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

          <div onclick="toggleUserDropdown(event)" style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 6px 12px; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0;" class="hover:bg-gray-100">
            <div style="width: 35px; height: 35px; border-radius: 50%; background-color: #7e22ce; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 15px;">👤</div>
            <div style="text-align: left;">
              <div style="font-size: 13px; font-weight: 700; color: #1e293b; line-height: 1.2;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
              <div style="font-size: 11px; color: #64748b; line-height: 1.2;"><?php echo htmlspecialchars($rolUsuario); ?></div>
            </div>
            <span style="font-size: 10px; color: #64748b; margin-left: 2px;">▼</span>
          </div>

          <!-- MENÚ DESPLEGABLE CON LAS OPCIONES SOLICITADAS -->
          <div id="userDropdownMenu" class="hidden" style="position: absolute; top: 55px; right: 0; width: 260px; background: white; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1000; padding: 15px;">
            <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 8px;">
              <div style="font-size: 14px; font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($nombreUsuario); ?></div>
              <div style="font-size: 12px; color: #64748b; word-break: break-all;"><?php echo htmlspecialchars($emailUsuario); ?></div>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 2px;">
              <a href="#" onclick="alert('Sección Mi cuenta'); return false;" style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;">👤 <span>Mi cuenta</span></a>
              <a href="#" onclick="alert('Agregar cuenta'); return false;" style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;">➕ <span>Agregar cuenta</span></a>
              <a href="#" onclick="alert('Cambiar cuenta'); return false;" style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;">🔄 <span>Cambiar cuenta</span></a>
              <a href="#" onclick="alert('Ayuda del sistema'); return false;" style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 6px; color: #334155; text-decoration: none; font-size: 13px;">❓ <span>Ayuda</span></a>
              <div style="border-top: 1px solid #f1f5f9; margin-top: 4px; padding-top: 4px;">
                <a href="index.php" style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 6px; color: #dc2626; text-decoration: none; font-size: 13px;">🚪 <span>Cerrar sesión</span></a>
              </div>
            </div>
          </div>

        </div>
      </header>

      <div id="contenidoModulo" style="padding: 30px; max-width: 1400px; margin: 0 auto;">
        
        <?php if (!empty($errorBD)): ?>
          <div style="background: #fee2e2; color: #b91c1c; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fecaca;">
            <strong>Error SQL en index1.php:</strong> <?php echo htmlspecialchars($errorBD); ?>
          </div>
        <?php endif; ?>

        <!-- SECCIÓN DE GRÁFICAS: TARJETAS CON SOMBRA SUAVE Y ESTILO PROFESIONAL -->
        <?php if (!empty($listaSalidas)): ?>
          <section class="charts-section" style="display: flex; gap: 24px; margin-bottom: 30px; flex-wrap: wrap;">
            <div class="chart-container line-chart-box" style="flex: 1; min-width: 320px; background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
              <canvas id="lineChart" style="max-height: 260px;"></canvas>
            </div>
            <div class="chart-container donut-chart-box" style="flex: 1; min-width: 320px; background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
              <canvas id="donutChart" style="max-height: 260px;"></canvas>
            </div>
          </section>
        <?php endif; ?>

        <section class="table-section">
          <div class="table-card" style="background: #ffffff; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
              <h2 style="font-size: 18px; font-weight: 700; color: #0f172a; margin: 0;">Registro de Salidas <span style="font-size: 13px; font-weight: 500; color: #64748b; background: #f1f5f9; padding: 4px 10px; border-radius: 20px; margin-left: 8px;">Total encontrados: <?php echo count($listaSalidas); ?></span></h2>
            </div>

            <div class="table-wrapper">
              <table id="salidasTable">
                <thead>
                  <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Destino</th>
                    <th>Responsable</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody id="tableBody" style="font-size: 14px; color: #334155;">
                  <?php if (!empty($listaSalidas)): ?>
                    <?php foreach ($listaSalidas as $row): 
                      $idSalida = $row['id'] ?? $row['ID'] ?? 0;
                    ?>
                      <tr id="fila-<?php echo $idSalida; ?>" style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                        <td style="font-weight: 600; color: #0f172a;"><?php echo htmlspecialchars($row['Producto'] ?? $row['producto'] ?? ''); ?></td>
                        <td><span style="background: #f3e8ff; color: #7e22ce; padding: 2px 8px; border-radius: 6px; font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($row['Cantidad'] ?? $row['cantidad'] ?? ''); ?></span></td>
                        <td><?php echo htmlspecialchars($row['Fecha'] ?? $row['fecha'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['Hora'] ?? $row['hora'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['Destino'] ?? $row['destino'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['Responsable'] ?? $row['responsable'] ?? ''); ?></td>
                        <td style="display: flex; gap: 8px; align-items: center;">
                          <button class="btn-icon" title="Editar" style="background: #f1f5f9; border: 0; padding: 6px 10px; border-radius: 6px; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">✏️</button>
                          <button class="btn-delete" title="Eliminar" onclick="eliminarSalida(<?php echo $idSalida; ?>)">🗑️</button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="7" style="text-align: center; color: #64748b; padding: 35px; font-weight: 600;">
                        No hay salidas registradas todavía.
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      <?php if (!empty($listaSalidas)): ?>
        // 1. Gráfica de Dona
        const ctxDonut = document.getElementById('donutChart');
        if (ctxDonut) {
          const labelsDona = <?php echo json_encode($labelsDonut); ?>;
          const valoresDona = <?php echo json_encode($valuesDonut); ?>;

          new Chart(ctxDonut, {
            type: 'doughnut',
            data: {
              labels: labelsDona,
              datasets: [{
                data: valoresDona,
                backgroundColor: ['#7e22ce', '#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6'],
                borderWidth: 2,
                borderColor: '#ffffff'
              }]
            },
            options: {
              responsive: true,
              plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Segoe UI', size: 12 } } },
                title: { display: true, text: 'Distribución por Productos Registrados', font: { family: 'Segoe UI', size: 14, weight: 'bold' }, color: '#0f172a', padding: { bottom: 20 } }
              }
            }
          });
        }

        // 2. Gráfica de Líneas
        const ctxLine = document.getElementById('lineChart');
        if (ctxLine) {
          const labelsLin = <?php echo json_encode($labelsLine); ?>;
          const valoresLin = <?php echo json_encode($valuesLine); ?>;

          new Chart(ctxLine, {
            type: 'line',
            data: {
              labels: labelsLin,
              datasets: [{
                label: 'Cantidad Saliente por Fecha',
                data: valoresLin,
                borderColor: '#7e22ce',
                backgroundColor: 'rgba(126, 34, 206, 0.08)',
                borderWidth: 3,
                fill: true,
                tension: 0.35,
                pointBackgroundColor: '#7e22ce',
                pointRadius: 4
              }]
            },
            options: {
              responsive: true,
              plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Segoe UI', size: 12 } } },
                title: { display: true, text: 'Historial de Salidas por Fecha', font: { family: 'Segoe UI', size: 14, weight: 'bold' }, color: '#0f172a', padding: { bottom: 20 } }
              },
              scales: {
                x: { grid: { display: false } },
                y: { grid: { color: '#f1f5f9' } }
              }
            }
          });
        }
      <?php endif; ?>
    });

    // Función para alternar el menú desplegable del usuario
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

    // Cerrar los menús desplegables si se hace clic fuera de ellos
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

    // Función para eliminar registro
    function eliminarSalida(id) {
      if (confirm("¿Estás segura de que deseas eliminar este registro de salida?")) {
        fetch('eliminar_salida.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: id })
        })
        .then(async res => {
          const text = await res.text();
          try {
            return JSON.parse(text);
          } catch (e) {
            throw new Error("El archivo PHP devolvió texto plano: " + text);
          }
        })
        .then(data => {
          if (data.success) {
            alert("✅ Registro eliminado correctamente.");
            location.reload();
          } else {
            alert("⚠️ Error al eliminar: " + (data.error || 'Ocurrió un problema'));
          }
        })
        .catch(err => {
          console.error(err);
          alert("⚠️ " + err.message);
        });
      }
    }

    function hambAbrir() {
      document.getElementById("menuInventario").classList.add("abierto");
      document.getElementById("fondoMenu").classList.add("fondo-visible");
    }
    function hambCerrar() {
      document.getElementById("menuInventario").classList.remove("abierto");
      document.getElementById("fondoMenu").classList.remove("fondo-visible");
    }
  </script>
</body>
</html>