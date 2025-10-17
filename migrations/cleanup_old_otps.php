<?php
/**
 * cleanup_old_otps.php
 *
 * Deletes admin_otps rows older than N days. Intended to be run via CLI (Task Scheduler)
 * or via browser with a secret query parameter (not recommended publicly).
 *
 * Usage (CLI):
 *   php migrations/cleanup_old_otps.php [days] [--dry-run]
 * Examples:
 *   php migrations/cleanup_old_otps.php         # deletes rows older than 30 days
 *   php migrations/cleanup_old_otps.php 7       # deletes rows older than 7 days
 *   php migrations/cleanup_old_otps.php 7 --dry-run  # show how many would be deleted
 *
 * Usage (HTTP - optional):
 *   http://localhost/wave_project/migrations/cleanup_old_otps.php?secret=PUT_A_SECRET_HERE&days=30
 * Note: If used via HTTP, you must set the SECRET environment variable on the server
 *       or edit this file to hard-code a secret (not recommended). CLI is safer.
 */

// Configurable default
$defaultDays = 30;

// Simple secret support for web access. If you plan to run via HTTP, set an env var
// e.g. in Apache/Windows environment: setx OTP_CLEANUP_SECRET "your-secret"
$httpSecret = getenv('OTP_CLEANUP_SECRET') ?: null;

// Load DB
require_once __DIR__ . '/../wavedb.php';

// Parse arguments
$cli = (php_sapi_name() === 'cli');
$days = $defaultDays;
$dryRun = false;

if ($cli) {
    global $argv;
    if (isset($argv[1]) && strpos($argv[1], '--') !== 0) {
        $days = (int)$argv[1] ?: $defaultDays;
    }
    $dryRun = in_array('--dry-run', $argv, true);
} else {
    // HTTP invocation
    $secret = $_GET['secret'] ?? '';
    if (!$httpSecret || $secret !== $httpSecret) {
        header('HTTP/1.1 403 Forbidden');
        echo "Forbidden. Set OTP_CLEANUP_SECRET on the server or run this script from CLI.\n";
        exit;
    }
    $days = isset($_GET['days']) ? (int)$_GET['days'] : $defaultDays;
    $dryRun = isset($_GET['dry_run']);
}

$days = max(1, (int)$days);
$cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

function out($s) {
    echo $s . "\n";
}

out("Admin OTP cleanup: rows older than {$days} days (created_at < {$cutoff})");

// Count rows
$stmt = $conn->prepare("SELECT COUNT(*) FROM admin_otps WHERE created_at < ?");
$stmt->bind_param('s', $cutoff);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

if ($count == 0) {
    out("No rows to delete.");
    exit(0);
}

out("Rows matched: {$count}");

if ($dryRun) {
    out("Dry run enabled — no rows will be deleted. Use without --dry-run to perform deletion.");
    exit(0);
}

// Perform deletion
$del = $conn->prepare("DELETE FROM admin_otps WHERE created_at < ?");
$del->bind_param('s', $cutoff);
$del->execute();
$affected = $del->affected_rows;
$errNo = $del->errno;
$err = $del->error;
$del->close();

if ($errNo) {
    out("Error deleting rows ({$errNo}): {$err}");
    exit(1);
}

out("Deleted {$affected} rows from admin_otps.");

// Optional: optimize table to reclaim space (uncomment if desired)
// $conn->query('OPTIMIZE TABLE admin_otps');

$conn->close();

out("Cleanup complete.");
