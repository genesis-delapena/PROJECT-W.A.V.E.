<?php
/**
 * Migration: remove UNIQUE index on `username` in `admin_otps` so multiple OTP
 * rows per username are allowed (history preserved).
 *
 * Usage (one-time):
 * - Run in browser: http://localhost/wave_project/migrations/remove_unique_username_on_admin_otps.php
 * - Or run from CLI: php migrations/remove_unique_username_on_admin_otps.php
 *
 * The script will:
 * 1) Connect using wavedb.php
 * 2) Detect any UNIQUE index on `username` (case-insensitive match)
 * 3) If found, drop the index. If not found, report and exit.
 *
 * IMPORTANT: Please backup the database before running migrations.
 */

require_once __DIR__ . '/../wavedb.php';

header('Content-Type: text/plain');

echo "Migration: remove UNIQUE(username) index from admin_otps\n";

// Check existing indexes
$res = $conn->query("SHOW INDEX FROM admin_otps");
if (!$res) {
    echo "Error reading index info: " . $conn->error . "\n";
    exit(1);
}

$uniqueIndexes = [];
while ($row = $res->fetch_assoc()) {
    if ($row['Non_unique'] == 0) {
        // Index is unique; collect by Key_name
        $uniqueIndexes[$row['Key_name']][] = $row['Column_name'];
    }
}

// Find an index where the sole column is 'username' (or the first column)
$targetIndex = null;
foreach ($uniqueIndexes as $key => $cols) {
    if (in_array('username', $cols, true)) {
        // If index covers multiple columns but includes username, we'll still drop it
        $targetIndex = $key;
        break;
    }
}

if (!$targetIndex) {
    echo "No unique index on 'username' found. Nothing to do.\n";
    exit(0);
}

// Confirm details
echo "Found unique index '$targetIndex' on columns: " . implode(',', $uniqueIndexes[$targetIndex]) . "\n";

// Drop the index
$sql = "ALTER TABLE admin_otps DROP INDEX `$targetIndex`";
if ($conn->query($sql) === TRUE) {
    echo "Dropped index '$targetIndex'.\n";
    echo "Now admin_otps will accept multiple rows per username.\n";
} else {
    echo "Failed to drop index: " . $conn->error . "\n";
    exit(1);
}

echo "Migration complete. Please remove or secure this file after use.\n";

$conn->close();
