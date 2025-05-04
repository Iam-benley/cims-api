<?php
// Database configuration
$dbHost = 'localhost';
$dbName = 'clinic_inventory';
$dbUser = 'root';
$dbPass = '12345';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
