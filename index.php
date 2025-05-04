
<?php
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");

    // Handle preflight request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit(); // Stop further execution
    }

    require 'dbcon.php';

    $requestData = json_decode(file_get_contents('php://input'), true);
    $method      = $requestData['method'];
    $data        = $requestData['data'];

    try {
        switch ($method) {
            case 'getInventory':
                //Validate data
                $category   = $data['category_id'] ?? null;
                $name       = $data['name'] ?? null;
                $params     = [];
                $conditions = [];

                $query = "SELECT * FROM inventory";

                if (!empty($name)) {
                    $conditions[] = "name LIKE ?";
                    $params[]     = "%$name%";
                }

                if (!empty($category)) {
                    $conditions[] = "category_id = ?";
                    $params[]     = $category;
                }


                if (!empty($conditions)) {
                    $query .= " WHERE " . implode(" AND ", $conditions);
                }

                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'data' => $inventory]);
                break;

            case 'addItem':
                //Validate data

                // $required = ['name', 'category_id', 'quantity', 'unit_id'];

                // foreach ($required as $field) {
                //     if (empty($input[$field])) {
                //         throw new Exception("Missing required field: $field");
                //     }
                // }

                // if ($input['quantity'] < 0) {
                //     throw new Exception("Quantity cannot be negative");
                // }

                $stmt = $pdo->prepare("INSERT INTO inventory
                                  (name, category_id, quantity, unit_id, expiry_date)
                                  VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['name'],
                    $data['category_id'],
                    $data['quantity'],
                    $data['unit_id'],
                    null,
                ]);

                $itemId = $pdo->lastInsertId();

                // // Log the addition
                // $stmt = $pdo->prepare("INSERT INTO inventory_logs
                //                   (inventory_id, quantity_change, action, notes)
                //                   VALUES (?, ?, 'add', 'Initial inventory addition')");
                // $stmt->execute([$itemId, $input['quantity']]);

                echo json_encode(['success' => true, 'id' => $itemId]);
                break;

            case 'updateItem':
                if (empty($input['id'])) {
                    throw new Exception("Item ID is required");
                }

                $updates = [];
                $params  = [];

                if (isset($input['name'])) {
                    $updates[] = "name = ?";
                    $params[]  = $input['name'];
                }

                if (isset($input['category_id'])) {
                    $updates[] = "category_id = ?";
                    $params[]  = $input['category_id'];
                }

                if (isset($input['quantity'])) {
                    if ($input['quantity'] < 0) {
                        throw new Exception("Quantity cannot be negative");
                    }
                    $updates[] = "quantity = ?";
                    $params[]  = $input['quantity'];
                }

                if (isset($input['unit_id'])) {
                    $updates[] = "unit_id = ?";
                    $params[]  = $input['unit_id'];
                }

                if (isset($input['expiry_date'])) {
                    $updates[] = "expiry_date = ?";
                    $params[]  = $input['expiry_date'];
                }

                if (empty($updates)) {
                    throw new Exception("No fields to update");
                }

                $params[] = $input['id'];

                $query = "UPDATE inventory SET " . implode(", ", $updates) . " WHERE id = ?";
                $stmt  = $pdo->prepare($query);
                $stmt->execute($params);

                // Log the update if quantity changed
                if (isset($input['quantity'])) {
                    $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = ?");
                    $stmt->execute([$input['id']]);
                    $current = $stmt->fetchColumn();

                    $change = $input['quantity'] - $current;
                    if ($change != 0) {
                        $action = $change > 0 ? 'add' : 'remove';
                        $stmt   = $pdo->prepare("INSERT INTO inventory_logs
                                          (inventory_id, quantity_change, action, notes)
                                          VALUES (?, ?, ?, 'Manual quantity adjustment')");
                        $stmt->execute([$input['id'], abs($change), $action]);
                    }
                }

                echo json_encode(['success' => true]);
                break;

            case 'deleteItem':
                if (empty($input['id'])) {
                    throw new Exception("Item ID is required");
                }

                // First get the item to log the deletion
                $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = ?");
                $stmt->execute([$input['id']]);
                $quantity = $stmt->fetchColumn();

                // Delete the item
                $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
                $stmt->execute([$input['id']]);

                // Log the deletion
                if ($quantity > 0) {
                    $stmt = $pdo->prepare("INSERT INTO inventory_logs
                                      (inventory_id, quantity_change, action, notes)
                                      VALUES (?, ?, 'remove', 'Item deleted from inventory')");
                    $stmt->execute([$input['id'], $quantity]);
                }

                echo json_encode(['success' => true]);
                break;

            case 'getCategories':
                $stmt = $pdo->query("SELECT id, name FROM categories");
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'getUnits':
                $stmt = $pdo->query("SELECT id, name, abbreviation FROM units");
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'getMonthlyReport':
                $month    = $_GET['month'] ?? date('Y-m');
                $category = $_GET['category'] ?? null;

                $query = "SELECT i.name, c.name as category,
                     SUM(CASE WHEN l.action = 'remove' THEN l.quantity_change ELSE 0 END) as dispensed,
                     u.abbreviation as unit
                     FROM inventory_logs l
                     JOIN inventory i ON l.inventory_id = i.id
                     JOIN categories c ON i.category_id = c.id
                     JOIN units u ON i.unit_id = u.id
                     WHERE DATE_FORMAT(l.created_at, '%Y-%m') = ?";

                $params = [$month];

                if ($category) {
                    $query .= " AND c.name = ?";
                    $params[] = $category;
                }

                $query .= " GROUP BY i.id, i.name, c.name, u.abbreviation";

                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $report = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'data' => $report, 'month' => $month]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid method']);
                break;
        }
    } catch (Exception $e) {
        http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}