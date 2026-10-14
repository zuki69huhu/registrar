<?php
require_once __DIR__ . '/../includes/db.php';

$columns = [
    'profile_picture' => 'VARCHAR(255) DEFAULT NULL',
    'year_level' => 'VARCHAR(50) DEFAULT NULL',
    'course' => 'VARCHAR(100) DEFAULT NULL',
    'contact_number' => 'VARCHAR(50) DEFAULT NULL',
    'address' => 'TEXT DEFAULT NULL'
];

foreach ($columns as $column => $definition) {
    // Check if column exists
    $result = $mysqli->query("SHOW COLUMNS FROM users LIKE '$column'");
    if ($result->num_rows === 0) {
        $sql = "ALTER TABLE users ADD COLUMN $column $definition";
        if ($mysqli->query($sql)) {
            echo "Added $column to users table.\n";
        } else {
            echo "Error adding $column: " . $mysqli->error . "\n";
        }
    } else {
        echo "Column $column already exists.\n";
    }
}
echo "Migration completed.\n";
?>
