<?php
// Lightweight runner for UFA_App_GUI.py
// This attempts to launch the Python GUI on the server host. Behaviour depends on server OS
header('Content-Type: application/json');
try {
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'UFA_App_GUI.py';
    if (!file_exists($script)) {
        echo json_encode(['ok' => false, 'error' => 'UFA_App_GUI.py not found']);
        exit;
    }

    // Choose python executable - rely on PATH; adjust if you need a full path
    $python = 'python';
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    if ($isWindows) {
        // Use start to detach on Windows. Command must be executed via shell.
        $cmd = 'start "" ' . escapeshellcmd($python) . ' ' . escapeshellarg($script);
        // pclose(popen()) detaches process on many Windows+PHP setups
        @pclose(@popen($cmd, 'r'));
        echo json_encode(['ok' => true, 'msg' => 'Launch attempted (Windows start)']);
        exit;
    } else {
        // Unix-like: run in background
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
        exec($cmd, $output, $ret);
        if ($ret === 0) echo json_encode(['ok' => true, 'msg' => 'Launch attempted (background)']);
        else echo json_encode(['ok' => false, 'error' => 'exec failed', 'code' => $ret]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
