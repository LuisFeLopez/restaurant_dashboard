<?php
require_once '../security.php';

// Start secure session
startSecureSession();

// Set security headers
setSecurityHeaders();

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Conexión a la base de datos
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "techsysup_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Obtener información del usuario
$username = $_SESSION['username'];
$user_sql = "SELECT ru.*, rb.name as branch_name FROM restaurant_users ru LEFT JOIN restaurant_branches rb ON ru.branch_id = rb.id WHERE ru.username = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$user_result = $stmt->get_result();
$user = $user_result->fetch_assoc();
$stmt->close();

if (!$user) {
    // Usuario no encontrado en restaurant_users
    header("Location: ../login.php");
    exit();
}

// Determinar la sede actual
$current_branch_id = isset($_GET['branch']) ? $_GET['branch'] : $user['branch_id'];
$current_branch_name = '';

// Si es admin de restaurante, puede cambiar sede
if ($_SESSION['role'] === 'restaurant') {
    if ($current_branch_id) {
        $branch_sql = "SELECT name FROM restaurant_branches WHERE id = ?";
        $stmt = $conn->prepare($branch_sql);
        $stmt->bind_param("i", $current_branch_id);
        $stmt->execute();
        $branch_result = $stmt->get_result();
        if ($branch_result->num_rows > 0) {
            $branch = $branch_result->fetch_assoc();
            $current_branch_name = $branch['name'];
        }
        $stmt->close();
    }
} else {
    $current_branch_name = $user['branch_name'];
}

// Obtener sedes disponibles (para admin)
$branches = [];
if ($_SESSION['role'] === 'restaurant') {
    $branches_sql = "SELECT id, name FROM restaurant_branches WHERE status = 'active' ORDER BY name";
    $branches_result = $conn->query($branches_sql);
    while ($row = $branches_result->fetch_assoc()) {
        $branches[] = $row;
    }
}

/* Comprueba si una tabla existe en la base de datos */
function tableExists($conn, $tableName)
{
    $tableNameEscaped = $conn->real_escape_string($tableName);
    $res = $conn->query("SHOW TABLES LIKE '" . $tableNameEscaped . "'");
    return ($res && $res->num_rows > 0);
}

// Función para obtener datos según la sección
function getSectionData($section, $branch_id, $conn)
{
    $data = [];
    switch ($section) {
        case 'mesas':
            // Obtener mesas de la sede
            $sql = "SELECT * FROM restaurant_tables WHERE branch_id = ? ORDER BY table_number";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
            break;
        case 'pedidos':
            // Obtener pedidos activos de la sede con items del menú
            // Usar la columna table_number de orders en lugar de join por table_id (evita errores si la columna no existe)
            $sql = "SELECT o.*, o.table_number, ru.full_name as user_name FROM orders o LEFT JOIN restaurant_users ru ON o.user_id = ru.id WHERE o.branch_id = ? AND o.status IN ('pending', 'preparing', 'ready', 'delivered') ORDER BY o.created_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                // Obtener los items del pedido
                $items_stmt = $conn->prepare("SELECT oi.quantity, oi.price, mi.name FROM order_items oi LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
                $items_stmt->bind_param("i", $row['id']);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $order_items = [];
                while ($item_row = $items_result->fetch_assoc()) {
                    $order_items[] = $item_row;
                }
                $row['items'] = $order_items;
                $items_stmt->close();
                $data[] = $row;
            }
            $stmt->close();
            break;
        case 'cocina':
            // Pedidos en cocina con items del menú
            $sql = "SELECT o.*, o.table_number, ru.full_name as user_name FROM orders o LEFT JOIN restaurant_users ru ON o.user_id = ru.id WHERE o.branch_id = ? AND o.status = 'preparing' ORDER BY o.created_at ASC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                // Obtener los items del pedido
                $items_stmt = $conn->prepare("SELECT oi.quantity, oi.price, mi.name FROM order_items oi LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
                $items_stmt->bind_param("i", $row['id']);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $order_items = [];
                while ($item_row = $items_result->fetch_assoc()) {
                    $order_items[] = $item_row;
                }
                $row['items'] = $order_items;
                $items_stmt->close();
                $data[] = $row;
            }
            $stmt->close();
            break;
        case 'menu':
            // Ítems de menú
            $sql = "SELECT mi.*, mc.name as category_name FROM menu_items mi LEFT JOIN menu_categories mc ON mi.category_id = mc.id WHERE mi.status = 'active' ORDER BY mc.name, mi.name";
            $result = $conn->query($sql);
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            break;
        case 'inventarios':
            // Inventario
            $sql = "SELECT * FROM inventory_items WHERE status = 'active' ORDER BY name";
            $result = $conn->query($sql);
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            break;
        case 'meseros':
            // Meseros de la sede
            $sql = "SELECT ru.* FROM restaurant_users ru WHERE ru.branch_id = ? AND ru.role = 'staff' AND ru.status = 'active' ORDER BY ru.full_name";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
            break;
        case 'caja':
            // Transacciones de caja del día
            $today = date('Y-m-d');
            if (!tableExists($conn, 'cash_register_transactions')) {
                // Tabla de transacciones no existe: devolver array vacío
                break;
            }
            $sql = "SELECT * FROM cash_register_transactions WHERE branch_id = ? AND DATE(created_at) = ? ORDER BY created_at DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $branch_id, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
            break;
        case 'reportes':
            // Reportes básicos
            $data['total_orders'] = 0;
            $data['total_sales'] = 0;
            $data['pending_orders'] = 0;
            // Implementar consultas para reportes
            break;
    }
    return $data;
}

