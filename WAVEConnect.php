<?php
// Define the external host to check (Google's public DNS)
$external_host = '8.8.8.8';
$port = 53; // DNS port is fast to check

// Set a very short timeout to fail quickly if unresponsive
$timeout = 1; // 1 second

// Attempt to open a socket connection to the external host
$connection = @fsockopen($external_host, $port, $errno, $errstr, $timeout);

if ($connection) {
    // Connection successful - assume "Online" (Strong/Stable)
    fclose($connection);
    // Redirect to the online login page
    header("Location: wavelogin.php");
    exit;
} else {
    // Connection failed - assume "Offline" (Weak/No connection from server's perspective)
    // You could log $errstr here for debugging
    
    // Redirect to the offline login page
    header("Location: wavelogin_offline.php");
    exit;
}

// Fallback to the offline login if headers were already sent (shouldn't happen)
// include 'wavelogin_offline.php';
?>