<?php
// URL of the Flask server
$serverUrl = "http://192.168.0.3:5000/get";

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $serverUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

// Execute request
$response = curl_exec($ch);

// Handle errors
if (curl_errno($ch)) {
    echo json_encode(["error" => "Failed to connect to Server_PC.py"]);
    exit;
}
curl_close($ch);

// Output JSON directly
header("Content-Type: application/json");
echo $response;
