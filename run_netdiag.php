<?php
// Endpoint to request running WAVE_NetDiag.py as Administrator (best-effort)
// Note: Elevation from a web server context is environment-dependent. This
// attempts to invoke PowerShell Start-Process -Verb RunAs on Windows and
// will trigger a UAC prompt for the interactive user if possible.

session_name('WAVE_ADMIN');
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authenticated as admin']);
    exit;
}

$script = __DIR__ . DIRECTORY_SEPARATOR . 'WAVE_NetDiag.py';
if (!file_exists($script)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Script not found: ' . $script]);
    exit;
}

// Only implement Windows elevation (RunAs) — for other OS return not supported
$os = PHP_OS_FAMILY ?? PHP_OS;
try {
    if (stripos($os, 'Windows') !== false || strtoupper(substr(PHP_OS,0,3)) === 'WIN') {
        // Build PowerShell command to Start-Process with Verb RunAs (UAC)
        // It's a best-effort approach: the PHP process must have permission to start
        // interactive processes; the system will show a UAC prompt to the interactive user.
        $psScript = 'Start-Process -FilePath "python" -ArgumentList ' . escapeshellarg($script) . ' -Verb RunAs';
        $cmd = 'powershell -NoProfile -WindowStyle Hidden -Command ' . escapeshellarg($psScript);

        // Detach using pclose(popen()) to avoid blocking the web request
        @pclose(@popen($cmd, 'r'));

        echo json_encode(['success' => true, 'message' => 'Requested elevation (PowerShell Start-Process -Verb RunAs).']);
        exit;
    } else {
        // On *nix, just attempt to exec with sudo in background (likely not appropriate for webserver)
        $python = 'python3';
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
        exec($cmd, $output, $rc);
        if ($rc === 0) {
            echo json_encode(['success' => true, 'message' => 'Started NetDiag (background).']);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Failed to start NetDiag on this OS.']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    exit;
}

?>
