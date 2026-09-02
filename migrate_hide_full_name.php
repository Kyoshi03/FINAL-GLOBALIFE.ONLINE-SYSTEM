<?php
/**
 * One-time local database cleanup for the split-name migration.
 * Keeps full_name as an invisible generated compatibility column so the
 * current pages continue to work while phpMyAdmin stays focused on the
 * editable name fields.
 */
require_once __DIR__ . '/config/database.php';

$conn = getDBConnection();
$sql = "ALTER TABLE users MODIFY COLUMN full_name VARCHAR(100) GENERATED ALWAYS AS ("
    . dbUsersFullNameExpression()
    . ') STORED INVISIBLE';

if (!$conn->query($sql)) {
    fwrite(STDERR, "Could not hide full_name: " . $conn->error . PHP_EOL);
    exit(1);
}

echo "The full_name compatibility column is now hidden from normal table views.\n";
