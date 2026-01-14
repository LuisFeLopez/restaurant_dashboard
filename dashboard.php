<?php
require_once '../security.php';

// Start secure session
startSecureSession();

// Set security headers
setSecurityHeaders();

// Verificar si el usuario está logueado y es restaurant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'restaurant') {
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

// Procesar acciones POST
$message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'create_branch') {
            $name = $_POST['name'] ?? '';
            $address = $_POST['address'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $manager_name = $_POST['manager_name'] ?? '';

            if (!empty($name)) {
                $stmt = $conn->prepare("INSERT INTO restaurant_branches (name, address, phone, manager_name) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $name, $address, $phone, $manager_name);
                if ($stmt->execute()) {
                    $message = "Sede creada exitosamente.";
                } else {
                    $message = "Error al crear la sede.";
                }
                $stmt->close();
            }
        } elseif ($action === 'toggle_branch') {
            $branch_id = $_POST['branch_id'] ?? 0;
            $current_status = $_POST['current_status'] ?? 'active';
            $new_status = ($current_status === 'active') ? 'inactive' : 'active';

            $stmt = $conn->prepare("UPDATE restaurant_branches SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $branch_id);
            if ($stmt->execute()) {
                $message = "Estado de la sede actualizado.";
            } else {
                $message = "Error al actualizar el estado.";
            }
            $stmt->close();
        } elseif ($action === 'create_user') {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $full_name = $_POST['full_name'] ?? '';
            $branch_id = $_POST['branch_id'] ?? 0;
            $role = $_POST['role'] ?? 'staff';

            if (!empty($username) && !empty($password) && !empty($full_name)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO restaurant_users (username, password, full_name, branch_id, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssds", $username, $hashed_password, $full_name, $branch_id, $role);
                if ($stmt->execute()) {
                    $message = "Usuario creado exitosamente.";
                } else {
                    $message = "Error al crear el usuario.";
                }
                $stmt->close();
            }
        } elseif ($action === 'toggle_user') {
            $user_id = $_POST['user_id'] ?? 0;
            $current_status = $_POST['current_status'] ?? 'active';
            $new_status = ($current_status === 'active') ? 'inactive' : 'active';

            $stmt = $conn->prepare("UPDATE restaurant_users SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $user_id);
            if ($stmt->execute()) {
                $message = "Estado del usuario actualizado.";
            } else {
                $message = "Error al actualizar el estado.";
            }
            $stmt->close();
        } elseif ($action === 'create_menu_category') {
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';

            if (!empty($name)) {
                $stmt = $conn->prepare("INSERT INTO menu_categories (name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $description);
                if ($stmt->execute()) {
                    $message = "Categoría de menú creada exitosamente.";
                } else {
                    $message = "Error al crear la categoría.";
                }
                $stmt->close();
            }
        } elseif ($action === 'create_menu_item') {
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $price = $_POST['price'] ?? 0;
            $category_id = $_POST['category_id'] ?? 0;

            if (!empty($name) && $price > 0) {
                $stmt = $conn->prepare("INSERT INTO menu_items (name, description, price, category_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssdi", $name, $description, $price, $category_id);
                if ($stmt->execute()) {
                    $message = "Ítem de menú creado exitosamente.";
                } else {
                    $message = "Error al crear el ítem.";
                }
                $stmt->close();
            }
        } elseif ($action === 'create_order') {
            $branch_id = $_POST['branch_id'] ?? 0;
            $user_id = $_POST['user_id'] ?? 0;
            $table_number = $_POST['table_number'] ?? '';
            $items = $_POST['items'] ?? [];

            if (!empty($items)) {
                // Crear pedido
                $stmt = $conn->prepare("INSERT INTO orders (branch_id, user_id, table_number) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $branch_id, $user_id, $table_number);
                if ($stmt->execute()) {
                    $order_id = $conn->insert_id;
                    $total = 0;

                    // Agregar ítems al pedido
                    foreach ($items as $item) {
                        $menu_item_id = $item['id'];
                        $quantity = $item['quantity'];
                        $notes = $item['notes'] ?? '';

                        // Obtener precio del ítem
                        $price_stmt = $conn->prepare("SELECT price FROM menu_items WHERE id = ?");
                        $price_stmt->bind_param("i", $menu_item_id);
                        $price_stmt->execute();
                        $price_result = $price_stmt->get_result();
                        $price_row = $price_result->fetch_assoc();
                        $price = $price_row['price'];
                        $price_stmt->close();

                        $subtotal = $price * $quantity;
                        $total += $subtotal;

                        $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price, notes) VALUES (?, ?, ?, ?, ?)");
                        $item_stmt->bind_param("iiids", $order_id, $menu_item_id, $quantity, $price, $notes);
                        $item_stmt->execute();
                        $item_stmt->close();
                    }

                    // Actualizar total del pedido
                    $update_stmt = $conn->prepare("UPDATE orders SET total = ? WHERE id = ?");
                    $update_stmt->bind_param("di", $total, $order_id);
                    $update_stmt->execute();
                    $update_stmt->close();

                    $message = "Pedido creado exitosamente.";
                } else {
                    $message = "Error al crear el pedido.";
                }
                $stmt->close();
            }
        } elseif ($action === 'update_order_status') {
            $order_id = $_POST['order_id'] ?? 0;
            $status = $_POST['status'] ?? 'pending';

            $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $order_id);
            if ($stmt->execute()) {
                $message = "Estado del pedido actualizado.";
            } else {
                $message = "Error al actualizar el estado.";
            }
            $stmt->close();
        } elseif ($action === 'create_inventory_item') {
            $name = $_POST['name'] ?? '';
            $description = $_POST['description'] ?? '';
            $quantity = $_POST['quantity'] ?? 0;
            $unit = $_POST['unit'] ?? 'units';
            $min_quantity = $_POST['min_quantity'] ?? 0;
            $supplier = $_POST['supplier'] ?? '';

            if (!empty($name)) {
                $stmt = $conn->prepare("INSERT INTO inventory_items (name, description, quantity, unit, min_quantity, supplier) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdsds", $name, $description, $quantity, $unit, $min_quantity, $supplier);
                if ($stmt->execute()) {
                    $message = "Ítem de inventario creado exitosamente.";
                } else {
                    $message = "Error al crear el ítem.";
                }
                $stmt->close();
            }
        } elseif ($action === 'update_inventory') {
            $item_id = $_POST['item_id'] ?? 0;
            $quantity = $_POST['quantity'] ?? 0;

            $stmt = $conn->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?");
            $stmt->bind_param("di", $quantity, $item_id);
            if ($stmt->execute()) {
                $message = "Inventario actualizado exitosamente.";
            } else {
                $message = "Error al actualizar el inventario.";
            }
            $stmt->close();
        } elseif ($action === 'create_reservation') {
            $branch_id = $_POST['branch_id'] ?? 0;
            $customer_name = $_POST['customer_name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $email = $_POST['email'] ?? '';
            $reservation_date = $_POST['reservation_date'] ?? '';
            $reservation_time = $_POST['reservation_time'] ?? '';
            $guests = $_POST['guests'] ?? 1;
            $notes = $_POST['notes'] ?? '';

            if (!empty($customer_name) && !empty($reservation_date) && !empty($reservation_time)) {
                $stmt = $conn->prepare("INSERT INTO reservations (branch_id, customer_name, phone, email, reservation_date, reservation_time, guests, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issssiss", $branch_id, $customer_name, $phone, $email, $reservation_date, $reservation_time, $guests, $notes);
                if ($stmt->execute()) {
                    $message = "Reserva creada exitosamente.";
                } else {
                    $message = "Error al crear la reserva.";
                }
                $stmt->close();
            }
        } elseif ($action === 'update_reservation_status') {
            $reservation_id = $_POST['reservation_id'] ?? 0;
            $status = $_POST['status'] ?? 'pending';

            $stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $reservation_id);
            if ($stmt->execute()) {
                $message = "Estado de la reserva actualizado.";
            } else {
                $message = "Error al actualizar el estado.";
            }
            $stmt->close();
        } elseif ($action === 'delete_branch') {
            $branch_id = $_POST['branch_id'] ?? 0;

            $stmt = $conn->prepare("DELETE FROM restaurant_branches WHERE id = ?");
            $stmt->bind_param("i", $branch_id);
            if ($stmt->execute()) {
                $message = "Sede eliminada exitosamente.";
            } else {
                $message = "Error al eliminar la sede.";
            }
            $stmt->close();
        } elseif ($action === 'create_schedule') {
            $user_id = $_POST['user_id'] ?? 0;
            $branch_id = $_POST['branch_id'] ?? 0;
            $schedule_date = $_POST['schedule_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $notes = $_POST['notes'] ?? '';

            if ($user_id > 0 && !empty($schedule_date) && !empty($start_time) && !empty($end_time)) {
                $stmt = $conn->prepare("INSERT INTO staff_schedules (user_id, branch_id, schedule_date, start_time, end_time, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iissss", $user_id, $branch_id, $schedule_date, $start_time, $end_time, $notes);
                if ($stmt->execute()) {
                    $message = "Horario creado exitosamente.";
                } else {
                    $message = "Error al crear el horario.";
                }
                $stmt->close();
            }
        }
    }
}

// Obtener todas las sedes
$branches_sql = "SELECT * FROM restaurant_branches ORDER BY created_at DESC";
$branches_result = $conn->query($branches_sql);

// Obtener todos los usuarios con información de sede
$users_sql = "SELECT ru.*, rb.name as branch_name FROM restaurant_users ru LEFT JOIN restaurant_branches rb ON ru.branch_id = rb.id ORDER BY ru.created_at DESC";
$users_result = $conn->query($users_sql);

// Obtener categorías de menú
$menu_categories_sql = "SELECT * FROM menu_categories WHERE status = 'active' ORDER BY name";
$menu_categories_result = $conn->query($menu_categories_sql);

// Obtener ítems de menú con categorías
$menu_items_sql = "SELECT mi.*, mc.name as category_name FROM menu_items mi LEFT JOIN menu_categories mc ON mi.category_id = mc.id WHERE mi.status = 'active' ORDER BY mi.created_at DESC";
$menu_items_result = $conn->query($menu_items_sql);

// Obtener pedidos con información
$orders_sql = "SELECT o.*, rb.name as branch_name, ru.full_name as user_name FROM orders o LEFT JOIN restaurant_branches rb ON o.branch_id = rb.id LEFT JOIN restaurant_users ru ON o.user_id = ru.id ORDER BY o.created_at DESC LIMIT 50";
$orders_result = $conn->query($orders_sql);

// Obtener ítems de inventario
$inventory_sql = "SELECT * FROM inventory_items WHERE status = 'active' ORDER BY name";
$inventory_result = $conn->query($inventory_sql);

// Obtener reservas
$reservations_sql = "SELECT r.*, rb.name as branch_name FROM reservations r LEFT JOIN restaurant_branches rb ON r.branch_id = rb.id ORDER BY r.reservation_date DESC, r.reservation_time DESC LIMIT 50";
$reservations_result = $conn->query($reservations_sql);

// Obtener horarios del personal
$schedules_sql = "SELECT ss.*, ru.full_name as user_name, rb.name as branch_name FROM staff_schedules ss LEFT JOIN restaurant_users ru ON ss.user_id = ru.id LEFT JOIN restaurant_branches rb ON ss.branch_id = rb.id ORDER BY ss.schedule_date DESC, ss.start_time ASC LIMIT 50";
$schedules_result = $conn->query($schedules_sql);

// Estadísticas
$total_branches = $branches_result->num_rows;
$active_branches = 0;
$total_users = $users_result->num_rows;
$active_users = 0;
$total_menu_items = $menu_items_result->num_rows;
$total_orders = $orders_result->num_rows;
$pending_orders = 0;
$total_inventory_items = $inventory_result->num_rows;
$low_stock_items = 0;
$total_reservations = $reservations_result->num_rows;
$confirmed_reservations = 0;

$branches_result->data_seek(0);
while($row = $branches_result->fetch_assoc()) {
    if ($row['status'] === 'active') $active_branches++;
}

$users_result->data_seek(0);
while($row = $users_result->fetch_assoc()) {
    if ($row['status'] === 'active') $active_users++;
}

$orders_result->data_seek(0);
while($row = $orders_result->fetch_assoc()) {
    if ($row['status'] === 'pending') $pending_orders++;
}

$inventory_result->data_seek(0);
while($row = $inventory_result->fetch_assoc()) {
    if ($row['quantity'] <= $row['min_quantity']) $low_stock_items++;
}

$reservations_result->data_seek(0);
while($row = $reservations_result->fetch_assoc()) {
    if ($row['status'] === 'confirmed') $confirmed_reservations++;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Restaurante - TechSysUp</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }
        .header {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .header h2 {
            margin: 0;
            font-size: 1.5em;
        }
        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .logout-btn:hover {
            background: #c82333;
        }
        .container {
            max-width: 1200px;
            margin: 20px auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .stats {
            display: flex;
            justify-content: space-around;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .stat-card {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            flex: 1;
            margin: 10px;
            min-width: 200px;
        }
        .stat-number {
            font-size: 2em;
            font-weight: bold;
        }
        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        .section {
            margin-bottom: 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
        }
        .section h3 {
            color: #333;
            margin-top: 0;
            border-bottom: 2px solid #ff6b35;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-success {
            background: #28a745;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        tr:hover {
            background-color: #f8f9fa;
        }
        .status-active {
            color: #28a745;
            font-weight: bold;
        }
        .status-inactive {
            color: #dc3545;
            font-weight: bold;
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
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 8px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: black;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>TechSysUp - Dashboard Restaurante</h2>
        <div>
            <a href="pos_dashboard.php" class="logout-btn" style="background: #28a745; margin-right: 10px;">Ir al POS</a>
            <a href="../logout.php" class="logout-btn">Cerrar Sesión</a>
        </div>
    </div>

    <div class="container">
        <h1>Gestión de Restaurantes</h1>

        <?php if ($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Estadísticas -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_branches; ?></div>
                <div class="stat-label">Total Sedes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_branches; ?></div>
                <div class="stat-label">Sedes Activas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_users; ?></div>
                <div class="stat-label">Total Usuarios</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_users; ?></div>
                <div class="stat-label">Usuarios Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_menu_items; ?></div>
                <div class="stat-label">Ítems de Menú</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_orders; ?></div>
                <div class="stat-label">Total Pedidos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $pending_orders; ?></div>
                <div class="stat-label">Pedidos Pendientes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_inventory_items; ?></div>
                <div class="stat-label">Ítems en Inventario</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $low_stock_items; ?></div>
                <div class="stat-label">Stock Bajo</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_reservations; ?></div>
                <div class="stat-label">Total Reservas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $confirmed_reservations; ?></div>
                <div class="stat-label">Reservas Confirmadas</div>
            </div>
        </div>

        <!-- Gestión de Sedes -->
        <div class="section">
            <h3>Gestión de Sedes</h3>
            <button class="btn" onclick="openModal('branchModal')">Crear Nueva Sede</button>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Dirección</th>
                        <th>Teléfono</th>
                        <th>Gerente</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                        <th>Eliminar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $branches_result->data_seek(0);
                    if ($branches_result->num_rows > 0) {
                        while($row = $branches_result->fetch_assoc()) {
                            $status_class = $row['status'] === 'active' ? 'status-active' : 'status-inactive';
                            $status_text = $row['status'] === 'active' ? 'Activa' : 'Inactiva';
                            $toggle_text = $row['status'] === 'active' ? 'Desactivar' : 'Activar';
                            $btn_class = $row['status'] === 'active' ? 'btn-danger' : 'btn-success';

                            echo "<tr>";
                            echo "<td>" . $row["id"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["name"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["address"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["phone"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["manager_name"]) . "</td>";
                            echo "<td class='$status_class'>$status_text</td>";
                            echo "<td>
                                <form method='POST' style='display:inline;'>
                                    <input type='hidden' name='action' value='toggle_branch'>
                                    <input type='hidden' name='branch_id' value='" . $row["id"] . "'>
                                    <input type='hidden' name='current_status' value='" . $row["status"] . "'>
                                    <button type='submit' class='btn $btn_class'>$toggle_text</button>
                                </form>
                            </td>";
                            echo "<td>
                                <form method='POST' style='display:inline;'>
                                    <input type='hidden' name='action' value='delete_branch'>
                                    <input type='hidden' name='branch_id' value='" . $row["id"] . "'>
                                    <button type='submit' class='btn btn-danger' onclick='return confirm(\"¿Estás seguro de que quieres eliminar esta sede?\")'>Eliminar</button>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='8'>No hay sedes registradas</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Gestión de Usuarios -->
        <div class="section">
            <h3>Gestión de Usuarios</h3>
            <button class="btn" onclick="openModal('userModal')">Crear Nuevo Usuario</button>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Nombre Completo</th>
                        <th>Sede Asignada</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $users_result->data_seek(0);
                    if ($users_result->num_rows > 0) {
                        while($row = $users_result->fetch_assoc()) {
                            $status_class = $row['status'] === 'active' ? 'status-active' : 'status-inactive';
                            $status_text = $row['status'] === 'active' ? 'Activo' : 'Inactivo';
                            $toggle_text = $row['status'] === 'active' ? 'Desactivar' : 'Activar';
                            $btn_class = $row['status'] === 'active' ? 'btn-danger' : 'btn-success';

                            echo "<tr>";
                            echo "<td>" . $row["id"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["username"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["full_name"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["branch_name"] ?? 'Sin asignar') . "</td>";
                            echo "<td>" . ucfirst($row["role"]) . "</td>";
                            echo "<td class='$status_class'>$status_text</td>";
                            echo "<td>
                                <form method='POST' style='display:inline;'>
                                    <input type='hidden' name='action' value='toggle_user'>
                                    <input type='hidden' name='user_id' value='" . $row["id"] . "'>
                                    <input type='hidden' name='current_status' value='" . $row["status"] . "'>
                                    <button type='submit' class='btn $btn_class'>$toggle_text</button>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7'>No hay usuarios registrados</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Gestión de Menú -->
        <div class="section">
            <h3>Gestión de Menú</h3>
            <button class="btn" onclick="openModal('menuCategoryModal')">Crear Categoría</button>
            <button class="btn" onclick="openModal('menuItemModal')">Crear Ítem de Menú</button>

            <h4>Categorías de Menú</h4>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Descripción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $menu_categories_result->data_seek(0);
                    if ($menu_categories_result->num_rows > 0) {
                        while($row = $menu_categories_result->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td>" . $row["id"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["name"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["description"]) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='3'>No hay categorías registradas</td></tr>";
                    }
                    ?>
                </tbody>
            </table>

            <h4>Ítems de Menú</h4>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Categoría</th>
                        <th>Precio</th>
                        <th>Descripción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $menu_items_result->data_seek(0);
                    if ($menu_items_result->num_rows > 0) {
                        while($row = $menu_items_result->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td>" . $row["id"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["name"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["category_name"] ?? 'Sin categoría') . "</td>";
                            echo "<td>$" . number_format($row["price"], 0, ',', '.') . "</td>";
                            echo "<td>" . htmlspecialchars($row["description"]) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='5'>No hay ítems de menú registrados</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Gestión de Pedidos -->
        <div class="section">
            <h3>Gestión de Pedidos</h3>
            <button class="btn" onclick="openModal('orderModal')">Crear Nuevo Pedido</button>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Sede</th>
                        <th>Mesa</th>
                        <th>Usuario</th>
                        <th>Estado</th>
                        <th>Total</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $orders_result->data_seek(0);
                    if ($orders_result->num_rows > 0) {
                        while($row = $orders_result->fetch_assoc()) {
                            $status_class = '';
                            if ($row['status'] === 'pending') $status_class = 'status-inactive';
                            elseif ($row['status'] === 'preparing') $status_class = 'status-active';
                            elseif ($row['status'] === 'ready') $status_class = 'status-active';
                            elseif ($row['status'] === 'delivered') $status_class = 'status-active';
                            elseif ($row['status'] === 'cancelled') $status_class = 'status-inactive';

                            echo "<tr>";
                            echo "<td>" . $row["id"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["branch_name"] ?? 'Sin sede') . "</td>";
                            echo "<td>" . htmlspecialchars($row["table_number"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["user_name"] ?? 'Sin usuario') . "</td>";
                            echo "<td class='$status_class'>" . ucfirst($row["status"]) . "</td>";
                            echo "<td>$" . number_format($row["total"], 0, ',', '.') . "</td>";
                            echo "<td>" . date('d/m/Y H:i', strtotime($row["created_at"])) . "</td>";
                            echo "<td>
                                <form method='POST' style='display:inline;'>
                                    <input type='hidden' name='action' value='update_order_status'>
                                    <input type='hidden' name='order_id' value='" . $row["id"] . "'>
                                    <select name='status' onchange='this.form.submit()'>
                                        <option value='pending' " . ($row['status'] === 'pending' ? 'selected' : '') . ">Pendiente</option>
                                        <option value='preparing' " . ($row['status'] === 'preparing' ? 'selected' : '') . ">Preparando</option>
                                        <option value='ready' " . ($row['status'] === 'ready' ? 'selected' : '') . ">Listo</option>
                                        <option value='delivered' " . ($row['status'] === 'delivered' ? 'selected' : '') . ">Entregado</option>
                                        <option value='cancelled' " . ($row['status'] === 'cancelled' ? 'selected' : '') . ">Cancelado</option>
                                    </select>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='8'>No hay pedidos registrados</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Gestión de Inventario -->
        <div class="section">
            <h3>Gestión de Inventario</h3>
            <button class="btn" onclick="openModal('inventoryModal')">Agregar Ítem</button>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Cantidad</th>
                        <th>Unidad</th>
                        <th>Mínimo</th>
                        <th>Proveedor</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $inventory_result->data_seek(0);
                    if ($inventory_result->num_rows > 0) {
                        while($row = $inventory_result->fetch_assoc()) {
                            $status_class = $row['quantity'] <= $row['min_quantity'] ? 'status-inactive' : 'status-active';
                            $status_text = $row['quantity'] <= $row['min_quantity'] ? 'Stock Bajo' : 'Normal';

                            echo "<tr>";
                            echo "<td>" . $row["id"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["name"]) . "</td>";
                            echo "<td>" . $row["quantity"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["unit"]) . "</td>";
                            echo "<td>" . $row["min_quantity"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["supplier"]) . "</td>";
                            echo "<td class='$status_class'>$status_text</td>";
                            echo "<td>
                                <form method='POST' style='display:inline;'>
                                    <input type='hidden' name='action' value='update_inventory'>
                                    <input type='hidden' name='item_id' value='" . $row["id"] . "'>
                                    <input type='number' name='quantity' value='" . $row["quantity"] . "' min='0' step='0.01' style='width:80px;'>
                                    <button type='submit' class='btn btn-success'>Actualizar</button>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='8'>No hay ítems en inventario</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Gestión de Reservas -->
        <div class="section">
            <h3>Gestión de Reservas</h3>
            <button class="btn" onclick="openModal('reservationModal')">Crear Reserva</button>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Sede</th>
                        <th>Cliente</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Invitados</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $reservations_result->data_seek(0);
                    if ($reservations_result->num_rows > 0) {
                        while($row = $reservations_result->fetch_assoc()) {
                            $status_class = '';
                            if ($row['status'] === 'pending') $status_class = 'status-inactive';
                            elseif ($row['status'] === 'confirmed') $status_class = 'status-active';
                            elseif ($row['status'] === 'cancelled') $status_class = 'status-inactive';
                            elseif ($row['status'] === 'completed') $status_class = 'status-active';

                            echo "<tr>";
                            echo "<td>" . $row["id"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["branch_name"] ?? 'Sin sede') . "</td>";
                            echo "<td>" . htmlspecialchars($row["customer_name"]) . "</td>";
                            echo "<td>" . date('d/m/Y', strtotime($row["reservation_date"])) . "</td>";
                            echo "<td>" . $row["reservation_time"] . "</td>";
                            echo "<td>" . $row["guests"] . "</td>";
                            echo "<td class='$status_class'>" . ucfirst($row["status"]) . "</td>";
                            echo "<td>
                                <form method='POST' style='display:inline;'>
                                    <input type='hidden' name='action' value='update_reservation_status'>
                                    <input type='hidden' name='reservation_id' value='" . $row["id"] . "'>
                                    <select name='status' onchange='this.form.submit()'>
                                        <option value='pending' " . ($row['status'] === 'pending' ? 'selected' : '') . ">Pendiente</option>
                                        <option value='confirmed' " . ($row['status'] === 'confirmed' ? 'selected' : '') . ">Confirmada</option>
                                        <option value='cancelled' " . ($row['status'] === 'cancelled' ? 'selected' : '') . ">Cancelada</option>
                                        <option value='completed' " . ($row['status'] === 'completed' ? 'selected' : '') . ">Completada</option>
                                    </select>
                                </form>
                            </td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='8'>No hay reservas registradas</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Horarios del Personal -->
        <div class="section">
            <h3>Horarios del Personal</h3>
            <button class="btn" onclick="openModal('scheduleModal')">Crear Horario</button>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Sede</th>
                        <th>Fecha</th>
                        <th>Hora Inicio</th>
                        <th>Hora Fin</th>
                        <th>Notas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $schedules_result->data_seek(0);
                    if ($schedules_result->num_rows > 0) {
                        while($row = $schedules_result->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td>" . $row["id"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["user_name"] ?? 'Sin usuario') . "</td>";
                            echo "<td>" . htmlspecialchars($row["branch_name"] ?? 'Sin sede') . "</td>";
                            echo "<td>" . date('d/m/Y', strtotime($row["schedule_date"])) . "</td>";
                            echo "<td>" . $row["start_time"] . "</td>";
                            echo "<td>" . $row["end_time"] . "</td>";
                            echo "<td>" . htmlspecialchars($row["notes"]) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7'>No hay horarios registrados</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Reportes -->
        <div class="section">
            <h3>Reportes</h3>
            <p>Funcionalidad de reportes próximamente disponible.</p>
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_orders; ?></div>
                    <div class="stat-label">Pedidos Totales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_reservations; ?></div>
                    <div class="stat-label">Reservas Totales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $confirmed_reservations; ?></div>
                    <div class="stat-label">Reservas Confirmadas</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para crear sede -->
    <div id="branchModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('branchModal')">&times;</span>
            <h3>Crear Nueva Sede</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_branch">
                <div class="form-group">
                    <label for="name">Nombre de la Sede:</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="address">Dirección:</label>
                    <input type="text" id="address" name="address">
                </div>
                <div class="form-group">
                    <label for="phone">Teléfono:</label>
                    <input type="text" id="phone" name="phone">
                </div>
                <div class="form-group">
                    <label for="manager_name">Nombre del Gerente:</label>
                    <input type="text" id="manager_name" name="manager_name">
                </div>
                <button type="submit" class="btn">Crear Sede</button>
            </form>
        </div>
    </div>

    <!-- Modal para crear usuario -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('userModal')">&times;</span>
            <h3>Crear Nuevo Usuario</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                <div class="form-group">
                    <label for="username">Nombre de Usuario:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Contraseña:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="full_name">Nombre Completo:</label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>
                <div class="form-group">
                    <label for="branch_id">Sede Asignada:</label>
                    <select id="branch_id" name="branch_id">
                        <option value="">Sin asignar</option>
                        <?php
                        $branches_result->data_seek(0);
                        while($row = $branches_result->fetch_assoc()) {
                            echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["name"]) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="role">Rol:</label>
                    <select id="role" name="role">
                        <option value="staff">Personal</option>
                        <option value="manager">Gerente</option>
                    </select>
                </div>
                <button type="submit" class="btn">Crear Usuario</button>
            </form>
        </div>
    </div>

    <!-- Modal para crear categoría de menú -->
    <div id="menuCategoryModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('menuCategoryModal')">&times;</span>
            <h3>Crear Categoría de Menú</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_menu_category">
                <div class="form-group">
                    <label for="category_name">Nombre de la Categoría:</label>
                    <input type="text" id="category_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="category_description">Descripción:</label>
                    <textarea id="category_description" name="description" rows="3"></textarea>
                </div>
                <button type="submit" class="btn">Crear Categoría</button>
            </form>
        </div>
    </div>

    <!-- Modal para crear ítem de menú -->
    <div id="menuItemModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('menuItemModal')">&times;</span>
            <h3>Crear Ítem de Menú</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_menu_item">
                <div class="form-group">
                    <label for="item_name">Nombre del Ítem:</label>
                    <input type="text" id="item_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="item_description">Descripción:</label>
                    <textarea id="item_description" name="description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label for="item_price">Precio:</label>
                    <input type="number" id="item_price" name="price" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="item_category">Categoría:</label>
                    <select id="item_category" name="category_id" required>
                        <option value="">Seleccionar Categoría</option>
                        <?php
                        $menu_categories_result->data_seek(0);
                        while($row = $menu_categories_result->fetch_assoc()) {
                            echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["name"]) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn">Crear Ítem</button>
            </form>
        </div>
    </div>

    <!-- Modal para crear pedido -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('orderModal')">&times;</span>
            <h3>Crear Nuevo Pedido</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_order">
                <div class="form-group">
                    <label for="order_branch">Sede:</label>
                    <select id="order_branch" name="branch_id" required>
                        <option value="">Seleccionar Sede</option>
                        <?php
                        $branches_result->data_seek(0);
                        while($row = $branches_result->fetch_assoc()) {
                            echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["name"]) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="order_user">Usuario:</label>
                    <select id="order_user" name="user_id" required>
                        <option value="">Seleccionar Usuario</option>
                        <?php
                        $users_result->data_seek(0);
                        while($row = $users_result->fetch_assoc()) {
                            echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["full_name"]) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="table_number">Número de Mesa:</label>
                    <input type="text" id="table_number" name="table_number" required>
                </div>
                <div class="form-group">
                    <label>Ítems del Pedido:</label>
                    <div id="order_items">
                        <div class="order-item">
                            <select name="items[0][id]" required>
                                <option value="">Seleccionar Ítem</option>
                                <?php
                                $menu_items_result->data_seek(0);
                                while($row = $menu_items_result->fetch_assoc()) {
                                    echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["name"]) . " - $" . number_format($row["price"], 0, ',', '.') . "</option>";
                                }
                                ?>
                            </select>
                            <input type="number" name="items[0][quantity]" placeholder="Cantidad" min="1" required>
                            <input type="text" name="items[0][notes]" placeholder="Notas">
                        </div>
                    </div>
                    <button type="button" onclick="addOrderItem()">Agregar Ítem</button>
                </div>
                <button type="submit" class="btn">Crear Pedido</button>
            </form>
        </div>
    </div>

    <!-- Modal para agregar ítem de inventario -->
    <div id="inventoryModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('inventoryModal')">&times;</span>
            <h3>Agregar Ítem de Inventario</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_inventory_item">
                <div class="form-group">
                    <label for="inventory_name">Nombre:</label>
                    <input type="text" id="inventory_name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="inventory_description">Descripción:</label>
                    <textarea id="inventory_description" name="description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label for="inventory_quantity">Cantidad:</label>
                    <input type="number" id="inventory_quantity" name="quantity" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="inventory_unit">Unidad:</label>
                    <input type="text" id="inventory_unit" name="unit" value="units" required>
                </div>
                <div class="form-group">
                    <label for="inventory_min_quantity">Cantidad Mínima:</label>
                    <input type="number" id="inventory_min_quantity" name="min_quantity" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="inventory_supplier">Proveedor:</label>
                    <input type="text" id="inventory_supplier" name="supplier">
                </div>
                <button type="submit" class="btn">Agregar Ítem</button>
            </form>
        </div>
    </div>

    <!-- Modal para crear reserva -->
    <div id="reservationModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('reservationModal')">&times;</span>
            <h3>Crear Reserva</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_reservation">
                <div class="form-group">
                    <label for="reservation_branch">Sede:</label>
                    <select id="reservation_branch" name="branch_id" required>
                        <option value="">Seleccionar Sede</option>
                        <?php
                        $branches_result->data_seek(0);
                        while($row = $branches_result->fetch_assoc()) {
                            echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["name"]) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="customer_name">Nombre del Cliente:</label>
                    <input type="text" id="customer_name" name="customer_name" required>
                </div>
                <div class="form-group">
                    <label for="customer_phone">Teléfono:</label>
                    <input type="text" id="customer_phone" name="phone">
                </div>
                <div class="form-group">
                    <label for="customer_email">Email:</label>
                    <input type="email" id="customer_email" name="email">
                </div>
                <div class="form-group">
                    <label for="reservation_date">Fecha:</label>
                    <input type="date" id="reservation_date" name="reservation_date" required>
                </div>
                <div class="form-group">
                    <label for="reservation_time">Hora:</label>
                    <input type="time" id="reservation_time" name="reservation_time" required>
                </div>
                <div class="form-group">
                    <label for="guests">Número de Invitados:</label>
                    <input type="number" id="guests" name="guests" min="1" required>
                </div>
                <div class="form-group">
                    <label for="reservation_notes">Notas:</label>
                    <textarea id="reservation_notes" name="notes" rows="3"></textarea>
                </div>
                <button type="submit" class="btn">Crear Reserva</button>
            </form>
        </div>
    </div>

    <!-- Modal para crear horario -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('scheduleModal')">&times;</span>
            <h3>Crear Horario</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_schedule">
                <div class="form-group">
                    <label for="schedule_user">Usuario:</label>
                    <select id="schedule_user" name="user_id" required>
                        <option value="">Seleccionar Usuario</option>
                        <?php
                        $users_result->data_seek(0);
                        while($row = $users_result->fetch_assoc()) {
                            echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["full_name"]) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="schedule_branch">Sede:</label>
                    <select id="schedule_branch" name="branch_id">
                        <option value="">Seleccionar Sede</option>
                        <?php
                        $branches_result->data_seek(0);
                        while($row = $branches_result->fetch_assoc()) {
                            echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["name"]) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="schedule_date">Fecha:</label>
                    <input type="date" id="schedule_date" name="schedule_date" required>
                </div>
                <div class="form-group">
                    <label for="start_time">Hora de Inicio:</label>
                    <input type="time" id="start_time" name="start_time" required>
                </div>
                <div class="form-group">
                    <label for="end_time">Hora de Fin:</label>
                    <input type="time" id="end_time" name="end_time" required>
                </div>
                <div class="form-group">
                    <label for="schedule_notes">Notas:</label>
                    <textarea id="schedule_notes" name="notes" rows="3"></textarea>
                </div>
                <button type="submit" class="btn">Crear Horario</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).style.display = "block";
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = "none";
        }

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = "none";
            }
        }

        // Función para agregar ítems al pedido dinámicamente
        let itemCount = 1;
        function addOrderItem() {
            const orderItems = document.getElementById('order_items');
            const newItem = document.createElement('div');
            newItem.className = 'order-item';
            newItem.innerHTML = `
                <select name="items[${itemCount}][id]" required>
                    <option value="">Seleccionar Ítem</option>
                    <?php
                    $menu_items_result->data_seek(0);
                    while($row = $menu_items_result->fetch_assoc()) {
                        echo "<option value='" . $row["id"] . "'>" . htmlspecialchars($row["name"]) . " - $" . number_format($row["price"], 0, ',', '.') . "</option>";
                    }
                    ?>
                </select>
                <input type="number" name="items[${itemCount}][quantity]" placeholder="Cantidad" min="1" required>
                <input type="text" name="items[${itemCount}][notes]" placeholder="Notas">
                <button type="button" onclick="removeOrderItem(this)">Eliminar</button>
            `;
            orderItems.appendChild(newItem);
            itemCount++;
        }

        function removeOrderItem(button) {
            button.parentElement.remove();
        }
    </script>
</body>
</html>
