<?php
session_start();
	// Only destroy the session associated with the current request to avoid logging other tabs out
	// Determine current session name and destroy that session
	$currentName = session_name();
	session_start();
	session_unset();
	session_destroy();
	// Remove the session cookie for the current session
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie($currentName, '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
	}
	// Prevent browser caching after logout
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Cache-Control: post-check=0, pre-check=0', false);
	header('Pragma: no-cache');
	header("Location: wavelogin.php");
	exit;
?>