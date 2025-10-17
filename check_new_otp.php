<?php
session_start();
header('Content-Type: application/json');
include 'wavedb.php';

$response = ['ok' => false];

// Only notify for logged-in users
if (!isset($_SESSION['username'])) {
    $response['error'] = 'not_logged_in';
    echo json_encode($response);
    exit;
}

$user = $_SESSION['username'];

// Get the latest OTP created_at for this user
$stmt = $conn->prepare("SELECT MAX(created_at) AS latest FROM admin_otps WHERE username = ?");
$stmt->bind_param('s', $user);
$stmt->execute();
$stmt->bind_result($latest);
$stmt->fetch();
$stmt->close();

// Normalize
$latest_ts = $latest ? strtotime($latest) : 0;
$last_seen = isset($_SESSION['last_seen_otp_ts']) ? (int)$_SESSION['last_seen_otp_ts'] : 0;

if ($latest_ts > $last_seen) {
    // mark as seen now
    $_SESSION['last_seen_otp_ts'] = $latest_ts;
    $response['ok'] = true;
    $response['new'] = true;
    $response['latest'] = $latest;
} else {
    $response['ok'] = true;
    $response['new'] = false;
    $response['latest'] = $latest;
}

echo json_encode($response);

?>
