<?php
$host = 'localhost';
$user = 'root';
$password = '12345';
$dbname = 'clinic_inventory';

try {
    // Step 1: Connect to MySQL without selecting a database
    $pdo = new PDO("mysql:host=$host", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Step 2: Create the database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname");
    echo "Database '$dbname' created or already exists.<br>";

    // Step 3: Connect to the newly created database
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to database '$dbname'.<br>";

    // Step 4: Create tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL 
        );

        CREATE TABLE IF NOT EXISTS units (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(20) NOT NULL UNIQUE,
            abbreviation VARCHAR(10) NOT NULL UNIQUE
        );

        CREATE TABLE IF NOT EXISTS inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            category_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            unit_id INT NOT NULL,
            expiry_date DATE NULL,
            date_added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id),
            FOREIGN KEY (unit_id) REFERENCES units(id)
        );


        CREATE TABLE IF NOT EXISTS dispensed_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inventory_id INT NOT NULL,
            quantity INT NOT NULL,
            dispensed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (inventory_id) REFERENCES inventory(id)
        );
    ");
    echo "Tables created successfully.<br>";

    // Step 5: Insert categories and units
    $pdo->exec("
        INSERT IGNORE INTO categories (name) VALUES 
        ('Medicine'), ('Equipment'), ('Consumables');

        INSERT IGNORE INTO units (name, abbreviation) VALUES 
        ('pieces', 'pcs'), 
        ('bottle', 'btl'), 
        ('boxes', 'bxs');
    ");
    echo "Categories and units inserted.<br>";

    // Step 6: Prepare sample inventory names
    $sampleItems = [
        'Medicine' => ['Paracetamol', 'Ibuprofen', 'Amoxicillin', 'Cough Syrup'],
        'Equipment' => ['Stethoscope', 'Thermometer', 'Blood Pressure Monitor', 'Glucometer'],
        'Consumables' => ['Syringes', 'Cotton Balls', 'Alcohol Pads', 'Face Masks'],
    ];

    // Fetch category and unit IDs
    $categories = $pdo->query("SELECT id, name FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);
    $units = $pdo->query("SELECT id FROM units")->fetchAll(PDO::FETCH_COLUMN);

    $inventoryIds = [];

    // Step 7: Insert inventory items
    foreach ($sampleItems as $categoryName => $items) {
        $categoryId = array_search($categoryName, $categories);
        foreach ($items as $itemName) {
            $unitId = $units[array_rand($units)];
            $quantity = rand(10, 500); // Whole number
            $expiry = (rand(0, 1) ? date('Y-m-d', strtotime("+".rand(60, 365)." days")) : null);

            $stmt = $pdo->prepare("INSERT INTO inventory (name, category_id, quantity, unit_id, expiry_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$itemName, $categoryId, $quantity, $unitId, $expiry]);
            $inventoryIds[] = $pdo->lastInsertId();
        }
    }

    echo "Sample inventory data inserted.<br>";

    // Step 8: Insert dispensed items (5 per inventory record from Jan–Apr 2025)
    foreach ($inventoryIds as $invId) {
        for ($i = 0; $i < 5; $i++) {
            $dispenseDate = date('Y-m-d', strtotime("2025-01-01 +".rand(0, 120)." days"));
            $dispenseQty = rand(1, 20); // Whole number
            $pdo->prepare("INSERT INTO dispensed_items (inventory_id, quantity, dispensed_at) VALUES (?, ?, ?)")
                ->execute([$invId, $dispenseQty, $dispenseDate]);
        }
    }

    echo "Sample dispensed items inserted.<br>";

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
