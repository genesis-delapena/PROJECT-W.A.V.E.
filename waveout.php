<?php
session_start();
// Prevent browser caching after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
session_destroy();
header("Location: wavelogin.php");
exit;
?>