<?php
// pc_proxy.php - simple same-origin proxy to forward messages to Flask server
// Accepts POST with JSON body: { message: "..." }
// Forwards to configured Flask host and returns the Flask response (or error).

// Configure the Flask server address reachable from the PHP host
$flask_hosts = [
    'http://192.168.0.2:5000',
    'http://192.168.0.3:5000'
];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if (empty($raw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Try each Flask host until success; collect attempts for debugging
$attempts = [];
foreach ($flask_hosts as $host) {
    $url = rtrim($host, '/') . '/send_from_pc';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $resp = curl_exec($ch);
    $err = curl_errno($ch);
    $errstr = $err ? curl_error($ch) : null;
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $attempts[] = [
        'host' => $host,
        'http_code' => $http,
        'curl_errno' => $err,
        'curl_error' => $errstr,
        'response_snippet' => $resp !== false ? substr($resp, 0, 512) : null
    ];

    if (!$err && $http >= 200 && $http < 300) {
        // Forward success response body
        http_response_code($http);
        echo $resp !== false ? $resp : json_encode(['status' => 'ok']);
        exit;
    }

    // Log the failed attempt to Apache error log for server-side debugging
    error_log("pc_proxy: attempt to $url returned http=$http curl_errno=$err curl_error=" . ($errstr ?: ''));
}

// If we get here, all hosts failed
http_response_code(502);
echo json_encode(['error' => 'Failed to forward to Flask hosts', 'attempts' => $attempts]);
exit;