// Procesar acciones POST
$message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'add_table') {
            $table_number = $_POST['table_number'] ?? '';
            if (!empty($table_number) && $current_branch_id) {
                // Check if table number already exists for this branch
                $check_stmt = $conn->prepare("SELECT id FROM restaurant_tables WHERE branch_id = ? AND table_number = ?");
                $check_stmt->bind_param("is", $current_branch_id, $table_number);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows > 0) {
                    $message = "Error: Ya existe una mesa con el número '$table_number' en esta sede.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO restaurant_tables (branch_id, table_number, status) VALUES (?, ?, 'available')");
                    $stmt->bind_param("is", $current_branch_id, $table_number);
                    if ($stmt->execute()) {
                        $message = "Mesa agregada exitosamente.";
                    } else {
                        $message = "Error al agregar la mesa.";
                    }
                    $stmt->close();
                }
                $check_stmt->close();
            }
        } elseif ($action === 'delete_table') {
            $table_number = $_POST['table_number'] ?? '';
            if (!empty($table_number) && $current_branch_id) {
                // Check if table exists for this branch
                $check_stmt = $conn->prepare("SELECT id FROM restaurant_tables WHERE branch_id = ? AND table_number = ?");
                $check_stmt->bind_param("is", $current_branch_id, $table_number);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows == 0) {
                    $message = "Error: No existe una mesa con el número '$table_number' en esta sede.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM restaurant_tables WHERE branch_id = ? AND table_number = ?");
                    $stmt->bind_param("is", $current_branch_id, $table_number);
                    if ($stmt->execute()) {
                        $message = "Mesa eliminada exitosamente.";
                    } else {
                        $message = "Error al eliminar la mesa.";
                    }
                    $stmt->close();
                }
                $check_stmt->close();
            }
        } elseif ($action === 'add_order') {
            $table_id = (int) ($_POST['table_id'] ?? 0);
            $quantities = $_POST['quantity'] ?? [];
            $notes = $_POST['notes'] ?? '';

            // Debug: log the received data
            error_log("DEBUG add_order START: table_id=$table_id, current_branch_id=$current_branch_id, quantities=" . json_encode($quantities));

            if ($table_id > 0 && !empty($quantities) && $current_branch_id) {
                error_log("DEBUG: Condition passed");
                // Get table number
                $table_stmt = $conn->prepare("SELECT table_number FROM restaurant_tables WHERE id = ? AND branch_id = ?");
                $table_stmt->bind_param("ii", $table_id, $current_branch_id);
                $table_stmt->execute();
                $table_result = $table_stmt->get_result();
                if ($table_result->num_rows > 0) {
                    $table = $table_result->fetch_assoc();
                    $table_number = $table['table_number'];
                    error_log("DEBUG: Table found, table_number=$table_number");
                    $table_stmt->close();
                } else {
                    $message = "Mesa no encontrada.";
                    error_log("DEBUG: Table NOT found for table_id=$table_id, branch_id=$current_branch_id");
                    $table_stmt->close();
                }

                // Calculate total
                $total = 0.0;
                $order_items = [];
                foreach ($quantities as $item_id => $qty) {
                    $qty = (int) $qty;
                    if ($qty > 0) {
                        // Get item price
                        $item_stmt = $conn->prepare("SELECT price FROM menu_items WHERE id = ?");
                        $item_stmt->bind_param("i", $item_id);
                        $item_stmt->execute();
                        $item_result = $item_stmt->get_result();
                        if ($item_result->num_rows > 0) {
                            $item = $item_result->fetch_assoc();
                            $total += $item['price'] * $qty;
                            $order_items[] = ['item_id' => (int) $item_id, 'quantity' => $qty, 'price' => (float) $item['price']];
                        }
                        $item_stmt->close();
                    }
                }
                if ($total > 0) {
                    error_log("DEBUG: Total > 0, ready to insert. Total=$total, table_number=$table_number, user_id={$_SESSION['user_id']}, branch_id=$current_branch_id, notes=$notes");
                    error_log("DEBUG: Order items count: " . count($order_items));
                    // Insert order
                    $order_stmt = $conn->prepare("INSERT INTO orders (table_number, user_id, branch_id, total, status, notes, created_at) VALUES (?, ?, ?, ?, 'pending', ?, NOW())");
                    if (!$order_stmt) {
                        $message = "Error preparando consulta: " . $conn->error;
                        error_log("DEBUG: Prepare failed: " . $conn->error);
                    } else {
                        // Create variables for bind_param (must be actual variables, not expressions)
                        $user_id_int = (int) $_SESSION['user_id'];
                        $branch_id_int = (int) $current_branch_id;
                        $order_stmt->bind_param("siids", $table_number, $user_id_int, $branch_id_int, $total, $notes);
                        error_log("DEBUG: About to execute INSERT");
                        if ($order_stmt->execute()) {
                            $order_id = $conn->insert_id;
                            error_log("DEBUG: Order created successfully, order_id=$order_id");
                            // Insert order items
                            foreach ($order_items as $item) {
                                $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price) VALUES (?, ?, ?, ?)");
                                if (!$item_stmt) {
                                    error_log("DEBUG: Prepare failed for order items: " . $conn->error);
                                    continue;
                                }
                                $item_stmt->bind_param("iiid", $order_id, $item['item_id'], $item['quantity'], $item['price']);
                                if (!$item_stmt->execute()) {
                                    $message = "Error al insertar item del pedido: " . $conn->error;
                                    error_log("DEBUG: Error inserting order item: " . $conn->error);
                                }
                                $item_stmt->close();
                            }
                            // Update table status to occupied
                            $update_stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE id = ? AND branch_id = ?");
                            if (!$update_stmt) {
                                error_log("DEBUG: Prepare failed for update table: " . $conn->error);
                            } else {
                                $update_stmt->bind_param("ii", $table_id, $current_branch_id);
                                if (!$update_stmt->execute()) {
                                    $message = "Error al actualizar estado de la mesa: " . $conn->error;
                                    error_log("DEBUG: Error updating table status: " . $conn->error);
                                }
                                $update_stmt->close();
                            }
                            $message = "Pedido enviado a cocina exitosamente.";
                            error_log("DEBUG: Order process completed successfully");
                        } else {
                            $message = "Error al crear el pedido: " . $conn->error;
                            error_log("DEBUG: Error creating order: " . $conn->error);
                        }
                        $order_stmt->close();
                    }
                } else {
                    $message = "No hay items seleccionados.";
                    error_log("DEBUG: Total is 0, no items selected");
                }
            } else {
                $message = "Datos inválidos para el pedido.";
                error_log("DEBUG: Condition FAILED - table_id=$table_id, empty(quantities)=" . (empty($quantities) ? '1' : '0') . ", current_branch_id=$current_branch_id");
            }
        } elseif ($action === 'update_order_status') {
            $order_id = (int) ($_POST['order_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $valid_statuses = ['pending', 'preparing', 'ready', 'delivered'];
            if ($order_id > 0 && in_array($status, $valid_statuses) && $current_branch_id) {
                // Check if order belongs to this branch
                $check_stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND branch_id = ?");
                $check_stmt->bind_param("ii", $order_id, $current_branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ? AND branch_id = ?");
                    $stmt->bind_param("sii", $status, $order_id, $current_branch_id);
                    if ($stmt->execute()) {
                        // Registrar en caja cuando pasa a "preparing"
                        if ($status === 'preparing') {
                            if (tableExists($conn, 'cash_register_transactions')) {
                                // Verificar si la transacción ya existe para este pedido
                                $check_trans_stmt = $conn->prepare("SELECT id FROM cash_register_transactions WHERE order_id = ? AND branch_id = ?");
                                $check_trans_stmt->bind_param("ii", $order_id, $current_branch_id);
                                $check_trans_stmt->execute();
                                $check_trans_result = $check_trans_stmt->get_result();

                                // Solo registrar si no existe una transacción previa
                                if ($check_trans_result->num_rows === 0) {
                                    // Obtener el total del pedido con todos los detalles
                                    $order_total_stmt = $conn->prepare("SELECT o.id, o.table_number, o.total, o.notes, o.created_at FROM orders o WHERE o.id = ?");
                                    $order_total_stmt->bind_param("i", $order_id);
                                    $order_total_stmt->execute();
                                    $order_total_result = $order_total_stmt->get_result();
                                    if ($order_total_result->num_rows > 0) {
                                        $order_data = $order_total_result->fetch_assoc();

                                        // Obtener los items del pedido para la descripción
                                        $items_stmt = $conn->prepare("SELECT oi.quantity, mi.name FROM order_items oi LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
                                        $items_stmt->bind_param("i", $order_id);
                                        $items_stmt->execute();
                                        $items_result = $items_stmt->get_result();

                                        $items_list = [];
                                        while ($item_row = $items_result->fetch_assoc()) {
                                            $items_list[] = $item_row['quantity'] . "x " . $item_row['name'];
                                        }
                                        $items_stmt->close();

                                        // Crear descripción detallada
                                        $items_desc = !empty($items_list) ? " - " . implode(", ", $items_list) : "";
                                        $description = "Pago de Pedido #" . $order_id . " (Mesa " . $order_data['table_number'] . ")" . $items_desc;

                                        // Insertar transacción en caja
                                        $cash_stmt = $conn->prepare("INSERT INTO cash_register_transactions (branch_id, order_id, user_id, amount, type, description, created_at) VALUES (?, ?, ?, ?, 'payment', ?, NOW())");
                                        $user_id = (int) $_SESSION['user_id'];
                                        $amount = (float) $order_data['total'];
                                        $cash_stmt->bind_param("iidds", $current_branch_id, $order_id, $user_id, $amount, $description);
                                        $cash_stmt->execute();
                                        $cash_stmt->close();
                                    }
                                    $order_total_stmt->close();
                                }
                                $check_trans_stmt->close();
                            }
                        }

                        if ($status === 'delivered') {
                            // Update table status to available
                            $table_stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_number = (SELECT table_number FROM orders WHERE id = ?) AND branch_id = ?");
                            $table_stmt->bind_param("ii", $order_id, $current_branch_id);
                            $table_stmt->execute();
                            $table_stmt->close();
                        }
                        $message = "Estado del pedido actualizado exitosamente.";
                    } else {
                        $message = "Error al actualizar el estado del pedido.";
                    }
                    $stmt->close();
                } else {
                    $message = "Pedido no encontrado.";
                }
                $check_stmt->close();
            } else {
                $message = "Datos inválidos.";
            }
        } elseif ($action === 'cancel_order') {
            $order_id = (int) ($_POST['order_id'] ?? 0);
            if ($order_id > 0 && $current_branch_id) {
                // Check if order belongs to this branch
                $check_stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND branch_id = ?");
                $check_stmt->bind_param("ii", $order_id, $current_branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND branch_id = ?");
                    $stmt->bind_param("ii", $order_id, $current_branch_id);
                    if ($stmt->execute()) {
                        // Update table status to available
                        $table_stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_number = (SELECT table_number FROM orders WHERE id = ?) AND branch_id = ?");
                        $table_stmt->bind_param("ii", $order_id, $current_branch_id);
                        $table_stmt->execute();
                        $table_stmt->close();
                        $message = "Pedido cancelado exitosamente.";
                    } else {
                        $message = "Error al cancelar el pedido.";
                    }
                    $stmt->close();
                } else {
                    $message = "Pedido no encontrado.";
                }
                $check_stmt->close();
            } else {
                $message = "Datos inválidos.";
            }
        } elseif ($action === 'complete_order') {
            $order_id = (int) ($_POST['order_id'] ?? 0);
            if ($order_id > 0 && $current_branch_id) {
                // Check if order belongs to this branch
                $check_stmt = $conn->prepare("SELECT id, table_number FROM orders WHERE id = ? AND branch_id = ?");
                $check_stmt->bind_param("ii", $order_id, $current_branch_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows > 0) {
                    $order_row = $check_result->fetch_assoc();
                    // Update order status to completed
                    $stmt = $conn->prepare("UPDATE orders SET status = 'completed' WHERE id = ? AND branch_id = ?");
                    $stmt->bind_param("ii", $order_id, $current_branch_id);
                    if ($stmt->execute()) {
                        // Update table status to available
                        $table_stmt = $conn->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_number = ? AND branch_id = ?");
                        $table_stmt->bind_param("ii", $order_row['table_number'], $current_branch_id);
                        $table_stmt->execute();
                        $table_stmt->close();

                        // Registrar en caja cuando se finaliza el pedido
                        if (tableExists($conn, 'cash_register_transactions')) {
                            // Obtener el total del pedido con todos los detalles
                            $order_total_stmt = $conn->prepare("SELECT o.id, o.table_number, o.total, o.notes, o.created_at FROM orders o WHERE o.id = ?");
                            $order_total_stmt->bind_param("i", $order_id);
                            $order_total_stmt->execute();
                            $order_total_result = $order_total_stmt->get_result();
                            if ($order_total_result->num_rows > 0) {
                                $order_data = $order_total_result->fetch_assoc();

                                // Obtener los items del pedido para la descripción
                                $items_stmt = $conn->prepare("SELECT oi.quantity, mi.name FROM order_items oi LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
                                $items_stmt->bind_param("i", $order_id);
                                $items_stmt->execute();
                                $items_result = $items_stmt->get_result();

                                $items_list = [];
                                while ($item_row = $items_result->fetch_assoc()) {
                                    $items_list[] = $item_row['quantity'] . "x " . $item_row['name'];
                                }
                                $items_stmt->close();

                                // Crear descripción detallada
                                $items_desc = !empty($items_list) ? " - " . implode(", ", $items_list) : "";
                                $description = "Finalizado Pedido #" . $order_id . " (Mesa " . $order_data['table_number'] . ")" . $items_desc;

                                // Actualizar transacción en caja con estado de finalizado
                                $cash_update = $conn->prepare("UPDATE cash_register_transactions SET description = ? WHERE order_id = ? AND branch_id = ?");
                                $cash_update->bind_param("sii", $description, $order_id, $current_branch_id);
                                $cash_update->execute();
                                $cash_update->close();
                            }
                            $order_total_stmt->close();
                        }

                        $message = "Pedido completado y guardado en el historial.";
                    } else {
                        $message = "Error al completar el pedido.";
                    }
                    $stmt->close();
                } else {
                    $message = "Pedido no encontrado.";
                }
                $check_stmt->close();
            } else {
                $message = "Datos inválidos.";
            }
        }
    }
}

// Acción AJAX para obtener datos del pedido (para imprimir)
if (isset($_GET['action']) && $_GET['action'] === 'get_order_data') {
    header('Content-Type: application/json');
    $order_id = (int) ($_GET['order_id'] ?? 0);
    if ($order_id > 0) {
        // Obtener datos del pedido
        $order_stmt = $conn->prepare("SELECT o.id, o.table_number, o.total, o.notes, o.created_at, ru.full_name as user_name FROM orders o LEFT JOIN restaurant_users ru ON o.user_id = ru.id WHERE o.id = ? AND o.branch_id = ?");
        $order_stmt->bind_param("ii", $order_id, $current_branch_id);
        $order_stmt->execute();
        $order_result = $order_stmt->get_result();

        if ($order_result->num_rows > 0) {
            $order = $order_result->fetch_assoc();

            // Obtener items del pedido
            $items_stmt = $conn->prepare("SELECT oi.quantity, oi.price, mi.name FROM order_items oi LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
            $items_stmt->bind_param("i", $order_id);
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();

            $items = [];
            while ($item_row = $items_result->fetch_assoc()) {
                $items[] = $item_row;
            }
            $items_stmt->close();

            $order['items'] = $items;

            // Add branch name to the response
            $branch_sql = "SELECT name FROM restaurant_branches WHERE id = ?";
            $b_stmt = $conn->prepare($branch_sql);
            $b_stmt->bind_param("i", $current_branch_id);
            $b_stmt->execute();
            $b_res = $b_stmt->get_result();
            if ($b_res->num_rows > 0) {
                $b_row = $b_res->fetch_assoc();
                $order['branch_name'] = $b_row['name'];
            } else {
                $order['branch_name'] = 'Restaurante';
            }
            $b_stmt->close();

            echo json_encode(['success' => true, 'data' => $order]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Pedido no encontrado']);
        }
        $order_stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'ID de pedido inválido']);
    }
    exit();
}

// Obtener sección actual
$current_section = isset($_GET['section']) ? $_GET['section'] : 'mesas';
$section_data = getSectionData($current_section, $current_branch_id, $conn);

// Obtener mesas para la sección de pedidos
$tables = [];
$menu_items = [];
if ($current_section === 'pedidos') {
    $tables = getSectionData('mesas', $current_branch_id, $conn);
    $menu_items = getSectionData('menu', $current_branch_id, $conn);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Dashboard - <?php echo htmlspecialchars($current_branch_name); ?> - TechSysUp</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            overflow-x: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .header h2 {
            font-size: 1.2em;
        }

        .header .branch-info {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: #c82333;
        }

        .sidebar {
            position: fixed;
            top: 80px;
            left: 0;
            width: 250px;
            height: calc(100vh - 80px);
            background: white;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
            z-index: 999;
        }

        .sidebar .menu-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 1px solid #f0f0f0;
        }

        .sidebar .menu-item:hover {
            background-color: #f8f9fa;
            border-left: 4px solid #667eea;
        }

        .sidebar .menu-item.active {
            background-color: #e3f2fd;
            border-left: 4px solid #667eea;
            color: #1976d2;
        }

        .sidebar .menu-item i {
            margin-right: 15px;
            font-size: 1.2em;
            width: 20px;
        }

        .sidebar .menu-item span {
            font-weight: 500;
        }

        .sidebar .user-info {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            padding: 10px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 5px;
            text-align: center;
            font-size: 14px;
            color: #333;
            font-weight: 500;
        }

        .main-content {
            margin-left: 250px;
            margin-top: 80px;
            padding: 20px;
            min-height: calc(100vh - 80px);
        }

        .section-header {
            margin-bottom: 20px;
        }

        .section-header h1 {
            color: #333;
            font-size: 1.8em;
            margin-bottom: 10px;
        }

        .branch-selector {
            margin-bottom: 20px;
        }

        .branch-selector select {
            padding: 10px 15px;
            border: none;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.2);
            transition: all 0.3s ease;
        }

        .branch-selector select:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .branch-selector select option {
            background: white;
            color: black;
        }

        .content-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .table-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .table-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
        }

        .table-card.occupied {
            background-color: #fff3cd;
            border-color: #ffc107;
        }

        .table-card.available {
            background-color: #d4edda;
            border-color: #28a745;
        }

        .table-number {
            font-size: 1.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .table-status {
            font-size: 0.9em;
            color: #666;
        }

        .order-item {
            background: #f8f9fa;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
            border-left: 4px solid #667eea;
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 5px;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-preparing {
            background-color: #cce5ff;
            color: #004085;
        }

        .status-ready {
            background-color: #d4edda;
            color: #155724;
        }

        .status-delivered {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
        }

        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
            }

            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <div>
            <h2>TechSysUp - POS Dashboard</h2>
            <div class="branch-info"><?php echo htmlspecialchars($current_branch_name); ?></div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <?php if ($_SESSION['role'] === 'restaurant' && !empty($branches)): ?>
                <div class="branch-selector" style="margin-bottom: 0;">
                    <select onchange="changeBranch(this.value)">
                        <option value="">Seleccionar Sede</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo $branch['id']; ?>" <?php echo $current_branch_id == $branch['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($branch['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <a href="../logout.php" class="logout-btn">Cerrar Sesión</a>
        </div>
    </div>

    <div class="sidebar">
        <div class="menu-item <?php echo $current_section === 'mesas' ? 'active' : ''; ?>"
            onclick="changeSection('mesas')">
            <i class="fas fa-utensils"></i>
            <span>Gestión de Mesas</span>
        </div>
        <div class="menu-item <?php echo $current_section === 'pedidos' ? 'active' : ''; ?>"
            onclick="changeSection('pedidos')">
            <i class="fas fa-clipboard-list"></i>
            <span>Toma de Pedidos</span>
        </div>
        <div class="menu-item <?php echo $current_section === 'cocina' ? 'active' : ''; ?>"
            onclick="changeSection('cocina')">
            <i class="fas fa-concierge-bell"></i>
            <span>Cocina</span>
        </div>
        <div class="menu-item <?php echo $current_section === 'menu' ? 'active' : ''; ?>"
            onclick="changeSection('menu')">
            <i class="fas fa-book-open"></i>
            <span>Menú</span>
        </div>
        <div class="menu-item <?php echo $current_section === 'inventarios' ? 'active' : ''; ?>"
            onclick="changeSection('inventarios')">
            <i class="fas fa-boxes"></i>
            <span>Inventarios</span>
        </div>
        <div class="menu-item <?php echo $current_section === 'meseros' ? 'active' : ''; ?>"
            onclick="changeSection('meseros')">
            <i class="fas fa-user-friends"></i>
            <span>Meseros</span>
        </div>
        <div class="menu-item <?php echo $current_section === 'caja' ? 'active' : ''; ?>"
            onclick="changeSection('caja')">
            <i class="fas fa-cash-register"></i>
            <span>Caja</span>
        </div>
        <div class="menu-item <?php echo $current_section === 'reportes' ? 'active' : ''; ?>"
            onclick="changeSection('reportes')">
            <i class="fas fa-chart-bar"></i>
            <span>Reportes</span>
        </div>
        <div class="user-info">
            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
        </div>
    </div>

    <div class="main-content">
        <div class="section-header">
            <h1><?php
            $section_titles = [
                'mesas' => 'Gestión de Mesas',
                'pedidos' => 'Toma de Pedidos',
                'cocina' => 'Cocina',
                'menu' => 'Menú',
                'inventarios' => 'Inventarios',
                'meseros' => 'Meseros',
                'caja' => 'Caja',
                'reportes' => 'Reportes'
            ];
            echo $section_titles[$current_section] ?? 'Dashboard';
            ?></h1>
        </div>

        <div class="content-card">
            <?php if ($message): ?>
                <div class="message"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($current_section === 'mesas'): ?>
                <div class="grid">
                    <?php if (empty($section_data)): ?>
                        <p>No hay mesas configuradas para esta sede.</p>
                    <?php else: ?>
                        <?php foreach ($section_data as $table): ?>
                            <div class="table-card <?php echo $table['status'] === 'occupied' ? 'occupied' : 'available'; ?>"
                                onclick="manageTable(<?php echo $table['id']; ?>)">
                                <div class="table-number"><?php echo htmlspecialchars($table['table_number']); ?></div>
                                <div class="table-status"><?php echo $table['status'] === 'occupied' ? 'Ocupada' : 'Disponible'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 20px;">
                    <button class="btn" onclick="addTable()">Agregar Mesa</button>
                    <button class="btn btn-danger" onclick="deleteTable()">Eliminar Mesa</button>
                </div>

            <?php elseif ($current_section === 'pedidos'): ?>
                <div class="grid">
                    <?php if (empty($tables)): ?>
                        <p>No hay mesas configuradas para esta sede.</p>
                    <?php else: ?>
                        <?php foreach ($tables as $table): ?>
                            <div class="table-card <?php echo $table['status'] === 'occupied' ? 'occupied' : 'available'; ?>"
                                onclick="newOrder(<?php echo $table['id']; ?>, '<?php echo htmlspecialchars($table['table_number']); ?>')">
                                <div class="table-number"><?php echo htmlspecialchars($table['table_number']); ?></div>
                                <div class="table-status"><?php echo $table['status'] === 'occupied' ? 'Ocupada' : 'Disponible'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <h3 style="margin-top: 30px;">Pedidos Activos</h3>
                <?php if (empty($section_data)): ?>
                    <p>No hay pedidos activos.</p>
                <?php else: ?>
                    <?php foreach ($section_data as $order): ?>
                        <div class="order-item" id="order-<?php echo $order['id']; ?>">
                            <div class="order-header">
                                <strong>Pedido #<?php echo $order['id']; ?> - Mesa
                                    <?php echo htmlspecialchars($order['table_number']); ?></strong>
                                <span class="status-badge status-<?php echo $order['status']; ?>"
                                    id="status-badge-<?php echo $order['id']; ?>"><?php echo ucfirst($order['status']); ?></span>
                            </div>
                            <p><strong>Mesero:</strong> <?php echo htmlspecialchars($order['user_name'] ?? 'N/A'); ?></p>
                            <p><strong>Total:</strong> $<?php echo number_format($order['total'], 0, ',', '.'); ?></p>
                            <p><strong>Hora:</strong> <?php echo date('H:i', strtotime($order['created_at'])); ?></p>

                            <?php if (!empty($order['notes'])): ?>
                                <p><strong>Notas:</strong> <em><?php echo htmlspecialchars($order['notes']); ?></em></p>
                            <?php endif; ?>

                            <?php if (!empty($order['items'])): ?>
                                <div class="order-items-list">
                                    <h4 style="margin: 10px 0 5px 0; font-size: 14px;">Artículos del Pedido:</h4>
                                    <ul style="margin: 5px 0; padding-left: 20px;">
                                        <?php foreach ($order['items'] as $item): ?>
                                            <li>
                                                <strong><?php echo htmlspecialchars($item['name'] ?? 'N/A'); ?></strong>
                                                x <?php echo $item['quantity']; ?>
                                                - $<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <?php if ($order['status'] === 'pending'): ?>
                                    <button class="btn btn-success"
                                        onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'preparing')">Preparar</button>
                                    <button class="btn btn-secondary" onclick="printTicket(<?php echo $order['id']; ?>)"><i
                                            class="fa fa-print"></i> Imprimir</button>
                                <?php elseif ($order['status'] === 'ready'): ?>
                                    <button class="btn btn-info"
                                        onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'delivered')">Entregado</button>
                                <?php elseif ($order['status'] === 'delivered'): ?>
                                    <button class="btn btn-warning"
                                        onclick="completeOrder(<?php echo $order['id']; ?>)">Finalizado</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php elseif ($current_section === 'cocina'): ?>
                <h3>Pedidos en Preparación</h3>
                <?php if (empty($section_data)): ?>
                    <p>No hay pedidos en preparación.</p>
                <?php else: ?>
                    <?php foreach ($section_data as $order): ?>
                        <div class="order-item">
                            <div class="order-header">
                                <strong>Pedido #<?php echo $order['id']; ?> - Mesa
                                    <?php echo htmlspecialchars($order['table_number']); ?></strong>
                                <button class="btn btn-success"
                                    onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'ready')">Marcar Listo</button>
                            </div>
                            <p><strong>Mesero:</strong> <?php echo htmlspecialchars($order['user_name'] ?? 'N/A'); ?></p>
                            <p><strong>Hora:</strong> <?php echo date('H:i', strtotime($order['created_at'])); ?></p>

                            <?php if (!empty($order['notes'])): ?>
                                <p><strong>Notas:</strong> <em><?php echo htmlspecialchars($order['notes']); ?></em></p>
                            <?php endif; ?>

                            <?php if (!empty($order['items'])): ?>
                                <div class="order-items-list">
                                    <h4 style="margin: 10px 0 5px 0; font-size: 14px;">Artículos del Pedido:</h4>
                                    <ul style="margin: 5px 0; padding-left: 20px;">
                                        <?php foreach ($order['items'] as $item): ?>
                                            <li>
                                                <strong><?php echo htmlspecialchars($item['name'] ?? 'N/A'); ?></strong>
                                                x <?php echo $item['quantity']; ?>
                                                - $<?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php elseif ($current_section === 'menu'): ?>
                <table>
                    <thead>
                    <tbody>
                        <?php foreach ($section_data as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                <td><?php echo htmlspecialchars($item['category_name'] ?? 'Sin categoría'); ?></td>
                                <td>$<?php echo number_format($item['price'], 0, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($item['description']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($current_section === 'inventarios'): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Cantidad</th>
                            <th>Unidad</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($section_data as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td><?php echo $item['quantity'] <= $item['min_quantity'] ? 'Stock Bajo' : 'Normal'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($current_section === 'meseros'): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($section_data as $waiter): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($waiter['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($waiter['username']); ?></td>
                                <td><?php echo $waiter['status'] === 'active' ? 'Activo' : 'Inactivo'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($current_section === 'caja'): ?>
                <h3>Caja - Transacciones de Hoy</h3>

                <?php
                // Calcular totales
                $total_cash = 0;
                foreach ($section_data as $transaction) {
                    if ($transaction['type'] === 'payment') {
                        $total_cash += $transaction['amount'];
                    }
                }
                ?>

                <div style="background-color: #f0f0f0; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="margin-top: 0;">Resumen del Día</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div style="background-color: #fff; padding: 10px; border-radius: 5px; text-align: center;">
                            <p style="margin: 0; color: #666; font-size: 12px;">TOTAL COBRADO</p>
                            <h2 style="margin: 5px 0; color: #27ae60;">
                                $<?php echo number_format($total_cash, 2, ',', '.'); ?></h2>
                        </div>
                        <div style="background-color: #fff; padding: 10px; border-radius: 5px; text-align: center;">
                            <p style="margin: 0; color: #666; font-size: 12px;">TRANSACCIONES</p>
                            <h2 style="margin: 5px 0; color: #3498db;"><?php echo count($section_data); ?></h2>
                        </div>
                    </div>
                </div>

                <?php if (empty($section_data)): ?>
                    <p>No hay transacciones registradas hoy.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Pedido</th>
                                <th>Monto</th>
                                <th>Descripción</th>
                                <th>Hora</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($section_data as $transaction): ?>
                                <tr>
                                    <td>
                                        <span
                                            style="background-color: <?php echo ($transaction['type'] === 'payment' ? '#27ae60' : '#e74c3c'); ?>; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                                            <?php echo ucfirst($transaction['type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $transaction['order_id'] ? '#' . $transaction['order_id'] : 'N/A'; ?></td>
                                    <td style="text-align: right; font-weight: bold;">
                                        $<?php echo number_format($transaction['amount'], 2, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                    <td><?php echo date('H:i', strtotime($transaction['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php elseif ($current_section === 'reportes'): ?>
                <div class="grid">
                    <div class="content-card">
                        <h3>Ventas del Día</h3>
                        <p>$0</p>
                    </div>
                    <div class="content-card">
                        <h3>Pedidos del Día</h3>
                        <p>0</p>
                    </div>
                    <div class="content-card">
                        <h3>Mesas Ocupadas</h3>
                        <p>0</p>
                    </div>
                    <div class="content-card">
                        <h3>Productos Más Vendidos</h3>
                        <p>Próximamente</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para agregar mesa -->
    <div id="addTableModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addTableModal')">&times;</span>
            <h3>Agregar Nueva Mesa</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_table">
                <div class="form-group">
                    <label for="table_number">Número de Mesa:</label>
                    <input type="text" id="table_number" name="table_number" required>
                </div>
                <button type="submit" class="btn">Agregar Mesa</button>
            </form>
        </div>
    </div>

    <!-- Modal para eliminar mesa -->
    <div id="deleteTableModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('deleteTableModal')">&times;</span>
            <h3>Eliminar Mesa</h3>
            <form method="POST">
                <input type="hidden" name="action" value="delete_table">
                <div class="form-group">
                    <label for="delete_table_number">Número de Mesa:</label>
                    <input type="text" id="delete_table_number" name="table_number" required>
                </div>
                <button type="submit" class="btn btn-danger">Eliminar Mesa</button>
            </form>
        </div>
    </div>

    <!-- Modal para nuevo pedido -->
    <div id="newOrderModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close" onclick="closeModal('newOrderModal')">&times;</span>
            <h3>Agregar Pedido para Mesa <span id="modal_table_number"></span></h3>
            <form method="POST" id="orderForm">
                <input type="hidden" name="action" value="add_order">
                <input type="hidden" name="table_id" id="order_table_id">
                <div style="max-height: 400px; overflow-y: auto;">
                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px;">
                        <?php foreach ($menu_items as $item): ?>
                            <div
                                style="border: 1px solid #ddd; border-radius: 8px; padding: 10px; text-align: center; background: #f9f9f9;">
                                <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                                $<?php echo number_format($item['price'], 0, ',', '.'); ?><br>
                                <input type="hidden" name="quantity[<?php echo $item['id']; ?>]"
                                    id="quantity_<?php echo $item['id']; ?>" value="0">
                                <button type="button" onclick="decreaseItem(<?php echo $item['id']; ?>)" class="btn"
                                    style="padding: 5px 8px; font-size: 12px; margin-right:6px;">-</button>
                                <span id="qty_<?php echo $item['id']; ?>"
                                    style="display: inline-block; margin-right: 10px;">0</span>
                                <button type="button" onclick="addItem(<?php echo $item['id']; ?>)" class="btn"
                                    style="padding: 5px 10px; font-size: 12px;">+</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="order_notes">Notas:</label>
                    <textarea id="order_notes" name="notes" rows="3"
                        placeholder="Agregar notas adicionales al pedido..."></textarea>
                </div>
                <button type="button" onclick="addOrder()" class="btn btn-success" style="margin-top: 20px;">Agregar
                    Pedido</button>
            </form>
            <div id="orderSummary" tabindex="-1"
                style="display: none; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 20px;">
                <h4>Pedido Seleccionado</h4>
                <div id="orderItems"></div>
                <button type="submit" form="orderForm" class="btn btn-primary" style="margin-top: 20px;"
                    onclick="submitOrder(event)">Enviar a Cocina</button>
            </div>
        </div>
    </div>

    <script>
        var menuItems = <?php echo json_encode($menu_items); ?>;
        var currentBranchId = <?php echo $current_branch_id ? $current_branch_id : 'null'; ?>;

        function changeSection(section) {
            window.location.href = '?section=' + section + '<?php echo $current_branch_id ? '&branch=' . $current_branch_id : ''; ?>';
        }

        function changeBranch(branchId) {
            if (branchId) {
                window.location.href = '?branch=' + branchId + '&section=<?php echo $current_section; ?>';
            }
        }

        function manageTable(tableId) {
            // Implementar gestión de mesa
            alert('Gestionar mesa ' + tableId);
        }

        function addTable() {
            document.getElementById('addTableModal').style.display = "block";
        }

        function deleteTable() {
            document.getElementById('deleteTableModal').style.display = "block";
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = "none";
        }

        // Cerrar modal al hacer clic fuera
        window.onclick = function (event) {
            if (event.target.className === 'modal') {
                event.target.style.display = "none";
            }
        }

        function newOrder(tableId, tableNumber) {
            document.getElementById('order_table_id').value = tableId;
            document.getElementById('modal_table_number').textContent = tableNumber;
            document.getElementById('newOrderModal').style.display = "block";
            // Reset quantities when opening modal for a new order
            for (var i = 0; i < menuItems.length; i++) {
                var id = menuItems[i].id;
                var q = document.getElementById('quantity_' + id);
                if (q) { q.value = 0; document.getElementById('qty_' + id).textContent = '0'; }
            }
            document.getElementById('orderSummary').style.display = 'none';
            document.getElementById('orderItems').innerHTML = '';
        }

        function editOrder(orderId) {
            // Implementar editar pedido
            alert('Editar pedido ' + orderId);
        }

        function updateOrderStatus(orderId, status) {
            if (confirm('¿Cambiar estado del pedido ' + orderId + ' a ' + status + '?')) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=update_order_status&order_id=' + orderId + '&status=' + status
                })
                    .then(response => response.text())
                    .then(data => {
                        // Actualizar el badge de estado sin recargar
                        const badge = document.getElementById('status-badge-' + orderId);
                        if (badge) {
                            badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                            badge.className = 'status-badge status-' + status;
                        }
                        // Actualizar los botones
                        location.reload();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error al actualizar el estado del pedido');
                    });
            }
        }

        function completeOrder(orderId) {
            if (confirm('¿Finalizar pedido ' + orderId + '? El pedido se guardará en el historial.')) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=complete_order&order_id=' + orderId
                })
                    .then(response => response.text())
                    .then(data => {
                        // Eliminar el pedido de la pantalla
                        const orderDiv = document.getElementById('order-' + orderId);
                        if (orderDiv) {
                            orderDiv.style.opacity = '0';
                            orderDiv.style.transition = 'opacity 0.3s';
                            setTimeout(() => {
                                orderDiv.remove();
                            }, 300);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error al finalizar el pedido');
                    });
            }
        }

        function cancelOrder(orderId) {
            if (confirm('¿Cancelar pedido ' + orderId + '?')) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=cancel_order&order_id=' + orderId
                })
                    .then(response => response.text())
                    .then(data => {
                        location.reload();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error al cancelar el pedido');
                    });
            }
        }

        function printTicket(orderId) {
            // Obtener datos del pedido desde el servidor
            var url = '?action=get_order_data&order_id=' + orderId;
            if (currentBranchId) {
                url += '&branch=' + currentBranchId;
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        generateTicket(data.data);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al obtener datos del pedido');
                });
        }

        function generateTicket(order) {
            try {
                // Format date and time
                var date = new Date(order.created_at);
                var dateStr = (date.getDate() < 10 ? '0' : '') + date.getDate() + '/' +
                    ((date.getMonth() + 1) < 10 ? '0' : '') + (date.getMonth() + 1) + '/' +
                    date.getFullYear();
                var timeStr = (date.getHours() < 10 ? '0' : '') + date.getHours() + ':' +
                    (date.getMinutes() < 10 ? '0' : '') + date.getMinutes();

                // Calculate total items
                var totalItems = order.items.reduce((sum, item) => sum + parseInt(item.quantity), 0);

                // Create print window
                var printWindow = window.open('', '', 'width=400,height=800');

                var html = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <title>Vale Pedido #${order.id}</title>
                        <style>
                            * {
                                margin: 0;
                                padding: 0;
                                box-sizing: border-box;
                            }
                            body {
                                font-family: 'Courier New', monospace;
                                padding: 10px;
                                width: 80mm;
                                font-size: 11px;
                                line-height: 1.3;
                            }
                            .header {
                                text-align: center;
                                font-weight: bold;
                                font-size: 14px;
                                margin-bottom: 5px;
                                border-bottom: 2px solid #000;
                                padding-bottom: 5px;
                            }
                            .divider {
                                border-bottom: 1px solid #000;
                                margin: 5px 0;
                            }
                            .info-section {
                                margin: 5px 0;
                                padding: 3px 0;
                            }
                            .info-row {
                                display: flex;
                                justify-content: space-between;
                                margin-bottom: 2px;
                            }
                            .label {
                                font-weight: bold;
                            }
                            .items-header {
                                font-weight: bold;
                                margin-top: 5px;
                                margin-bottom: 3px;
                                text-decoration: underline;
                            }
                            .item {
                                margin-bottom: 3px;
                                font-size: 10px;
                            }
                            .item-name {
                                overflow: hidden;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                                max-width: 50mm;
                            }
                            .item-details {
                                display: flex;
                                justify-content: space-between;
                                font-size: 10px;
                            }
                            .item-qty {
                                text-align: center;
                                width: 20px;
                            }
                            .item-price {
                                text-align: right;
                                width: 25px;
                            }
                            .totals-section {
                                border-top: 2px solid #000;
                                border-bottom: 2px solid #000;
                                padding: 3px 0;
                                margin: 5px 0;
                            }
                            .total-row {
                                display: flex;
                                justify-content: space-between;
                                font-weight: bold;
                                font-size: 12px;
                                padding: 2px 0;
                            }
                            .notes-section {
                                margin-top: 5px;
                                padding: 3px;
                                border-top: 1px solid #000;
                                font-size: 9px;
                                font-style: italic;
                            }
                            .footer {
                                text-align: center;
                                font-size: 9px;
                                margin-top: 5px;
                                padding-top: 3px;
                            }
                            @media print {
                                body {
                                    margin: 0;
                                    padding: 0;
                                }
                            }
                        </style>
                    <style>
                        /* ... existing styles ... */
                    </style>
                    </head>
                    <body>
                        <div class="header">
                            ${order.branch_name ? order.branch_name.toUpperCase() : 'RESTAURANTE'}<br>
                            VALE DE PEDIDO
                        </div>
                        
                        <div class="info-section">
                            <div class="info-row">
                                <span class="label">Pedido:</span>
                                <span>#${order.id}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Mesa:</span>
                                <span>${order.table_number}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Mesero:</span>
                                <span>${order.user_name || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Fecha:</span>
                                <span>${dateStr}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Hora:</span>
                                <span>${timeStr}</span>
                            </div>
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="items-header">ARTICULOS:</div>
                        <div>
                            ${order.items.map(item => `
                                <div class="item">
                                    <div class="item-name">${escapeHtml(item.name)}</div>
                                    <div class="item-details">
                                        <span class="item-qty">x${item.quantity}</span>
                                        <span class="item-price">$${(parseFloat(item.price) * parseInt(item.quantity)).toFixed(2)}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                        
                        <div class="divider"></div>
                        
                        <div class="totals-section">
                            <div class="total-row">
                                <span>ITEMS:</span>
                                <span>${totalItems}</span>
                            </div>
                            <div class="total-row" style="font-size: 13px; padding: 5px 0;">
                                <span>TOTAL:</span>
                                <span>$${parseFloat(order.total).toFixed(2)}</span>
                            </div>
                        </div>
                        
                        ${order.notes ? `
                            <div class="notes-section">
                                <strong>Notas:</strong> ${escapeHtml(order.notes)}
                            </div>
                        ` : ''}
                        
                        <div class="footer">
                            Gracias por su compra
                            <br>
                            Buen provecho
                        </div>
                    </body>
                    </html>
                `;

                printWindow.document.write(html);
                printWindow.document.close();

                // Esperar a que cargue el contenido antes de imprimir
                setTimeout(() => {
                    printWindow.focus();
                    printWindow.print();
                }, 500);

            } catch (error) {
                console.error('Error al generar ticket:', error);
                alert('Error al generar el ticket: ' + error.message);
            }
        }

        // Función para escapar HTML
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
        }

        function addItem(id) {
            var qty = document.getElementById('quantity_' + id);
            qty.value = parseInt(qty.value) + 1;
            document.getElementById('qty_' + id).textContent = qty.value;
        }

        function decreaseItem(id) {
            var qty = document.getElementById('quantity_' + id);
            var v = parseInt(qty.value);
            if (v > 0) {
                qty.value = v - 1;
                document.getElementById('qty_' + id).textContent = qty.value;
            }
        }

        function removeFromSummary(id) {
            // Set quantity to 0 and refresh the summary
            var qty = document.getElementById('quantity_' + id);
            if (qty) {
                qty.value = 0;
                document.getElementById('qty_' + id).textContent = '0';
                addOrder();
            }
        }

        function addOrder() {
            var orderItems = document.getElementById('orderItems');
            orderItems.innerHTML = '';
            var hasItems = false;
            var total = 0;
            for (var i = 0; i < menuItems.length; i++) {
                var item = menuItems[i];
                var qty = parseInt(document.getElementById('quantity_' + item.id).value);
                if (qty > 0) {
                    hasItems = true;
                    var div = document.createElement('div');
                    div.innerHTML = '<span>' + escapeHtml(item.name) + ' x' + qty + ' - $' + (item.price * qty) + '</span>' +
                        ' <button type="button" onclick="removeFromSummary(' + item.id + ')" class="btn btn-sm" style="margin-left:10px;">Eliminar</button>';
                    orderItems.appendChild(div);
                    total += item.price * qty;
                }
            }
            if (hasItems) {
                var notes = document.getElementById('order_notes').value;
                if (notes.trim() !== '') {
                    var notesDiv = document.createElement('div');
                    notesDiv.innerHTML = '<strong>Notas:</strong> ' + escapeHtml(notes);
                    orderItems.appendChild(notesDiv);
                }
                var totalDiv = document.createElement('div');
                totalDiv.innerHTML = '<strong>Total: $' + total + '</strong>';
                orderItems.appendChild(totalDiv);
                var summaryEl = document.getElementById('orderSummary');
                summaryEl.style.display = 'block';
                summaryEl.style.background = '#ffffff';
                summaryEl.style.padding = '12px';
                summaryEl.style.borderRadius = '6px';
                summaryEl.style.boxShadow = '0 6px 20px rgba(0,0,0,0.08)';
                try { summaryEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); summaryEl.focus(); } catch (e) { /* ignore */ }
            } else {
                alert('No hay items seleccionados');
            }
        }

        function submitOrder(e) {
            e.preventDefault();
            var form = document.getElementById('orderForm');
            var formData = new FormData(form);

            // Debug: log form data
            console.log('Form data being sent:');
            for (var pair of formData.entries()) {
                console.log(pair[0] + ': ' + pair[1]);
            }

            // Validate that there are items selected
            var hasItems = false;
            for (var i = 0; i < menuItems.length; i++) {
                var qty = parseInt(document.getElementById('quantity_' + menuItems[i].id).value);
                if (qty > 0) {
                    hasItems = true;
                    break;
                }
            }

            if (!hasItems) {
                alert('No hay items seleccionados en el pedido');
                return;
            }

            // Submit form via fetch
            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Error al enviar el pedido: ' + response.status);
                    }
                    return response.text();
                })
                .then(function (data) {
                    // Show success message and reload page
                    alert('Pedido enviado a cocina exitosamente');
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                })
                .catch(function (error) {
                    alert('Error al enviar el pedido: ' + error.message);
                    console.log('Error details:', error);
                });
        }
    </script>
</body>

</html>