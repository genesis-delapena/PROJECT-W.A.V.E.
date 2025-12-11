<?php
// Robust logout: destroy both default and admin sessions and clear active_sessions

function destroy_session_by_name($name) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name($name);
    session_start();
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : null;
    session_unset();
    session_destroy();
    // Remove cookie for this session namespace
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie($name, '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    return $username;
}

// Destroy admin session if present
$adminUser = destroy_session_by_name('WAVE_ADMIN');
// Destroy default session as well (for user dashboard namespace)
$defaultUser = destroy_session_by_name(session_name());

// Prefer whichever username we captured to clear active_sessions
$username = $adminUser ?: $defaultUser;

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

// Prevent browser caching after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header("Location: wavelogin.php");
exit;
?>