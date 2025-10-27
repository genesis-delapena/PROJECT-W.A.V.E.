<?php
// Try multiple candidate Flask hosts so PHP can reach the Server_PC.py even if IP changes
$candidates = [
    'http://192.168.0.2:5000/get',
    'http://192.168.0.3:5000/get',
    'http://localhost:5000/get',
    'http://127.0.0.1:5000/get'
];

header('Content-Type: application/json');

$attempts = [];
foreach ($candidates as $serverUrl) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $serverUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errstr = $errno ? curl_error($ch) : null;
    curl_close($ch);

    $attempts[] = ['url' => $serverUrl, 'http' => $http, 'curl_errno' => $errno, 'curl_error' => $errstr];

    if (!$errno && $http >= 200 && $http < 300 && $response) {
        // Basic sanity check: valid JSON
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo $response;
            exit;
        }
        // If not valid JSON, still return it as-is (best-effort)
        echo $response;
        exit;
    }
}

// If we get here, all attempts failed
http_response_code(502);
echo json_encode(['error' => 'Failed to fetch sensors from Flask server', 'attempts' => $attempts]);
exit;
