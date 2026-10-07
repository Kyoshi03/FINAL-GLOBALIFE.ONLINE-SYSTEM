<?php
/**
 * One-time cleanup for the split-name migration.
 * The editable first/middle/last/suffix columns are now the only name source.
 */
require_once __DIR__ . '/config/database.php';

$conn = getDBConnection();
$column = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'");
if (!$column) {
    fwrite(STDERR, "Could not inspect users.full_name: " . $conn->error . PHP_EOL);
    $conn->close();
    exit(1);
}
if ($column->num_rows === 0) {
    echo "The legacy full_name column is already absent.\n";
    $conn->close();
    exit(0);
}

$sql = "ALTER TABLE users DROP COLUMN full_name";

if (!$conn->query($sql)) {
    fwrite(STDERR, "Could not remove legacy full_name: " . $conn->error . PHP_EOL);
    exit(1);
}

echo "The legacy full_name column has been removed.\n";
