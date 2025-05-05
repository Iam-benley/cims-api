
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
                // Validate data
                $category   = $data['category_id'] ?? null;
                $name       = $data['name'] ?? null;
                $params     = [];
                $conditions = [];
            
                // Updated query with joins
                $query = "SELECT i.*, c.name as category_name, u.name as unit_name 
                          FROM inventory i
                          LEFT JOIN categories c ON i.category_id = c.id
                          LEFT JOIN units u ON i.unit_id = u.id";
            
                if (!empty($name)) {
                    $conditions[] = "i.name LIKE ?";
                    $params[]     = "%$name%";
                }
            
                if (!empty($category)) {
                    $conditions[] = "i.category_id = ?";
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
                    $data['expiry_date'] ?? null,
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
                // if (empty($input['id'])) {
                //     throw new Exception("Item ID is required");
                // }

                $updates = [];
                $params  = [];

                if (isset($data['name'])) {
                    $updates[] = "name = ?";
                    $params[]  = $data['name'];
                }

                if (isset($data['category_id'])) {
                    $updates[] = "category_id = ?";
                    $params[]  = $data['category_id'];
                }

                if (isset($data['quantity'])) {
                    // if ($input['quantity'] < 0) {
                    //     throw new Exception("Quantity cannot be negative");
                    // }
                    $updates[] = "quantity = ?";
                    $params[]  = $data['quantity'];
                }

                if (isset($data['unit_id'])) {
                    $updates[] = "unit_id = ?";
                    $params[]  = $data['unit_id'];
                }

                if (isset($data['expiry_date'])) {
                    $updates[] = "expiry_date = ?";
                    $params[]  = $data['expiry_date'];
                }

                if (empty($updates)) {
                    throw new Exception("No fields to update");
                }

                $params[] = $data['id'];

                $query = "UPDATE inventory SET " . implode(", ", $updates) . " WHERE id = ?";
                $stmt  = $pdo->prepare($query);
                $stmt->execute($params);

                // Log the update if quantity changed
                // if (isset($input['quantity'])) {
                //     $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = ?");
                //     $stmt->execute([$input['id']]);
                //     $current = $stmt->fetchColumn();

                //     $change = $input['quantity'] - $current;
                //     if ($change != 0) {
                //         $action = $change > 0 ? 'add' : 'remove';
                //         $stmt   = $pdo->prepare("INSERT INTO inventory_logs
                //                           (inventory_id, quantity_change, action, notes)
                //                           VALUES (?, ?, ?, 'Manual quantity adjustment')");
                //         $stmt->execute([$input['id'], abs($change), $action]);
                //     }
                // }

                echo json_encode(['success' => true, 'message' => 'Item successfully updated']);
                break;

            case 'deleteItem':
                // if (empty($input['id'])) {
                //     throw new Exception("Item ID is required");
                // }

                // First get the item to log the deletion
                $stmt = $pdo->prepare("SELECT quantity FROM inventory WHERE id = ?");
                $stmt->execute([$data['id']]);
                $quantity = $stmt->fetchColumn();

                // // Log the deletion
                // if ($quantity > 0) {
                //     $stmt = $pdo->prepare("INSERT INTO inventory_logs
                //                       (inventory_id, quantity_change, action, notes)
                //                       VALUES (?, ?, 'remove', 'Item deleted from inventory')");
                //     $stmt->execute([$data['id'], $quantity]);
                // }

                // Delete the item
                $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
                $stmt->execute([$data['id']]);

                echo json_encode(['success' => true]);
                break;

            case 'getCategories':
                $stmt = $pdo->query("SELECT id, name FROM categories");
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $categories]);
                break;

            case 'getUnits':
                $stmt = $pdo->query("SELECT id, name, abbreviation FROM units");
                $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $units]);
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