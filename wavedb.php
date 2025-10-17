<?php
$host = "localhost";
$user = "root"; // default in XAMPP
$pass = "MGW4v3J0ll1b33";     // default empty
$dbname = "wave_db";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>