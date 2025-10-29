<?php
// Simple endpoint to verify the current session token matches DB token.
header('Content-Type: application/json; charset=utf-8');
// Determine session name from cookies (support both roles)
$sessName = null;
if (!empty($_COOKIE['WAVE_ADMIN'])) $sessName = 'WAVE_ADMIN';
elseif (!empty($_COOKIE['WAVE_USER'])) $sessName = 'WAVE_USER';
else $sessName = session_name();

if ($sessName) session_name($sessName);
session_start();

include_once __DIR__ . '/wavedb.php';

$response = ['valid' => false, 'reason' => 'no_session'];
try {
  if (!empty($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $token = $_SESSION['session_token'] ?? '';
    if (empty($token)) {
      $response = ['valid' => false, 'reason' => 'no_token', 'message' => 'someone logged in using this account. you will be automatically log out'];
      echo json_encode($response);
      exit;
    }
    // Query active_sessions table
    $stmt = $conn->prepare("SELECT session_token FROM active_sessions WHERE username=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('s', $username);
      $stmt->execute();
      $stmt->bind_result($dbToken);
      if ($stmt->fetch()) {
        $stmt->close();
        if (hash_equals($dbToken, $token)) {
          $response = ['valid' => true];
        } else {
          $response = ['valid' => false, 'reason' => 'mismatch', 'message' => 'someone logged in using this account. you will be automatically log out'];
        }
      } else {
        $stmt->close();
        $response = ['valid' => false, 'reason' => 'no_db_row', 'message' => 'someone logged in using this account. you will be automatically log out'];
      }
    } else {
      $response = ['valid' => true]; // if DB stmt fails, be permissive to avoid locking out users
    }
  } else {
    $response = ['valid' => false, 'reason' => 'no_session'];
  }
} catch (Exception $e) {
  $response = ['valid' => true]; // on error, allow session to continue
}

echo json_encode($response);
exit;
