<?php
$host = "localhost";
$user = "root"; // default in XAMPP
$pass = "MGW4V3J0LL1B33";     // default empty
$dbname = "wave_db";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>