<?php
// Endpoint to start FA_Code_Generator_GUI.py from the web UI (admin only)
// Returns JSON { success: bool, message: string }

// Require admin session
session_name('WAVE_ADMIN');
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Path to the Python script (adjust if your file is elsewhere)
$script = __DIR__ . DIRECTORY_SEPARATOR . 'FA_Code_Generator_GUI.py';

if (!file_exists($script)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Script not found: ' . $script]);
    exit;
}

// Try to start the script in background. Behavior differs between Windows and *nix.
try {
    $php_os = PHP_OS_FAMILY ?? PHP_OS;
    if (stripos($php_os, 'Windows') !== false || strtoupper(substr(PHP_OS,0,3)) === 'WIN') {
        // Use start /B to run in background on Windows
        $python = 'python'; // assumes python is available in PATH; adjust if necessary
        $cmd = 'start /B "" ' . escapeshellcmd($python) . ' ' . escapeshellarg($script);
        // popen + pclose to detach
        pclose(popen($cmd, 'r'));
        echo json_encode(['success' => true, 'message' => 'FA script started (Windows background).']);
        exit;
    } else {
        // Unix-like: run in background with nohup
        $python = 'python3';
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 & echo $!';
        $output = [];
        exec($cmd, $output, $rc);
        if ($rc === 0) {
            $pid = isset($output[0]) ? trim($output[0]) : null;
            echo json_encode(['success' => true, 'message' => 'FA script started', 'pid' => $pid]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to start script (rc=' . intval($rc) . ')']);
            exit;
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    exit;
}

?>
