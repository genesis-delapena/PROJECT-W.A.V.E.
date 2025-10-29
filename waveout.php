<?php
session_start();
	// Only destroy the session associated with the current request to avoid logging other tabs out
	// Determine current session name and destroy that session
	$currentName = session_name();
	// capture username before destroying session so we can remove active_sessions entry
	$username = isset($_SESSION['username']) ? $_SESSION['username'] : null;
	session_unset();
	session_destroy();

	// If we have DB access and a username, remove the active session record so a future login is clean
	if (!empty($username)) {
		@include_once __DIR__ . '/wavedb.php';
		if (isset($conn)) {
			try {
				$del = $conn->prepare("DELETE FROM active_sessions WHERE username=?");
				if ($del) {
					$del->bind_param('s', $username);
					$del->execute();
					$del->close();
				}
			} catch (Exception $e) { error_log('Failed to remove active_session on logout: ' . $e->getMessage()); }
		}
	}
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