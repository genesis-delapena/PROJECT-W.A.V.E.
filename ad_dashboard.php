<?php
$timestamp = date('Y-m-d h:i:s A');
// ──────────────── EVENT LOG AJAX HANDLER (Robust, Python-style) ────────────────
if (isset($_POST['log_to_event_log'])) {
  // Use admin session name when handling admin pages; for log API prefer explicit user param
  session_name('WAVE_ADMIN');
  session_start();
  include 'wavedb.php';
  // PHP version of classifyLog to match JS logic
  function classifyLog($type, $message) {
    $msg = strtolower($message);
    if (preg_match('/login|logged in|logout|logged out/', $msg)) return 'ACCESS';
    if ($type === 'info' || strpos($msg, 'info') !== false) return 'INFO';
    if ($type === 'alert' || preg_match('/shutdown|powered on/', $msg)) return 'ALERT';
    if (preg_match('/sensor|all sensors/', $msg)) return 'ACTION';
    if ($type === 'warn' || preg_match('/⚠️|delay|fail|error|alarm/', $msg)) return 'ALARM';
    return 'INFO';
  }

  function log_notification($conn, $event, $description, $event_status = "INFO") {
    try {
      date_default_timezone_set('Asia/Manila');
      $timestamp = date('m-d-Y h:i:s A');
      $sql = "INSERT INTO event_log (MG_UName, event_timestamp, event_desc, event_status) VALUES (?, ?, ?, ?)";
      $stmt = $conn->prepare($sql);
      if (!$stmt) throw new Exception($conn->error);
      $stmt->bind_param("ssss", $event, $timestamp, $description, $event_status);
      $stmt->execute();
      $stmt->close();
      return ["success" => true, "error" => null];
    } catch (Exception $e) {
      return ["success" => false, "error" => $e->getMessage()];
    }
  }
  // Prefer an explicit username sent from the client (user dashboard) so logs reflect origin
  $user = $_POST['user'] ?? $_SESSION['username'] ?? 'Unknown';
  $desc = $_POST['desc'] ?? '';
  $event_source = $_POST['event_source'] ?? '';
  if ($event_source) {
    // If DB doesn't have an explicit column, embed source into description for now
    $desc = $desc . " [source:" . $event_source . "]";
  }
  $type = $_POST['status'] ?? '';
  $event_status = classifyLog($type, $desc);
  $result = log_notification($conn, $user, $desc, $event_status);
  header('Content-Type: application/json');
  echo json_encode($result);
  exit;
}

// Use explicit admin session name for the admin dashboard to avoid collision with user sessions
session_name('WAVE_ADMIN');
session_start();
// include socket secret helper for generating short token
include_once __DIR__ . '/socket_secret.php';
// Strict cache control to prevent back after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
include 'wavedb.php';

// Strict session check: if not logged in, always redirect to login
if (!isset($_SESSION["username"])) {
  header("Location: wavelogin.php");
  exit;
}

// Enforce single active session per account: compare stored token with session token
try {
  if (!empty($_SESSION['username'])) {
    $checkTokenStmt = $conn->prepare("SELECT session_token FROM active_sessions WHERE username=? LIMIT 1");
    if ($checkTokenStmt) {
      $checkTokenStmt->bind_param('s', $_SESSION['username']);
      $checkTokenStmt->execute();
      $checkTokenStmt->bind_result($dbSessionToken);
      if ($checkTokenStmt->fetch()) {
        $checkTokenStmt->close();
        // If tokens mismatch, destroy this session and force re-login
        if (empty($_SESSION['session_token']) || !hash_equals($dbSessionToken, $_SESSION['session_token'])) {
          // Another device logged in. Notify the current client and then logout.
          $kick_msg = 'someone logged in using this account. you will be automatically log out';
          // Close session for writing so waveout can destroy it reliably
          session_write_close();
          // Render a small interstitial page that notifies the user and then logs out
          ?><!doctype html>
          <html lang="en">
          <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Signed Out</title>
            <style>body{font-family:Segoe UI,Arial,Helvetica,sans-serif;background:#f8fafc;color:#0f172a;display:flex;align-items:center;justify-content:center;height:100vh;margin:0} .box{background:#fff;padding:28px;border-radius:12px;box-shadow:0 8px 32px rgba(2,6,23,0.08);max-width:720px;text-align:center} h1{font-size:20px;margin-bottom:8px;color:#072f4a} p{margin:0 0 12px;font-size:16px;color:#0b2233} .count{font-weight:800;color:#0b3b5a}</style>
          </head>
          <body>
            <div class="box">
              <h1>Signed in elsewhere</h1>
              <p><?php echo htmlspecialchars($kick_msg); ?></p>
              <p>You will be redirected to the login page in <span id="sec" class="count">5</span>s.</p>
              <p><a href="waveout.php">Log out now</a></p>
            </div>
            <script>
              (function(){
                var s = 5; var el = document.getElementById('sec');
                var t = setInterval(function(){ s--; if(s<=0){ clearInterval(t); window.location.href='waveout.php'; } el.textContent = s; }, 1000);
              })();
            </script>
          </body>
          </html><?php
          exit;
        }
      } else {
        $checkTokenStmt->close();
      }
    }
  }
} catch (Exception $e) {
  // Non-fatal: allow access but log for investigation
  error_log('Session check error (admin): ' . $e->getMessage());
}
/* ─────────────────────────────────────────────────────────────────────────────
   OPTIONAL SAME-ORIGIN PROXY API
   - Enables fetch from same origin to avoid CORS.
   - Frontend will call ?api=get first, then fallback to Flask host.
   ───────────────────────────────────────────────────────────────────────────── */
if (isset($_GET['api']) && $_GET['api'] === 'get') {
  // Adjust the Python server IP/port here if needed (Server_PC.py prints the server address):
  // Server_PC.py currently prints: Server running at http://192.168.0.2:5000
  $flaskUrl = "http://192.168.0.2:5000/get";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $flaskUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch);
    $err = curl_errno($ch);
    $info = curl_getinfo($ch);
    // Release the curl handle (avoid deprecated curl_close in newer PHP versions)
    $ch = null;

    header('Content-Type: application/json');
    if ($err || $info['http_code'] !== 200 || !$res) {
        echo json_encode(["error" => "Failed to fetch from Flask", "code" => $info['http_code'] ?? 0]);
    } else {
        echo $res;
    }
    exit;
}

// Proxy POST endpoint to forward flow commands from browser to Flask PC server
if (isset($_GET['api']) && $_GET['api'] === 'send_flow' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  // Read raw JSON body
  $raw = file_get_contents('php://input');
  // Flask endpoint that accepts PC-originated messages
  $flaskUrl = "http://192.168.0.2:5000/send_from_pc";

  $ch = curl_init($flaskUrl);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  $res = curl_exec($ch);
  $err = curl_errno($ch);
  $info = curl_getinfo($ch);
  // Release the curl handle (avoid deprecated curl_close in newer PHP versions)
  $ch = null;

  header('Content-Type: application/json');
  if ($err || !isset($info['http_code']) || intval($info['http_code']) >= 400) {
    http_response_code(502);
    echo json_encode(["error" => "Failed to forward to Flask", "code" => $info['http_code'] ?? 0, "detail" => $res]);
  } else {
    // Return Flask response as-is
    echo $res;
  }
  exit;
}

// Provide a lightweight JSON endpoint to fetch new event_log rows so other clients
// can poll for new notifications and update their local UI (no extra table required)
if (isset($_GET['api']) && $_GET['api'] === 'logs') {
  // Returns rows with id, event_timestamp, event_desc, event_status
  $since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;
  $limit = 500;
  $stmt = $conn->prepare("SELECT id, event_timestamp, event_desc, event_status FROM event_log WHERE id > ? ORDER BY id DESC LIMIT ?");
  $stmt->bind_param('ii', $since_id, $limit);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
  }
  $stmt->close();
  header('Content-Type: application/json');
  echo json_encode(['rows' => $rows]);
  exit;
}

/* ─────────────────────────────────────────────────────────────────────────────
   AUTH GUARD
   ───────────────────────────────────────────────────────────────────────────── */
if (!isset($_SESSION["username"])) {
    header("Location: wavelogin.php");
    exit;
}

/* ─────────────────────────────────────────────────────────────────────────────
   TAB SELECTION
   ───────────────────────────────────────────────────────────────────────────── */
$current_tab = $_GET['tab'] ?? 'users';
$error = "";

/* ─────────────────────────────────────────────────────────────────────────────
   USERS TAB LOGIC (ADD / EDIT)
   ───────────────────────────────────────────────────────────────────────────── */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["username"], $_POST["accessLevel"], $_POST["email"]) && !isset($_POST["deleteUser"])) {
    $username = trim($_POST["username"]);
    $emailRaw = trim($_POST["email"]);
    $email    = strtolower($emailRaw);
    $password = trim($_POST["password"]);
    $access   = $_POST["accessLevel"] === "ADMIN" ? 2 : 1;
    $oldUser  = $_POST["oldUsername"] ?? "";
    $oldEmail = strtolower($_POST["oldEmail"] ?? "");

    // Allowed email domains
    $allowedDomains = ["gmail.com", "yahoo.com", "icloud.com", "outlook.com"];
    $domain = '';
    if (strpos($email, '@') !== false) {
        $domain = substr(strrchr($email, "@"), 1);
    }

    // ✅ Username validation
    if (strlen($username) < 4 || strlen($username) > 10) {
        $error = "Username must be 4–10 characters.";
    }
    // ✅ Email validation
    elseif (strlen($email) > 30 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($domain, $allowedDomains)) {
        $error = "Email must be valid, max 30 chars, and from Gmail, Yahoo, iCloud, or Outlook.";
    }
    // ✅ Password validation
    elseif (!empty($password) && (strlen($password) < 6 || strlen($password) > 12)) {
        $error = "Password must be 6–12 characters.";
    } else {
        // --- Edit ---
        if (!empty($oldUser)) {
            $check = $conn->prepare("SELECT 1 FROM auth_table WHERE BINARY MG_UName=? AND BINARY MG_UName<>?");
            $check->bind_param("ss", $username, $oldUser);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) $error = "Username already exists.";
            $check->close();

            if (!$error) {
                $check = $conn->prepare("SELECT 1 FROM auth_table WHERE LOWER(MG_Email)=? AND BINARY MG_UName<>?");
                $check->bind_param("ss", $email, $oldUser);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) $error = "Email already exists.";
                $check->close();
            }

            if (!$error) {
                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE auth_table 
                        SET MG_UName=?, MG_Email=?, MG_PWD=?, LAR_level=? 
                        WHERE BINARY MG_UName=?");
                    $stmt->bind_param("sssis", $username, $email, $hashedPassword, $access, $oldUser);
                } else {
                    $stmt = $conn->prepare("UPDATE auth_table 
                        SET MG_UName=?, MG_Email=?, LAR_level=? 
                        WHERE BINARY MG_UName=?");
                    $stmt->bind_param("ssis", $username, $email, $access, $oldUser);
                }
                $stmt->execute();
                $stmt->close();

                header("Location: ad_dashboard.php?tab=users");
                exit;
            }
        }
        // --- Add ---
        else {
            if (empty($password) || strlen($password) < 6 || strlen($password) > 12) {
                $error = "Password is required and must be 6–12 characters.";
            } else {
        // Enforce maximum 5 accounts: do not allow new user creation when limit reached
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM auth_table");
        if ($countStmt) {
          $countStmt->execute();
          $countStmt->bind_result($userCount);
          $countStmt->fetch();
          $countStmt->close();
          if (intval($userCount) >= 5) {
            $error = "User limit reached. Maximum 5 accounts allowed.";
          }
        } else {
          // If the count query fails for some reason, avoid silently allowing more users
          $error = "Unable to verify user limit. Please try again later.";
        }

                $check = $conn->prepare("SELECT 1 FROM auth_table WHERE BINARY MG_UName=? OR LOWER(MG_Email)=?");
                $check->bind_param("ss", $username, $email);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) $error = "Username or Email already exists.";
                $check->close();

                if (!$error) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO auth_table (MG_UName, MG_Email, MG_PWD, LAR_level) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("sssi", $username, $email, $hashedPassword, $access);
                    $stmt->execute();
                    $stmt->close();

                    header("Location: ad_dashboard.php?tab=users");
                    exit;
                }
            }
        }
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   USERS TAB LOGIC (DELETE)
   ───────────────────────────────────────────────────────────────────────────── */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["deleteUser"])) {
    $deleteUser = $_POST["deleteUser"];
    $stmt = $conn->prepare("DELETE FROM auth_table WHERE BINARY MG_UName=?");
    $stmt->bind_param("s", $deleteUser);
    $stmt->execute();
    $stmt->close();
    header("Location: ad_dashboard.php?tab=users");
    exit;
}

/* ─────────────────────────────────────────────────────────────────────────────
   LOAD USERS FOR TABLE
   ───────────────────────────────────────────────────────────────────────────── */
$result = $conn->query("SELECT MG_UName, MG_Email, LAR_level FROM auth_table");
$users = $result->fetch_all(MYSQLI_ASSOC);
$userCount = count($users);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Righteous&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" type="image/png" href="wave_logo2.png">
<link rel="stylesheet" href="ad_dashboard.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- System Tools extras (PDF export) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<style>
  .cat-info { background: #e3f2fd; }
  .cat-info td.category { color: #1976d2; font-weight: bold; }
  .cat-info td.category::before { background: #2196f3; }
/* Compact Monitoring Cards */
.water-grid { display: grid; grid-template-columns: 2fr 3fr; gap: 10px; margin-bottom: 6px; }
.big-card.sensor-card { min-height: 120px; font-size: 1.0rem; }
.right-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: repeat(3, 1fr); gap: 10px; }
.sensor-card { background: linear-gradient(145deg, #7ed6f7, #5faee3); border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); color: #222; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 40px; font-size: 0.95rem; padding: 8px 6px; }
.sensor-card.wide { grid-column: span 2; }
.sensor-card h3 { margin: 0 0 6px 0; font-size: 0.95rem; font-weight: 700; }
.sensor-card p { margin: 0; font-size: 1rem; font-weight: 700; }
html { font-size: 16px; }
html, body {
  height: 100vh;
  width: 100vw;
  overflow: hidden !important;
  margin: 0;
  padding: 0;
  font-family: 'Segoe UI', Arial, sans-serif;
}
.main-content, .users-container, .notifications-wrap, .tools-stage {
  max-width: 100vw;
  max-height: 100vh;
  overflow: hidden !important;
  box-sizing: border-box;
  background: #fff;
  border-radius: 0;
  box-shadow: none;
  color: #222;
}
.header {
  max-width: 100vw;
  max-height: 100vh;
  overflow: hidden !important;
  box-sizing: border-box;
  background: rgba(255,255,255,0.12);
  border-radius: 32px;
  box-shadow: 0 8px 32px rgba(30,81,98,0.18);
  backdrop-filter: blur(24px) saturate(180%) brightness(1.12);
  -webkit-backdrop-filter: blur(24px) saturate(180%) brightness(1.12);
  border: 1.5px solid rgba(255,255,255,0.18);
  color: #fff;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  height: 100px;
  display: flex;
  align-items: center;
}
.main-navigation {
  max-width: 100vw;
  max-height: 100vh;
  overflow: hidden !important;
  box-sizing: border-box;
  background: rgba(255,255,255,0.12);
  border-radius: 32px;
  box-shadow: 0 8px 32px rgba(30,81,98,0.18);
  backdrop-filter: blur(24px) saturate(180%) brightness(1.12);
  -webkit-backdrop-filter: blur(24px) saturate(180%) brightness(1.12);
  border: 1.5px solid rgba(255,255,255,0.18);
  color: #fff;
  position: fixed;
  top: 100px;
  left: 0;
  bottom: 0;
  width: 220px;
  z-index: 999;
}
.main-content {
  margin-top: 110px;
  margin-left: 230px;
  margin-bottom: 0;
  padding-bottom: 48px;
  /* rest of .main-content styles remain unchanged */
}
/* ──────────────────────────────────────────────────────────────────
   SMALL INLINE STYLES (kept from your previous version)
   ────────────────────────────────────────────────────────────────── */
.password-wrapper { position: relative; display: flex; align-items: center; width: 100%; }
.password-wrapper input { flex: 1; padding-right: 40px; }
.password-wrapper i { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 18px; cursor: pointer; color: #555; }
/* Hide default browser reveal icons */
input[type="password"]::-ms-reveal,
input[type="password"]::-ms-clear { display: none !important; }
input[type="password"]::-webkit-credentials-auto-fill-button { display: none !important; visibility: hidden !important; }
.error-msg { color: #e53935; font-size: 13px; margin-top: 6px; display: none; }
/* Live chart area (reduced height so axis labels like 0 remain visible) */
.chart-container { height: 240px; margin-top: 18px; padding-bottom: 8px; }
/* Optional: keep the separate "last updated" DOM element hidden since we draw it on chart */
#lastUpdatedLabel { display: none; }

/* ──────────────────────────────────────────────────────────────────
   System Tools — Combo Box (Sensors / System Actions) full-width views
   ────────────────────────────────────────────────────────────────── */
.tools-toolbar { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
.tools-toolbar label { font-weight:600; color:#1f3b4d; }
#toolsCombo { appearance:none; -webkit-appearance:none; -moz-appearance:none;
  background:#fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="%23222"><path d="M7 10l5 5 5-5z"/></svg>') no-repeat right 10px center/16px 16px;
  padding:10px 38px 10px 12px; border:1px solid #cbd5e1; border-radius:10px; font-weight:600; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.tools-stage { width:100%; min-height:70vh; background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.08); padding:16px; }

/* Cards grid (Sensors content) */
.st-grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); gap:20px; }
.st-card {
  background: linear-gradient(145deg, #2b6777, #1e5162);
  border-radius: 14px; padding: 12px; /* reduced padding for compact cards */
  box-shadow: 0 8px 18px rgba(0,0,0,0.3);
  text-align:center; color:#f1faff;
  transition: transform .2s ease, box-shadow .3s ease;
}
.st-card:hover { transform: translateY(-6px); box-shadow: 0 12px 25px rgba(0,150,200,0.5); }

/* 👉 Match sidebar icon style for cards’ icons */
.st-icon {
  display:inline-flex; align-items:center; justify-content:center;
  width:52px; height:52px; /* slightly smaller */
  border-radius:12px; position:relative;
  background: linear-gradient(180deg,#49d7ff,#1aa6ff);
  color:#fff; font-size:22px; box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 4px 10px rgba(0,0,0,.25);
  margin-bottom: 10px;
}

/* status dot */
.st-dot { display:inline-block; width:10px; height:10px; border-radius:50%; background:#bbb; vertical-align:middle; position: absolute; top:6px; right:6px; }
.st-on  { background:#06d6a0; box-shadow:0 0 12px #06d6a0; }
.st-off { background:#e63946; box-shadow:0 0 6px #e63946; }

/* iOS-style Toggle Switch (for sensors) */
.st-switch { position:relative; display:inline-block; width:64px; height:34px; }
.st-switch input { display:none; }
.st-slider { position:absolute; cursor:pointer; inset:0; background:#b0c4de; border-radius:34px; transition: background .4s ease; }
.st-slider::before { content:""; position:absolute; height:26px; width:26px; left:4px; bottom:3px; background:#fff; border-radius:50%;
  transition: transform .4s ease, background .4s ease; box-shadow: 0 2px 6px rgba(0,0,0,0.3); }
.st-switch input:checked + .st-slider { background: linear-gradient(135deg, #06d6a0, #1b9aaa); box-shadow: 0 0 12px rgba(0,200,150,0.7); }
.st-switch input:checked + .st-slider::before { transform: translateX(30px); background: #e0f7fa; box-shadow: 0 0 10px rgba(0,200,150,0.9); }

/* Buttons (System Actions) */
.st-btn { font-size:15px; padding:10px 18px; border:none; border-radius:10px; cursor:pointer; font-weight:700; color:#fff; margin:8px 8px 0 0; box-shadow:0 4px 12px rgba(0,0,0,.3); }
.st-diag    { background: linear-gradient(135deg, #00b4d8, #0096c7); }
.st-diag:hover { background: linear-gradient(135deg, #0096c7, #0077b6); }
.st-powerOn { background: linear-gradient(135deg, #20e6b1, #06d6a0); color: #fff; border-radius: 9999px; padding:10px 18px; box-shadow: 0 8px 28px rgba(6,214,160,0.18), 0 0 24px rgba(6,214,160,0.12); border: 1px solid rgba(255,255,255,0.12); }
.st-powerOn:hover { background: linear-gradient(135deg, #04ad84, #15807c); }
.st-powerOff{ background: linear-gradient(135deg, #ff6b6b, #e63946); color: #fff; border-radius: 9999px; padding:10px 18px; box-shadow: 0 8px 28px rgba(230,57,70,0.14), 0 0 18px rgba(230,57,70,0.10); border: 1px solid rgba(255,255,255,0.12); }
.st-powerOff:hover { background: linear-gradient(135deg, #d00000, #9d0208); }
.st-clear   { background: linear-gradient(135deg, #ffb703, #fb8500); }
.st-clear:hover { background: linear-gradient(135deg, #fb8500, #d97706); }
.st-export  { background: linear-gradient(135deg, #06d6a0, #1b9aaa); }
.st-export:hover { background: linear-gradient(135deg, #04ad84, #15807c); }

/* 🔵 NEW: Pill style that matches sidebar icon bubble */
.st-pill {
  background: linear-gradient(180deg,#49d7ff,#1aa6ff) !important;
  border-radius: 9999px !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 6px 14px rgba(0,0,0,.25) !important;
  color:#083344 !important; /* deep teal text contrast */
  border:1px solid rgba(255,255,255,.35);
}
.st-pill:hover { filter: brightness(0.95); }

/* Make action buttons (Edit/Delete) consistent */
.action-btn { display:inline-block; min-width:64px; height:36px; padding:6px 10px; border-radius:10px; font-weight:700; cursor:pointer; }
.edit-btn { background: linear-gradient(135deg,#45aaf2,#0fb9e6); color:#fff; border:none; box-shadow:0 4px 10px rgba(0,0,0,0.12); }
.delete-btn { background: linear-gradient(135deg,#00b4ff,#0177d2); color:#fff; border:none; box-shadow:0 4px 10px rgba(0,0,0,0.12); }
.action-btn:active { transform: translateY(1px); }

/* Vessel status pill (enhanced glowing pill to match new UI) */
#st-vesselStatus { font-weight: 700; margin-top: 10px; padding: 10px 18px; border-radius: 9999px; display: inline-block; font-size: 0.98rem; box-shadow: 0 6px 18px rgba(0,0,0,0.08); }
.st-vessel-on  {
  background: linear-gradient(135deg,#20e6b1,#06d6a0) !important;
  color:#ffffff !important;
  box-shadow: 0 8px 28px rgba(6,214,160,0.25), 0 0 28px rgba(6,214,160,0.18) !important;
  border: 1px solid rgba(255,255,255,0.15) !important;
}
.st-vessel-off {
  background: linear-gradient(135deg,#ff6b6b,#e63946) !important;
  color:#ffffff !important;
  box-shadow: 0 8px 28px rgba(230,57,70,0.18), 0 0 18px rgba(230,57,70,0.14) !important;
  border: 1px solid rgba(255,255,255,0.12) !important;
}

/* Logs UI (Notifications tab – full-width, no dropdown) */
.notifications-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); padding:16px; }
#st-logBox { background:#1e5162; color:#fff; padding:15px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.3); max-height:70vh; overflow-y:auto; font-family:monospace; }
.st-log-entry { margin:5px 0; padding:4px; border-bottom:1px solid #3a7a8c; }
.st-info { color:#4cc9f0; }
.st-warn { color:#ffb703; }
.st-alert { color:#ff6b6b; font-weight:bold; }

/* Small perf hint badge (optional) */
.badge-hint { display:inline-block; margin-left:8px; padding:4px 10px; font-size:0.85rem; color:#0f5132; background:#d1e7dd; border-radius:9999px; }
</style>
</style>
<style>
  /* Override: match user_dashboard pill/button sizing and spacing for pixel parity */
  /* Make action buttons slightly smaller and pill-shaped like user view */
  .st-btn {
    font-size: 14px !important;
    padding: 8px 14px !important;
    border-radius: 20px !important;
    height: 34px !important;
    min-height: 34px !important;
  }
  .st-pill {
    padding: 6px 12px !important;
    font-size: 0.88rem !important;
    border-radius: 20px !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 6px 14px rgba(0,0,0,.06) !important;
  }
  /* Vessel pill: smaller radius and matching padding */
  #st-vesselStatus {
    margin: 0 !important;
    padding: 8px 12px !important;
    border-radius: 20px !important;
    font-weight: 700 !important;
    font-size: 0.95rem !important;
  }
  /* Power button variants: keep gradients but tighten shadow and padding */
  .st-powerOn, .st-powerOff {
    padding: 8px 14px !important;
    border-radius: 20px !important;
    box-shadow: 0 6px 18px rgba(0,0,0,0.12) !important;
  }
  .st-powerOn { box-shadow: 0 6px 18px rgba(6,214,160,0.12) !important; }
  .st-powerOff{ box-shadow: 0 6px 18px rgba(230,57,70,0.12) !important; }
</style>

<!-- Quick overrides: hide sensor status dots and remove red glow behind power/vessel controls -->
<style>
  /* Show the small status dot on sensor cards (on/off visual indicator) */
  .st-dot { display: inline-block !important; position: absolute; width:12px; height:12px; border-radius:50%; right:12px; top:12px; }

  /* Remove the red glow/box-shadow behind the Shutdown/Power button and vessel status pill */
  .st-powerOff, .st-vessel-off, #st-powerBtn.st-powerOff, #st-vesselStatus.st-vessel-off {
    box-shadow: none !important;
  }

  /* Optional: make the power-off button less visually aggressive while keeping its color */
  #st-powerBtn.st-powerOff { border: 1px solid rgba(0,0,0,0.06) !important; }
</style>
</head>
<body>
<script>
// Force redirect to login if session is missing (prevents back navigation after logout)
if (window.performance && window.performance.navigation && window.performance.navigation.type === 2) {
  // If navigation is back/forward, reload to trigger PHP session check
  window.location.reload();
}
</script>
<!-- Loading Overlay -->
<style>
body {
  background: url('wavebg.jpeg') no-repeat center center fixed;
  background-size: cover;
}

body {
  background: url('wavebg.jpeg') no-repeat center center fixed;
  background-size: cover;
}
.main-content h1, .main-content h2, .main-content h3, .main-content h4, .main-content h5, .main-content h6 {
  color: #222 !important;
  text-shadow: none;
}
.header h1, .header h2, .header h3, .header h4, .header h5, .header h6,
.main-navigation h1, .main-navigation h2, .main-navigation h3, .main-navigation h4, .main-navigation h5, .main-navigation h6 {
  color: #fff !important;
  text-shadow: 0 2px 8px rgba(0,0,0,0.18);
}

.st-card {
  background: linear-gradient(145deg, #2b6777, #1e5162);
  border-radius: 20px;
  box-shadow: 0 8px 18px rgba(0,0,0,0.18);
  color: #f1faff;
  z-index: 2;
}
  .chart-container {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 8px 18px rgba(0,0,0,0.10);
  z-index: 2;
  height: 240px;
  margin-bottom: 0;
  padding-bottom: 0;
  overflow-y: visible;
  /* No glassmorphism for live chart */
}
</style>
<style>
@keyframes spinLogo {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
#loadingOverlay {
  position:fixed;
  top:0;
  left:0;
  width:100vw;
  height:100vh;
  background:transparent;
  z-index:9999;
  display:none;
  align-items:center;
  justify-content:center;
  flex-direction:column;
}
#loadingOverlay .loading-logo {
  width:90px;
  height:90px;
  margin-bottom:18px;
  animation: spinLogo 1s linear infinite;
}
#loadingOverlay .loading-text {
  font-size:2rem;color:#fff;font-family:'Righteous',cursive;
  text-shadow: 0 2px 8px rgba(0,0,0,0.18);
}
</style>
<div id="loadingOverlay">
  <img src="wave_logo2.png" alt="Logo" class="loading-logo">
  <div class="loading-text">Loading...</div>
</div>
<?php if (!empty($error)): ?>
<script>Swal.fire("Error", "<?php echo addslashes($error); ?>", "error");</script>
<?php endif; ?>

<!-- ───────────────────────────────
     HEADER
     ─────────────────────────────── -->
<div class="header">
  <div class="header-left">
    <img src="isu.png" alt="ISU Logo" height="65" width="65" class="isu-logo">
    <div class="system-title">ADMIN Dashboard</div>
    <div class="admin-title"><img src="wave_logo2.png" alt="WAVE Logo"></div>
  </div>
  <div class="header-right">
    <div class="admin-dropdown" onclick="toggleDropdown()">
      <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION["username"]); ?> ▾
      <div id="dropdownMenu" class="dropdown-content">
        <!-- JS-driven logout so we can update logs + localStorage before navigating -->
  <button type="button" id="logoutBtnOcean" class="logout-btn-ocean"><i class="fas fa-sign-out-alt"></i><span class="logout-text">Logout</span></button>
        <style>
        .logout-btn-ocean {
          height: 26px;
          padding: 0 10px 0 8px;
          border: none !important;
          border-radius: 14px;
          background: linear-gradient(135deg, #a7ffeb 0%, #40c4ff 60%, #00bcd4 100%);
          color: #01579b;
          font-weight: 700;
          font-size: 0.97em;
          text-align: center;
          cursor: pointer;
          box-shadow: none !important;
          display: flex;
          align-items: center;
          gap: 4px;
          transition: background 0.22s, color 0.22s, box-shadow 0.22s;
          width: fit-content;
          min-width: 0;
          max-width: 120px;
          margin: 8px auto 8px auto;
        }
        /* Override dropdown-content a button for logout only */
        #dropdownMenu {
          background: transparent !important;
          box-shadow: none !important;
          border-radius: 0 !important;
          display: flex !important;
          flex-direction: column !important;
          align-items: center !important;
          justify-content: flex-start !important;
          padding: 0 !important;
          min-width: unset !important;
        }
        #dropdownMenu {
          background: transparent !important;
          box-shadow: none !important;
          border-radius: 0 !important;
        }
        #dropdownMenu .logout-btn-ocean {
          width: fit-content !important;
          min-width: 0 !important;
          max-width: 120px !important;
          padding: 0 10px 0 8px !important;
          display: flex !important;
          justify-content: center !important;
          align-items: center !important;
          align-self: center !important;
          margin: 2px 0 0 0 !important;
          background: linear-gradient(135deg, #a7ffeb 0%, #40c4ff 60%, #00bcd4 100%) !important;
          box-shadow: 0 2px 8px 0 #40c4ff22 !important;
        }
        .logout-btn-ocean .logout-text {
          padding-right: 2px;
        }
        .logout-btn-ocean i {
          font-size: 1em;
          color: #00bcd4;
        }
        .logout-text {
          font-size: 0.98em;
          color: #01579b;
          font-weight: 600;
          margin-left: 2px;
          letter-spacing: 0.02em;
        }
        .logout-btn-ocean:hover {
          background: linear-gradient(135deg, #40c4ff 0%, #a7ffeb 100%);
          color: #fff;
          box-shadow: 0 4px 12px 0 #00bcd433;
        }
        .logout-btn-ocean:hover i,
        .logout-btn-ocean:hover .logout-text {
          color: #fff;
        }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
          var logoutBtn = document.getElementById('logoutBtnOcean');
          if (logoutBtn) {
            logoutBtn.addEventListener('click', function(e) {
              e.preventDefault();
              Swal.fire({
                title: 'Logout?',
                text: 'Are you sure you want to log out?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#00bcd4',
                cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Stay here'
              }).then((result) => {
                if (result.isConfirmed) {
                  performLogout();
                }
              });
            });
          }
        });
        </script>
      </div>
    </div>
  </div>
</div>

<!-- ───────────────────────────────
     LEFT NAVIGATION
     ─────────────────────────────── -->
<div class="main-navigation">
  <div class="nav-container">
    <button class="<?php echo ($current_tab === 'water') ? 'nav-item active' : 'nav-item'; ?>" data-tab="water"><span class="nav-icon"><i class="fas fa-water"></i></span> <span class="tab-label"><b>MONITORING</b></span> </button>
    <button class="<?php echo ($current_tab === 'notifications') ? 'nav-item active' : 'nav-item'; ?>" data-tab="notifications"><span class="nav-icon"><i class="fas fa-bell"></i></span> <span class="tab-label"><b>NOTIFICATION</b></span> </button>
    <button class="<?php echo ($current_tab === 'feedlogs') ? 'nav-item active' : 'nav-item'; ?>" data-tab="feedlogs"><span class="nav-icon"><i class="fas fa-fish"></i></span> <span class="tab-label"><b>FEEDER</b></span> </button>
  <a href="#" class="nav-item" id="controllerLink"><span class="nav-icon"><i class="fas fa-ship"></i></span> <span class="tab-label"><b>CONTROLLER</b></span> </a>
    <button class="<?php echo ($current_tab === 'users') ? 'nav-item active' : 'nav-item'; ?>" data-tab="users"><span class="nav-icon"><i class="fas fa-users"></i></span> <span class="tab-label"><b>USERS</b></span> </button>
    <button class="<?php echo ($current_tab === 'tools') ? 'nav-item active' : 'nav-item'; ?>" data-tab="tools"><span class="nav-icon"><i class="fas fa-anchor"></i></span> <span class="tab-label"><b>SYSTEM TOOLS</b></span> </button>
  </div>
</div>
<!-- ───────────────────────────────
     MAIN CONTENT
     ─────────────────────────────── -->
     <div class="main-content" id="mainContent">

<!-- ──────────────────────────
     MONITORING
     ────────────────────────── -->
<div id="waterSection" class="section" style="<?php echo ($current_tab === 'water') ? '' : 'display:none;'; ?>"> 
  <div class="water-quality-section"> 
    <h2> <span id="perfHint" class="badge-hint" style="display:none;">live updates paused</span></h2> 
    <div class="water-grid"> 
      <div class="big-card sensor-card" onclick="switchChart('WQI')"> 
        <h3>Water Quality Index</h3> 
        <p id="wqiValue" name="wqi_value">--</p>
        <small id="wqi_status" style="display:block;margin-top:6px;font-size:0.85rem;color:#0f3b4a;opacity:0.95;">&nbsp;</small>
      </div> 
      <div class="right-grid"> 
        <div class="sensor-card" onclick="switchChart('DO')"><h3>Dissolved Oxygen (mg/L)</h3><p id="do">--</p>
          <small id="do_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#0f3b4a;opacity:0.9;">&nbsp;</small>
        </div> 
        <div class="sensor-card" onclick="switchChart('TURB')"><h3>Turbidity (NTU)</h3><p id="turbidity">--</p>
          <small id="turbidity_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#0f3b4a;opacity:0.9;">&nbsp;</small>
        </div> 
        <div class="sensor-card" onclick="switchChart('AMMO')"><h3>Ammonia (ppm)</h3>
          <p id="ammonia">--</p>
          <small id="ammonia_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#0f3b4a;opacity:0.9;">&nbsp;</small>
        </div>
        <div class="sensor-card" onclick="switchChart('PH')"><h3>pH Level</h3><p id="ph_level">--</p>
          <small id="ph_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#0f3b4a;opacity:0.9;">&nbsp;</small>
        </div> 
        <div class="sensor-card wide" onclick="switchChart('TEMP')"><h3>Water Temperature (°C)</h3><p id="temperature">--</p>
          <small id="temperature_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#0f3b4a;opacity:0.9;">&nbsp;</small>
        </div>
      </div> 
    </div> 

    <div class="card wide chart-container">
      <h3 id="chartTitle">WQI Live Chart</h3>
  <canvas id="liveChart" height="220" style="width:100%;max-width:100%;display:block;"></canvas>
	</div>

    <div style="margin-top:10px;color:#555;font-size:0.95rem;">
      <span id="lastUpdatedLabel">Last updated: <em id="lastUpdatedValue">--</em></span>
    </div>
  </div> 
</div> 

<!-- ──────────────────────────
     USERS
     ────────────────────────── -->
<div id="usersSection" class="section" style="<?php echo ($current_tab === 'users') ? '' : 'display:none;'; ?>">
  <div class="users-container">
    <div class="users-left">
      <h3>Manage Users</h3>
      <form method="POST" id="userForm" autocomplete="off">
        <input type="hidden" name="active_tab" value="users">
        <input type="hidden" name="oldUsername" id="oldUsername">
        <input type="hidden" name="oldEmail" id="oldEmail">

        <input type="text" name="username" id="username" placeholder="Username (4–10 characters only)" required minlength="4" maxlength="10">
        <div id="userError" class="error-msg">Username already exists.</div>

        <input type="email" name="email" id="email" placeholder="Email Address" required maxlength="30">
        <div id="emailError" class="error-msg">Email invalid, duplicate, or not allowed domain.</div>

        <div class="password-wrapper">
          <input type="password" name="password" id="password" placeholder="Password (6–12 characters only)" minlength="6" maxlength="12" autocomplete="new-password">
          <i class="fas fa-eye" onclick="togglePassword('password', this)"></i>
        </div>
        <div class="password-wrapper">
          <input type="password" name="confirmPassword" id="confirmPassword" placeholder="Confirm Password" minlength="6" maxlength="12" autocomplete="new-password">
          <i class="fas fa-eye" onclick="togglePassword('confirmPassword', this)"></i>
        </div>

        <select name="accessLevel" id="accessLevel" required>
          <option value="">Select Access</option>
          <option value="USER">USER</option>
          <option value="ADMIN">ADMIN</option>
        </select>
        <button type="submit" id="formButton">Add User</button>
      </form>
    </div>
    <div class="users-right">
      <h3>Existing Users</h3>
  <table>
        <thead><tr><th>Users</th><th>Email</th><th>Password</th><th>Access</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td><?php echo htmlspecialchars($user["MG_UName"]); ?></td>
            <td><?php echo htmlspecialchars($user["MG_Email"]); ?></td>
            <td>******</td>
            <td><?php echo $user["LAR_level"] == 2 ? "ADMIN" : "USER"; ?></td>
            <td>
              <button type="button" class="action-btn edit-btn"
                onclick="editUser('<?php echo addslashes($user['MG_UName']); ?>','<?php echo htmlspecialchars($user['MG_Email'], ENT_QUOTES); ?>','<?php echo intval($user['LAR_level']); ?>')">Edit</button>
              <button type="button" class="action-btn delete-btn" onclick="confirmDelete('<?php echo htmlspecialchars($user['MG_UName'], ENT_QUOTES); ?>')">Delete</button>

<script>
// ========== Confirm Delete User ========== //
function confirmDelete(username) {
  Swal.fire({
    title: 'Delete User?',
    text: `Are you sure you want to delete user "${username}"? This action cannot be undone!`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e63946',
    cancelButtonColor: '#aaa',
    confirmButtonText: 'Yes, delete',
    focusCancel: true
  }).then((result) => {
    if (result.isConfirmed) {
      // Log INFO event for user deletion
      ST_addLog("info", `[ADMIN] ${username} was deleted from the system.`);
      // Create and submit a form for deletion
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '';
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'deleteUser';
      input.value = username;
      form.appendChild(input);
      document.body.appendChild(form);
      form.submit();
    }
  });
}
</script>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ──────────────────────────
     FEED LOGS
     ────────────────────────── -->
  <div id="feedlogsSection" class="section" style="<?php echo ($current_tab === 'feedlogs') ? '' : 'display:none;'; ?>">
    <iframe src="feeder.php?from=admin" style="width:100%;height:80vh;border:none;"></iframe>
</div>

<!-- ──────────────────────────
     NOTIFICATIONS (FULL-WIDTH LOGS – no dropdown)
     ────────────────────────── -->
<div id="notificationsSection" class="section" style="<?php echo ($current_tab === 'notifications') ? '' : 'display:none;'; ?>">
  <div class="notifications-wrap">
    <div class="tool-actions" style="margin-bottom:10px; display:flex; gap:10px; align-items:center;">
  <input id="notifSearch" type="text" placeholder="Search logs..." class="notif-searchbar" oninput="filterNotificationLogs()">
  <select id="notifCategoryFilter" class="notif-category-filter" onchange="filterNotificationLogs()">
<style>
  .notif-searchbar {
    padding: 8px 14px;
    border-radius: 8px;
    border: 1.5px solid #d0d7de;
    min-width: 180px;
    font-size: 1rem;
    background: #f8fafc;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
    margin-right: 10px;
    box-shadow: 0 1px 4px #1e516208;
  }
  .notif-searchbar:focus {
    border-color: #1e5162;
    background: #fff;
    box-shadow: 0 2px 8px #1e516222;
  }
  .notif-category-filter {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid #d0d7de;
    font-size: 1rem;
    background: #f8fafc;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
    margin-right: 10px;
    box-shadow: 0 1px 4px #1e516208;
    cursor: pointer;
  }
  .notif-category-filter:focus {
    border-color: #1e5162;
    background: #fff;
    box-shadow: 0 2px 8px #1e516222;
  }
</style>
  <option value="all">All Events</option>
  <option value="action">Action</option>
  <option value="alert">Alert</option>
  <option value="info">Info</option>
  <option value="access">Access</option>
  <option value="alarm">Alarm</option>
      </select>
      <button class="st-btn st-export st-pill" onclick="openExportPdfModal()">Export PDF</button>
<!-- Export PDF Modal -->
<style>
  #exportPdfModal {
    display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100vw; height: 100vh;
    background: rgba(0,0,0,0.25); align-items: center; justify-content: center;
  }
  #exportPdfModal.active { display: flex; }
  #exportPdfModal .modal-content {
    background: #fff; padding: 2.2rem 2.2rem 1.5rem 2.2rem; border-radius: 16px; min-width: 320px; max-width: 95vw;
    box-shadow: 0 4px 32px #0002; font-family: 'Segoe UI', Arial, sans-serif;
    animation: modalPop .18s cubic-bezier(.4,1.6,.6,1) 1;
  }
  @keyframes modalPop {
    0% { transform: scale(0.95); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
  }
  #exportPdfModal h3 {
    margin-top: 0; margin-bottom: 1.2rem; color: #1e5162; font-size: 1.35rem; font-weight: 700;
  }
  #exportPdfModal label {
    font-weight: 600; color: #1e5162; margin-right: 8px; font-size: 1rem;
  }
  #exportPdfModal select, #exportPdfModal input[type="datetime-local"] {
    padding: 8px 12px; border-radius: 7px; border: 1px solid #d0d7de; font-size: 1rem;
    margin-bottom: 0.2em; margin-top: 0.2em;
  }
  #exportPdfModal .modal-row {
    display: flex; align-items: center; margin-bottom: 1.1rem;
  }
  #exportPdfModal .modal-row label { min-width: 60px; }
  #exportPdfModal .modal-actions {
    text-align: right; margin-top: 0.5em;
  }
  #modalExportCancel {
    margin-right: 1em; background: #fff; color: #1e5162; border: 1px solid #1e5162;
    padding: 0.5em 1.2em; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background .15s;
  }
  #modalExportCancel:hover { background: #f0f4f8; }
  #modalExportConfirm {
    background: #1e5162; color: #fff; border: none; padding: 0.5em 1.2em; border-radius: 6px;
    font-weight: 600; cursor: pointer; box-shadow: 0 2px 8px #1e516222;
    transition: background .15s;
  }
  #modalExportConfirm:hover { background: #1976d2; }
</style>
<div id="exportPdfModal">
  <div class="modal-content">
    <h3>Export Notification Logs</h3>
    <div class="modal-row">
      <label for="modalExportCategory">Event:</label>
      <select id="modalExportCategory">
  <option value="all">All</option>
  <option value="action">Action</option>
  <option value="alert">Alert</option>
  <option value="info">Info</option>
  <option value="access">Access</option>
  <option value="alarm">Alarm</option>
      </select>
    </div>
    <div class="modal-row">
      <label for="modalExportFrom">From:</label>
      <input type="datetime-local" id="modalExportFrom">
    </div>
    <div class="modal-row">
      <label for="modalExportTo">To:</label>
      <input type="datetime-local" id="modalExportTo">
    </div>
    <div class="modal-actions">
      <button id="modalExportCancel">Cancel</button>
      <button id="modalExportConfirm">Export PDF</button>
    </div>
  </div>
</div>
<script>
function openExportPdfModal() {
  document.getElementById('exportPdfModal').classList.add('active');
}
document.getElementById('modalExportCancel').onclick = function() {
  document.getElementById('exportPdfModal').classList.remove('active');
}
document.getElementById('modalExportConfirm').onclick = function() {
  // Get filter values from modal
  let cat = document.getElementById('modalExportCategory').value || 'all';
  // Force 'access' to lowercase for export logic
  if (cat.toLowerCase() === 'access') cat = 'access';
  const from = document.getElementById('modalExportFrom').value;
  const to = document.getElementById('modalExportTo').value;
  document.getElementById('exportPdfModal').classList.remove('active');
  ST_exportLogsPDF(cat, from, to);
}
// Live filter for notification logs
function filterNotificationLogs() {
  const search = document.getElementById('notifSearch').value.toLowerCase();
  let cat = document.getElementById('notifCategoryFilter').value;
  const table = document.getElementById('st-logTable');
  if (!table) return;
  const rows = table.getElementsByTagName('tr');
  for (let i = 1; i < rows.length; i++) { // skip header
    const cells = rows[i].getElementsByTagName('td');
    if (cells.length < 3) continue;
    const msg = cells[1].textContent.toLowerCase();
  let event = cells[2].textContent.toLowerCase();
  // Treat 'login' as 'access' for filtering
  if (event === 'login') event = 'access';
  const catMatch = (cat === 'all') || (event === cat);
    const searchMatch = !search || msg.includes(search) || event.includes(search) || cells[0].textContent.toLowerCase().includes(search);
    rows[i].style.display = (catMatch && searchMatch) ? '' : 'none';
  }
}
</script>
    </div>
    <div style="overflow-x:auto;">
      <style>
        #st-logTable {
          width: 100%;
          max-width: 900px;
          margin: 0 auto;
          border-collapse: separate;
          border-spacing: 0;
          table-layout: fixed;
          background: #fff;
          border-radius: 14px;
          box-shadow: 0 4px 24px rgba(30,81,98,0.13);
          overflow: hidden;
          font-family: 'Segoe UI', Arial, sans-serif;
        }
        #st-logTable th, #st-logTable td {
          padding: 14px 18px;
          text-align: left;
          font-size: 1.08rem;
        }
        #st-logTable th {
          background: #1e5162;
          color: #fff;
          border-bottom: 3px solid #1976d2;
          font-weight: 800;
          letter-spacing: 0.5px;
        }
        #st-logTable tbody tr {
          transition: background 0.2s;
        }
        #st-logTable tbody tr:hover {
          background: #f0f4f8;
        }
  .cat-action { background: #f6fff7; }
  .cat-alert { background: #fff7e6; }
  .cat-info { background: #fffde7; }
  .cat-alarm { background: #fff0f0; }
  .cat-access { background: #e3f2fd; }
  .cat-action td.category { color: #219150; font-weight: bold; }
  .cat-alert td.category { color: #e65100; font-weight: bold; }
  .cat-info td.category { color: #fbc02d; font-weight: bold; }
  .cat-alarm td.category { color: #b71c1c; font-weight: bold; }
  .cat-access td.category { color: #1976d2; font-weight: bold; }
        #st-logTable td {
          border-bottom: 1px solid #e3e8ee;
          word-break: break-word;
          color: #222;
        }
        #st-logTable td.timestamp {
          font-family: 'Fira Mono', 'Consolas', monospace;
          white-space: nowrap;
          width: 170px;
          color: #1976d2;
          font-weight: 600;
        }
        #st-logTable td.message {
          width: 60%;
          color: #222;
        }
        #st-logTable td.category {
          width: 120px;
          text-align: center;
          border-radius: 16px;
          background: none;
          font-size: 1.02rem;
          letter-spacing: 0.2px;
          padding: 10px 0;
        }
        #st-logTable td.category::before {
          content: '';
          display: inline-block;
          width: 10px;
          height: 10px;
          border-radius: 50%;
          margin-right: 8px;
          vertical-align: middle;
        }
  .cat-action td.category::before { background: #43a047; }
  .cat-alert td.category::before { background: #ff9800; }
  .cat-info td.category::before { background: #fbc02d; }
  .cat-alarm td.category::before { background: #e53935; }
  .cat-access td.category::before { background: #2196f3; }
      </style>
      <div style="max-height:420px; overflow-y:auto; border-radius:14px; box-shadow:0 2px 12px rgba(30,81,98,0.04); background:#fff;">
        <table id="st-logTable" style="margin-bottom:0; width:100%;">
          <thead>
            <tr style="background:#1e5162; color:#fff;">
              <th style="text-align:center; padding:12px 0; font-size:1.1em; letter-spacing:0.5px;">Timestamp</th>
              <th style="text-align:center; padding:12px 0; font-size:1.1em; letter-spacing:0.5px;">Log</th>
              <th style="text-align:center; padding:12px 0; font-size:1.1em; letter-spacing:0.5px;">Event</th>
            </tr>
          </thead>
          <tbody id="st-logBox" style="background:#f8fafc; font-size:1.05em;"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ──────────────────────────
     TOOLS  (SENSORS & SYSTEM ACTIONS AS COMBO BOX)
     ────────────────────────── -->
<div id="toolsSection" class="section" style="<?php echo ($current_tab === 'tools') ? 'position:relative;' : 'display:none; position:relative;'; ?>">
  <h2></h2>

  <!-- Top-left Combo Box -->
  <div class="tools-toolbar">
    <!-- Dropdown removed - sensors and actions are unified into a single view -->
  </div>

  <!-- Full-width stage: one view at a time -->
  <div id="toolsStageSensors" class="tools-stage" style="display:block;">
    <!-- Header row for actions (keeps buttons separate and avoids overlap) -->
    <div class="tools-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px;">
      <div class="tools-header-left">
        <!-- left area: reserved for future controls/filters -->
      </div>
      <div class="tools-header-right" style="display:flex; gap:10px; align-items:center;">
  <p id="st-vesselStatus" class="st-vessel-on" style="margin:0; padding:8px 12px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,0.08);">Vessel Status: ON</p>
  <button id="runFaBtn" class="st-btn st-export st-pill" title="Run FA Code Generator" onclick="runFA()">Generate 2FA Code</button>
  <button id="runNetBtn" class="st-btn st-export st-pill" title="Run NetDiag as Administrator" onclick="runNetDiag()">Run Diagnostics</button>
  <button id="st-powerBtn" class="st-btn st-powerOff st-pill" onclick="ST_togglePower()">Shutdown Vessel</button>
      </div>
    </div>

    <style>
      /* Tools header: keep actions in-flow to avoid overlapping sensor grid. */
      #toolsSection .tools-header { width:100%; box-sizing:border-box; }
      @media (max-width: 900px) {
        #toolsSection .tools-header { flex-direction:column; align-items:stretch; gap:8px; }
        #toolsSection .tools-header-right { justify-content:flex-end; }
        #toolsStageSensors { padding-top: 6px; }
      }
      @media (min-width: 901px) {
        #toolsSection .tools-header { flex-direction:row; }
        #toolsStageSensors { padding-top: 6px; }
      }
    </style>
    <div class="st-grid">
      <!-- Sensor cards: PH, Turbidity, Temperature, Dissolved Oxygen, Loadcells, Ultrasonic -->
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-vial"></i></div>
        <h4>PH LEVEL</h4>
        <div class="st-switch">
          <input type="checkbox" id="st-sw-ph" onchange="ST_toggleSensor(this,'ph')">
          <label for="st-sw-ph" class="st-slider"></label>
        </div>
      </div>

      <div class="st-card">
        <div class="st-icon"><i class="fas fa-tint"></i></div>
        <h4>TURBIDITY</h4>
        <div class="st-switch">
          <input type="checkbox" id="st-sw-turb" onchange="ST_toggleSensor(this,'turb')">
          <label for="st-sw-turb" class="st-slider"></label>
        </div>
      </div>

      <div class="st-card">
        <div class="st-icon"><i class="fas fa-thermometer-half"></i></div>
        <h4>TEMPERATURE</h4>
        <div class="st-switch">
          <input type="checkbox" id="st-sw-temp" onchange="ST_toggleSensor(this,'temp')">
          <label for="st-sw-temp" class="st-slider"></label>
        </div>
      </div>

      <div class="st-card">
        <div class="st-icon"><i class="fas fa-wind"></i></div>
        <h4>DISSOLVED OXYGEN</h4>
        <div class="st-switch">
          <input type="checkbox" id="st-sw-do" onchange="ST_toggleSensor(this,'do')">
          <label for="st-sw-do" class="st-slider"></span></label>
        </div>
      </div>

      <div class="st-card">
        <div class="st-icon"><i class="fas fa-balance-scale"></i></div>
        <h4>LOADCELL 1</h4>
        <div class="st-switch">
          <input type="checkbox" id="st-sw-load1" onchange="ST_toggleSensor(this,'load1')">
          <label for="st-sw-load1" class="st-slider"></label>
        </div>
      </div>

      <div class="st-card">
        <div class="st-icon"><i class="fas fa-balance-scale"></i></div>
        <h4>LOADCELL 2</h4>
        <div class="st-switch">
          <input type="checkbox" id="st-sw-load2" onchange="ST_toggleSensor(this,'load2')">
          <label for="st-sw-load2" class="st-slider"></label>
        </div>
      </div>

      <div class="st-card">
        <div class="st-icon"><i class="fas fa-broadcast-tower"></i></div>
        <h4>FEED LEVEL (ULTRASONIC)</h4>
        <div class="st-switch">
          <input type="checkbox" id="st-sw-ultra" onchange="ST_toggleSensor(this,'ultra')">
          <label for="st-sw-ultra" class="st-slider"></label>
        </div>
      </div>
    </div>
  </div>
    <hr style="margin:20px 0; border:1px solid #e5e7eb;">
  </div>
</div> <!-- /main-content -->
<!-- ───────────────────────────────
     SCRIPTS
     ─────────────────────────────── -->
     <script>
/* ========== Header Dropdown ========== */
function toggleDropdown(){ document.getElementById('dropdownMenu').classList.toggle('dropdown-show'); }

/* ========== Existing users/emails for validation ========== */
const existingUsers  = <?php echo json_encode(array_column($users,"MG_UName")); ?>;
const existingEmails = <?php echo json_encode(array_map("strtolower",array_column($users,"MG_Email"))); ?>;
const existingUserCount = <?php echo intval($userCount); ?>;
const allowedDomains = ["gmail.com","yahoo.com","icloud.com","outlook.com"];

/* ========== Inline validation: username dupes ========== */
document.getElementById("username").addEventListener("input", function(){
  const user = this.value.trim();
  const oldUser = document.getElementById("oldUsername").value;
  document.getElementById("userError").style.display = (user && existingUsers.includes(user) && user !== oldUser) ? "block" : "none";
});

// Disable Add User button if user limit reached (but allow form in Edit mode)
function updateAddButtonState() {
  const btn = document.getElementById('formButton');
  const oldUser = document.getElementById('oldUsername').value || '';
  if (existingUserCount >= 5 && oldUser === '') {
    btn.disabled = true;
    btn.textContent = 'User Limit Reached';
    btn.style.opacity = '0.65';
    btn.style.cursor = 'not-allowed';
  } else {
    btn.disabled = false;
    btn.textContent = (oldUser === '') ? 'Add User' : 'Save Changes';
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';
  }
}

// Run on load and when switching to edit mode
document.addEventListener('DOMContentLoaded', function(){
  updateAddButtonState();
});

// When editUser populates the form, update button state accordingly
const originalEditUser = window.editUser;
if (typeof originalEditUser === 'function') {
  window.editUser = function(username, email, accessLevel) {
    originalEditUser(username, email, accessLevel);
    // Now allow saving even if user limit reached
    updateAddButtonState();
  }
}

/* ========== Inline validation: email format/domain/dupes ========== */
document.getElementById("email").addEventListener("input", function(){
  const email = this.value.trim().toLowerCase();
  const oldEmail = (document.getElementById("oldEmail").value || "").toLowerCase();
  let bad = false;
  if (email.length > 30) bad = true;
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) bad = true;
  else {
    const domain = email.split("@")[1];
    if (!allowedDomains.includes(domain)) bad = true;
    if (existingEmails.includes(email) && email !== oldEmail) bad = true;
  }
  document.getElementById("emailError").style.display = bad ? "block" : "none";
});

/* ========== Password reveal toggles ========== */
function togglePassword(fieldId, icon) {
  const field = document.getElementById(fieldId);
  if (field.type === "password") { field.type = "text"; icon.classList.replace("fa-eye","fa-eye-slash"); }
  else { field.type = "password"; icon.classList.replace("fa-eye-slash","fa-eye"); }
}

/* ========== Populate edit form ========== */
function editUser(username, email, accessLevel){
  document.getElementById('oldUsername').value = username;
  document.getElementById('oldEmail').value = (email || "").toLowerCase();
  document.getElementById('username').value = username;
  document.getElementById('email').value = email;
  document.getElementById('password').value = "";
  document.getElementById('confirmPassword').value = "";
  document.getElementById('accessLevel').value = (parseInt(accessLevel,10) === 2 ? "ADMIN" : "USER");
  document.getElementById('formButton').textContent = "Save Changes";
  document.getElementById("userError").style.display = "none";
  document.getElementById("emailError").style.display = "none";
  navSwitchTo('users');
}

/* ========== Validate & submit user form ========== */
document.getElementById("userForm").addEventListener("submit", function(e){
  e.preventDefault();
  const oldUser  = document.getElementById("oldUsername").value;
  const oldEmail = (document.getElementById("oldEmail").value || "").toLowerCase();
  const user = document.getElementById("username").value.trim();
  const email = document.getElementById("email").value.trim().toLowerCase();
  const pwd = document.getElementById("password").value.trim();
  const cpwd = document.getElementById("confirmPassword").value.trim();

  if (user.length < 4 || user.length > 10) { Swal.fire("Error","Username must be 4–10 characters!","error"); return; }
  if (existingUsers.includes(user) && user !== oldUser) { Swal.fire("Error","Username already exists!","error"); return; }
  if (email.length > 30 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { Swal.fire("Error","Please enter a valid email address!","error"); return; }
  const domain = email.split("@")[1]; if (!allowedDomains.includes(domain)) { Swal.fire("Error","Email must be Gmail, Yahoo, iCloud, or Outlook.","error"); return; }
  if (existingEmails.includes(email) && email !== oldEmail) { Swal.fire("Error","Email already exists!","error"); return; }
  if (oldUser === "" && pwd === "") { Swal.fire("Error","Password is required for new users!","error"); return; }
  if (pwd !== "" && (pwd.length < 6 || pwd.length > 12)) { Swal.fire("Error","Password must be 6–12 characters!","error"); return; }
  if (pwd !== cpwd) { Swal.fire("Error","Passwords do not match!","error"); return; }

  // Log INFO event for new user creation
  if (oldUser === "") {
    ST_addLog("info", `[ADMIN] ${user} was added to the system.`);
  } else {
    if (user !== oldUser) {
      ST_addLog("info", `[ADMIN] ${oldUser} updated username to (${user})`);
    }
    if (email !== oldEmail) {
      ST_addLog("info", `[ADMIN] ${oldUser} updated email to (${email})`);
    }
    if (pwd !== "") {
      ST_addLog("info", `[ADMIN] ${oldUser} updated password`);
    }
  }
  this.submit();
});

/* ──────────────────────────────────────────────────────────────────
   NAV + PERFORMANCE: start/stop Monitoring polling per tab,
   pause on background, and clear timers on navigation away.
   ────────────────────────────────────────────────────────────────── */
const navButtons = document.querySelectorAll(".nav-item[data-tab]");
let pollTimer = null;
let chartReady = false;
let chartBackgroundTimer = null;

// Background poller: when Monitoring UI is not active we still poll lightly
// and append ticks to the persisted chart history so the chart doesn't reset
// when the user navigates away or refreshes the page.
async function chartBackgroundTick() {
  try {
    const wrapper = await robustFetchJson();
    if (wrapper && typeof wrapper.message === 'object') {
      const s = wrapper.message;
      const turbRaw = s.TURB ?? s.turb ?? s.TURBIDITY ?? s.turbidity ?? s.NTU_VALUE ?? s.ntu_value;
      const tempRaw = s.TEMP ?? s.temp ?? s.TEMP_C ?? s.temp_c ?? s.IMU_TEMP_C ?? s.imu_temp_c;
      const ammoRaw = s.AMMO ?? s.ammo ?? s.NH3_PPM ?? s.NH3_PPM_VALUE ?? s.nh3_ppm ?? s.NH3_PPM_VALUE;
      const data = {
        WQI:  safeNumberOrNull(s.WQI  ?? s.wqi),
        PH:   safeNumberOrNull(s.PH   ?? s.pH ?? s.PH_VAL ?? s.PH_LEVEL),
        TURB: safeNumberOrNull(turbRaw ?? s.TURB ?? s.turb),
        TEMP: safeNumberOrNull(tempRaw ?? s.TEMP ?? s.temp),
        AMMO: safeNumberOrNull(ammoRaw ?? s.AMMO ?? s.ammo),
        DO:   safeNumberOrNull(s.DO   ?? s.do ?? s.DO_MGL ?? s.DO_MG_L ?? s.DO_MG)
      };
      appendChartTick(data);
      // persist a minimal lastUpdated so restore shows recency
      try { const store = loadChartHistory() || {}; store.lastUpdated = s.last_updated || s.updated || Date.now(); saveChartHistory(store); } catch(e){}
    }
  } catch (e) { /* ignore background poll errors */ }
}
function startBackgroundChartPoll() {
  if (chartBackgroundTimer) return;
  // Run immediately then every 2s
  chartBackgroundTick();
  chartBackgroundTimer = setInterval(()=>{ chartBackgroundTick().catch(()=>{}); }, 2000);
}
function stopBackgroundChartPoll() {
  if (!chartBackgroundTimer) return;
  clearInterval(chartBackgroundTimer);
  chartBackgroundTimer = null;
}

function startMonitoring() {
  if (pollTimer) return;
  // When the full monitoring UI is active, stop the lightweight background poller
  stopBackgroundChartPoll();
  const hint = document.getElementById('perfHint'); if (hint) hint.style.display = 'none';
  // Restore persisted chart history into the live chart so it shows recent ticks collected while away
  try { restoreChartToLive(); } catch(e) {}
  fetchData(); // immediate hit
  pollTimer = setInterval(fetchData, 2000);
}
function stopMonitoring() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
    const hint = document.getElementById('perfHint');
    if (hint) hint.style.display = 'inline-block';
  }
  // When the monitoring UI is not active, keep collecting chart ticks in background
  startBackgroundChartPoll();
}

function navSwitchTo(tab){
  // Show loading overlay
  const overlay = document.getElementById('loadingOverlay');
  if (overlay) {
    overlay.style.display = 'flex';
    setTimeout(()=>{
      overlay.style.display = 'none';
      // Switch tab content after overlay
      const sections=document.querySelectorAll(".section");
      sections.forEach(s=>s.style.display="none");
      navButtons.forEach(b=>b.classList.remove("active"));
      const btn=[...navButtons].find(b=>b.dataset.tab===tab);
      if(btn) btn.classList.add("active");
      const sec=document.getElementById(tab+"Section");
      if(sec) sec.style.display="block";

      // Perf: only poll when Monitoring tab visible
      if (tab === 'water') startMonitoring(); else stopMonitoring();

      // Persist tab in URL (no reload)
      const url=new URL(window.location);
      url.searchParams.set('tab',tab);
      window.history.replaceState({},'',url);
    }, 700); // 700ms delay for loading effect
  }
}

navButtons.forEach(btn=>{
  btn.addEventListener("click", ()=>{
    const tab=btn.dataset.tab;
    navSwitchTo(tab);
  });
});

// Pause/resume polling when tab not visible (browser/tab change)
document.addEventListener('visibilitychange', ()=>{
  if (document.hidden) stopMonitoring();
  else {
    const active = document.querySelector(".nav-item.active")?.dataset.tab;
    if (active === 'water') startMonitoring();
  }
});

// Before leaving (e.g., to Controller), clear timers
window.addEventListener('beforeunload', ()=>{ stopMonitoring(); });

// Controller link: proactively stop timers to avoid lag on navigation
document.getElementById('controllerLink')?.addEventListener('click', ()=>{ stopMonitoring(); });

/* Initial tab render + monitoring */
navSwitchTo("<?php echo $current_tab; ?>");

/* ──────────────────────────────────────────────────────────────────
   MONITORING — Chart config + plugins (init when needed)
   ────────────────────────────────────────────────────────────────── */
const sensorConfig = {
  WQI:  { label: "WQI",         color: "green",  max: 100 },
  PH:   { label: "pH",          color: "teal",   max: 14  },
  TURB: { label: "Turbidity",   color: "orange", max: 230 },
  TEMP: { label: "Temperature", color: "red",    max: 50  },
  AMMO: { label: "Ammonia",     color: "purple", max: 1   },
  DO:   { label: "DO",          color: "blue",   max:     15  }
};

let activeSensor = "WQI";
let liveChart;
const maxPoints = 60;

const lastValueLabelPlugin = {
  id: 'lastValueLabel',
  afterDatasetsDraw(chart) {
    try {
      const { ctx } = chart;
      // Find the active dataset by matching label to activeSensor config
      const activeLabel = sensorConfig[activeSensor].label;
      const dsIndex = chart.data.datasets.findIndex(d => d.label === activeLabel);
      if (dsIndex < 0) return;
      const dsMeta = chart.getDatasetMeta(dsIndex);
      const ds = chart.data.datasets[dsIndex];
      if (!ds || ds.data.length === 0) return;
      const lastIndex = ds.data.length - 1;
      const lastPoint = dsMeta.data[lastIndex];
      if (!lastPoint) return;
      const value = ds.data[lastIndex];
      ctx.save();
      ctx.font = '12px Segoe UI, sans-serif';
      ctx.fillStyle = '#333';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'bottom';
      ctx.fillText(String(value), lastPoint.x + 6, lastPoint.y - 6);
      ctx.restore();
    } catch (e) { /* ignore plugin errors */ }
  }
};

const lastUpdatedPlugin = {
  id: 'lastUpdatedPlugin',
  afterDraw(chart) {
    try {
      const ctx = chart.ctx;
      const area = chart.chartArea;
      const txt = document.getElementById("lastUpdatedValue").textContent;
      if (!txt || txt === "--") return;
      ctx.save();
      ctx.font = '12px Segoe UI, sans-serif';
      ctx.fillStyle = '#444';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'top';
      const yPos = Math.max(area.top + 8, area.bottom - 20);
      ctx.fillText("Last updated: " + txt, area.left + 6, yPos);
      ctx.restore();
    } catch(e) { /* ignore */ }
  }
};

function setupChart(sensorKey){
  const ctx = document.getElementById('liveChart').getContext('2d');
  const conf = sensorConfig[sensorKey];
  document.getElementById('chartTitle').innerText = conf.label + ' Live Chart';

  // If a chart already exists, simply update title/axis and toggle visibility
  if (liveChart) {
    // Update y axis bounds for the newly active sensor
    liveChart.options.scales.y.max = conf.max;
    liveChart.options.scales.y.ticks.stepSize = (conf.max <= 1) ? 0.1 : Math.max(1, Math.round(conf.max/5));
    // Hide all datasets except the active one
    liveChart.data.datasets.forEach(d => { d.hidden = (d.label !== conf.label); });
    liveChart.update('none');
    chartReady = true;
    return;
  }

  // First-time chart creation: create one dataset per sensor so they all keep
  // receiving data in the background even when not visible.
  const datasets = Object.keys(sensorConfig).map(k => {
    const c = sensorConfig[k];
    return {
      label: c.label,
      borderColor: c.color,
      backgroundColor: 'transparent',
      data: [],
      fill: false,
      tension: 0.25,
      pointRadius: 2.5,
      pointHoverRadius: 6,
      borderWidth: 2,
      hidden: (k !== sensorKey)
    };
  });

  liveChart = new Chart(ctx, {
    type: 'line',
    data: { labels: [], datasets: datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 0 },
      interaction: { mode: 'nearest', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          enabled: true,
          callbacks: { label: function(ctx){ return `${ctx.dataset.label}: ${ctx.parsed.y}`; } }
        }
      },
      scales: {
        x: { display: true, ticks: { maxRotation: 0, autoSkip: true } },
        y: {
          beginAtZero: true,
          suggestedMin: 0,
          min: 0,
          max: conf.max,
          ticks: {
            stepSize: (conf.max <= 1) ? 0.1 : Math.max(1, Math.round(conf.max/5)),
            padding: 6,
            font: { size: 12 }
          }
        }
      },
      layout: { padding: { bottom: 18 } }
    },
    plugins: [lastValueLabelPlugin]
  });
  chartReady = true;
  // If we have persisted history, restore it into the fresh Chart instance
  try { restoreChartToLive(); } catch(e) { /* ignore restore errors */ }
}
function switchChart(sensorKey){ activeSensor = sensorKey; setupChart(sensorKey); }

/* Initialize chart after page is idle to avoid blocking first paint */
(function lazyInitChart(){
  if (window.requestIdleCallback) {
    requestIdleCallback(()=>{ setupChart(activeSensor); });
  } else {
    setTimeout(()=>{ setupChart(activeSensor); }, 0);
  }
})();

/* ──────────────────────────────────────────────────────────────────
   MONITORING: Fetch from Flask with same-origin proxy fallback
   ────────────────────────────────────────────────────────────────── */
// Primary fallback host for direct fetch (matches Server_PC.py output)
const FLASK_BASE = "http://192.168.0.2:5000";
async function robustFetchJson() {
  const sameOrigin = `${window.location.pathname}?api=get`;
  const remote     = `${FLASK_BASE}/get`;
  try {
    const r1 = await fetch(sameOrigin, { method: "GET", cache: "no-store" });
    if (r1.ok) return await r1.json();
  } catch (e) { }
  const r2 = await fetch(remote, { method: "GET", cache: "no-store" });
  return await r2.json();
}
function safeNumber(x, def=0){ const n = Number(x); return Number.isFinite(n) ? n : def; }
// Return null when value is missing or not a finite number (avoid defaulting to 0)
function safeNumberOrNull(x){ const n = Number(x); return Number.isFinite(n) ? n : null; }
function formatTimestamp(ts) {
  const d = ts ? new Date(ts) : new Date();
  if (isNaN(d)) return "--";
  const mm = String(d.getMonth()+1).padStart(2,"0");
  const dd = String(d.getDate()).padStart(2,"0");
  const yyyy = d.getFullYear();
  let hours = d.getHours();
  const minutes = String(d.getMinutes()).padStart(2,"0");
  const ampm = hours >= 12 ? "PM" : "AM";
  hours = hours % 12; hours = hours ? hours : 12;
  return `${mm}/${dd}/${yyyy} ${hours}:${minutes} ${ampm}`;
}
// Helpers to compute Water Quality Index (WQI) from available sensor values.
// Each parameter is converted to a 0-100 quality index (Qi) using simple,
// documented heuristics. We then compute the weighted sum using official
// weights: PH 0.2, DO 0.3, TURB 0.2, AMMO 0.2, TEMP 0.1. If one or more
// components are missing, the remaining weights are re-normalized so the
// computed WQI is meaningful even when DO or PH are not present.
function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }
function scorePH(pH){
  if (!Number.isFinite(pH)) return null;
  // Ideal pH around 7. Deviations penalized strongly: factor chosen so
  // a 0.5 deviation -> ~33.3 drop (matches example mapping).
  const q = 100 - Math.abs(pH - 7) * 66.6666667;
  return clamp(Math.round(q * 10) / 10, 0, 100);
}
function scoreDO(d){
  if (!Number.isFinite(d)) return null;
  // Higher DO is better; normalize against 8 mg/L as a practical good value.
  const q = (d / 8) * 100;
  return clamp(Math.round(q * 10) / 10, 0, 100);
}
function scoreTurb(t){
  if (!Number.isFinite(t)) return null;
  // Lower turbidity is better. Map 0->100, 25 NTU -> 0 (linear).
  const q = 100 - (t / 25) * 100;
  return clamp(Math.round(q * 10) / 10, 0, 100);
}
function scoreNH3(nh3){
  if (!Number.isFinite(nh3)) return null;
  // Low ammonia is better. Map 0->100, 0.5 mg/L -> 0 (linear).
  const q = 100 - (nh3 / 0.5) * 100;
  return clamp(Math.round(q * 10) / 10, 0, 100);
}
function scoreTemp(t){
  if (!Number.isFinite(t)) return null;
  // Small deviations from an ideal temperature (25C) penalized lightly.
  // Each degree away reduces score by ~2 points (so 3C -> -6 => 94 for 28C).
  const q = 100 - Math.abs(t - 25) * 2;
  return clamp(Math.round(q * 10) / 10, 0, 100);
}
function computeWQIFromValues(vals){
  // vals: { PH, DO, TURB, AMMO, TEMP } numeric or undefined
  const weights = { PH:0.2, DO:0.3, TURB:0.2, AMMO:0.2, TEMP:0.1 };
  const qi = {};
  qi.PH   = Number.isFinite(vals.PH)   ? scorePH(vals.PH)   : null;
  qi.DO   = Number.isFinite(vals.DO)   ? scoreDO(vals.DO)   : null;
  qi.TURB = Number.isFinite(vals.TURB) ? scoreTurb(vals.TURB) : null;
  qi.AMMO = Number.isFinite(vals.AMMO) ? scoreNH3(vals.AMMO) : null;
  qi.TEMP = Number.isFinite(vals.TEMP) ? scoreTemp(vals.TEMP) : null;

  // Sum weighted Qi for available components and renormalize weights if any missing
  let weightedSum = 0;
  let weightSum = 0;
  Object.keys(weights).forEach(k => {
    if (qi[k] !== null && typeof qi[k] !== 'undefined') {
      weightedSum += qi[k] * weights[k];
      weightSum += weights[k];
    }
  });
  if (weightSum <= 0) return null;
  const wqi = Math.round((weightedSum / weightSum) * 10) / 10; // one decimal place
  return wqi;
}
function wqiStatusLabel(wqi){
  if (!Number.isFinite(wqi)) return '';
  if (wqi >= 90) return 'Excellent';
  if (wqi >= 70) return 'Good';
  if (wqi >= 50) return 'Medium';
  if (wqi >= 25) return 'Poor';
  return 'Very Poor';
}
// Persist last-known sensor values so the UI doesn't fall back to zeros when data is absent
const lastKnown = {};
const LAST_KNOWN_KEY = 'wave_lastKnown_v1';
function loadLastKnownFromStorage(){
  try{
    const raw = localStorage.getItem(LAST_KNOWN_KEY);
    if (raw){ const parsed = JSON.parse(raw); if (parsed && typeof parsed === 'object') Object.assign(lastKnown, parsed); }
  }catch(e){ console.error('lastKnown load error', e); }
}
function saveLastKnownToStorage(){
  try{ localStorage.setItem(LAST_KNOWN_KEY, JSON.stringify(lastKnown)); }catch(e){ /* noop */ }
}
function renderFromLastKnown(){
  try{
    if (typeof lastKnown.WQI !== 'undefined') document.getElementById('wqiValue').textContent = lastKnown.WQI;
  if (typeof lastKnown.WQI_STATUS !== 'undefined') { const s = document.getElementById('wqi_status'); if (s) s.textContent = lastKnown.WQI_STATUS || ''; }
    // PH value: accept multiple casings
    const phVal = (typeof lastKnown.PH !== 'undefined') ? lastKnown.PH : (typeof lastKnown.ph !== 'undefined' ? lastKnown.ph : undefined);
    if (typeof phVal !== 'undefined') document.getElementById('ph_level').textContent = phVal;
    if (typeof lastKnown.TURB !== 'undefined') document.getElementById('turbidity').textContent = (Number.isFinite(Number(lastKnown.TURB)) ? Number(lastKnown.TURB).toFixed(1) : String(lastKnown.TURB));
    if (typeof lastKnown.TEMP !== 'undefined') document.getElementById('temperature').textContent = (Number.isFinite(Number(lastKnown.TEMP)) ? Number(lastKnown.TEMP).toFixed(2) : String(lastKnown.TEMP));
    if (typeof lastKnown.AMMO !== 'undefined' || typeof lastKnown.ammonia !== 'undefined') {
      const aVal = lastKnown.AMMO;
      const aDisplay = (typeof aVal === 'undefined' || aVal === null) ? lastKnown.ammonia : aVal;
      document.getElementById('ammonia').textContent = (Number.isFinite(Number(aDisplay)) ? Number(aDisplay).toFixed(2) : String(aDisplay));
    }
    const doVal = (typeof lastKnown.DO !== 'undefined') ? lastKnown.DO : (typeof lastKnown.do !== 'undefined' ? lastKnown.do : undefined);
    if (typeof doVal !== 'undefined') document.getElementById('do').textContent = doVal;
      // Restore persisted PH/DO status strings if available (accept multiple key casings)
      const phStatusVal = (typeof lastKnown.PH_STATUS !== 'undefined') ? lastKnown.PH_STATUS : (typeof lastKnown.ph_status !== 'undefined' ? lastKnown.ph_status : (typeof lastKnown.phStatus !== 'undefined' ? lastKnown.phStatus : undefined));
      if (typeof phStatusVal !== 'undefined') { const e = document.getElementById('ph_status'); if (e) e.textContent = phStatusVal || ''; }
      const doStatusVal = (typeof lastKnown.DO_STATUS !== 'undefined') ? lastKnown.DO_STATUS : (typeof lastKnown.do_status !== 'undefined' ? lastKnown.do_status : (typeof lastKnown.doStatus !== 'undefined' ? lastKnown.doStatus : undefined));
      if (typeof doStatusVal !== 'undefined') { const e = document.getElementById('do_status'); if (e) e.textContent = doStatusVal || ''; }
    // status fields
    if (typeof lastKnown.TURB_STATUS !== 'undefined') {
      const e = document.getElementById('turbidity_status'); if (e) e.textContent = lastKnown.TURB_STATUS || '';
    }
    if (typeof lastKnown.TEMP_STATUS !== 'undefined') {
      const e2 = document.getElementById('temperature_status'); if (e2) e2.textContent = lastKnown.TEMP_STATUS || '';
    }
    if (typeof lastKnown.AMMO_STATUS !== 'undefined') {
      const e3 = document.getElementById('ammonia_status'); if (e3) e3.textContent = lastKnown.AMMO_STATUS || '';
    }
  }catch(e){ /* ignore render errors */ }
}
// Load persisted values immediately so the page shows them while waiting for first fetch
loadLastKnownFromStorage();
renderFromLastKnown();

// --- Chart persistence helpers ---
const CHART_STORE_KEY = 'wave_monitor_chart_v1';
function loadChartHistory() {
  try {
    const raw = localStorage.getItem(CHART_STORE_KEY);
    if (!raw) return null;
    return JSON.parse(raw);
  } catch (e) { return null; }
}
function saveChartHistory(state) {
  try { localStorage.setItem(CHART_STORE_KEY, JSON.stringify(state)); } catch (e) { /* noop */ }
}

function appendChartTick(values) {
  // values: { WQI, PH, TURB, TEMP, AMMO, DO } - numeric or null
  try {
    const now = Date.now();
    const stored = loadChartHistory() || { labels: [], datasets: {} , lastUpdated: now };
    // push shared timeline label (empty string to keep compact)
    stored.labels.push('');
    // Ensure PH and DO are always persisted alongside any configured sensor datasets
    const configuredKeys = (typeof sensorConfig === 'object' && sensorConfig) ? Object.keys(sensorConfig) : [];
    const keysList = configuredKeys.concat(['PH','DO']).filter((v, i, a) => a.indexOf(v) === i);
    keysList.forEach(k => {
      if (!stored.datasets[k]) stored.datasets[k] = [];
      let v = null;
      try {
        // Prefer fresh numeric value from values when present
        if (typeof values[k] !== 'undefined' && values[k] !== null && String(values[k]).trim() !== '') {
          const n = Number(values[k]);
          if (Number.isFinite(n)) v = n;
        }
        // If no fresh numeric value, try lastKnown persisted value
        if (v === null) {
          if (typeof lastKnown[k] !== 'undefined' && lastKnown[k] !== null && String(lastKnown[k]).trim() !== '') {
            const lk = Number(lastKnown[k]); if (Number.isFinite(lk)) v = lk;
          }
        }
        // If still no value, repeat last stored point for smoothness
        if (v === null) {
          const arr = stored.datasets[k];
          if (arr && arr.length > 0) {
            const last = arr[arr.length - 1];
            if (typeof last !== 'undefined' && last !== null) v = last;
          }
        }
      } catch (e) {
        /* ignore fallback errors */
      }
      stored.datasets[k].push(v);
      if (stored.datasets[k].length > maxPoints) stored.datasets[k].shift();
    });
    if (stored.labels.length > maxPoints) stored.labels.shift();
    stored.lastUpdated = now;
    saveChartHistory(stored);
  } catch (e) { /* ignore persistence errors */ }
}

function restoreChartToLive() {
  try {
    const st = loadChartHistory();
    if (!st || !liveChart) return;
    liveChart.data.labels = st.labels.slice();
    Object.keys(sensorConfig).forEach((k, idx) => {
      const arr = (st.datasets && st.datasets[k]) ? st.datasets[k].slice() : [];
      liveChart.data.datasets[idx].data = arr.slice(-maxPoints);
    });
    liveChart.update('none');
  } catch (e) { /* ignore restore errors */ }
}

async function fetchData() {
  // Fetch every tick but only update the visible chart/UI when monitoring tab is active.
  const active = document.querySelector(".nav-item.active")?.dataset.tab;
  const shouldUpdateUI = (active === 'water' && chartReady);

  try {
    const wrapper = await robustFetchJson();
    if (wrapper && typeof wrapper.message === 'object') {
      const s = wrapper.message;
  // Accept variants for turbidity, temperature and ammonia (NTU_VALUE, TEMP_C, IMU_TEMP_C, NH3_PPM)
  const turbRaw = s.TURB ?? s.turb ?? s.TURBIDITY ?? s.turbidity ?? s.NTU_VALUE ?? s.ntu_value;
  const tempRaw = s.TEMP ?? s.temp ?? s.TEMP_C ?? s.temp_c ?? s.IMU_TEMP_C ?? s.imu_temp_c;
  const ammoRaw = s.AMMO ?? s.ammo ?? s.NH3_PPM ?? s.NH3_PPM_VALUE ?? s.nh3_ppm ?? s.NH3_PPM_VALUE;

      const data = {
        WQI:  safeNumberOrNull(s.WQI  ?? s.wqi),
        PH:   safeNumberOrNull(s.PH   ?? s.pH),
        TURB: safeNumberOrNull(turbRaw ?? s.TURB ?? s.turb),
        TEMP: safeNumberOrNull(tempRaw ?? s.TEMP ?? s.temp),
        AMMO: safeNumberOrNull(ammoRaw ?? s.AMMO ?? s.ammo),
        DO:   safeNumberOrNull(s.DO   ?? s.do)
      };

      // Show fresh values when present; otherwise use lastKnown values to avoid falling back to zeros
      const present = v => (typeof v !== 'undefined' && v !== null && String(v).trim() !== '');

      // WQI: prefer server-provided WQI; otherwise compute from available
      // sensor components (PH, DO, TURB, AMMO, TEMP). When a component is
      // missing we fall back to the persisted lastKnown values and
      // renormalize weights so WQI is still meaningful.
      if (present(s.WQI ?? s.wqi)) {
        const w = safeNumber(s.WQI ?? s.wqi);
        document.getElementById("wqiValue").textContent = w;
        const statusLabel = wqiStatusLabel(w);
        const sEl = document.getElementById('wqi_status'); if (sEl) sEl.textContent = statusLabel;
        lastKnown.WQI = w;
        lastKnown.WQI_STATUS = statusLabel;
      } else {
        // Build a set of numeric values from the fresh payload or lastKnown
        const phVal = present(s.PH ?? s.pH) ? safeNumber(s.PH ?? s.pH) : (typeof lastKnown.PH !== 'undefined' ? Number(lastKnown.PH) : undefined);
        const doVal = present(s.DO ?? s.do) ? safeNumber(s.DO ?? s.do) : (typeof lastKnown.DO !== 'undefined' ? Number(lastKnown.DO) : undefined);
        const turbVal = present(turbRaw) ? safeNumber(turbRaw) : (typeof lastKnown.TURB !== 'undefined' ? Number(lastKnown.TURB) : undefined);
        const ammoVal = present(ammoRaw) ? safeNumber(ammoRaw) : (typeof lastKnown.AMMO !== 'undefined' ? Number(lastKnown.AMMO) : undefined);
        const tempVal = present(tempRaw) ? safeNumber(tempRaw) : (typeof lastKnown.TEMP !== 'undefined' ? Number(lastKnown.TEMP) : undefined);

        const computed = computeWQIFromValues({ PH: phVal, DO: doVal, TURB: turbVal, AMMO: ammoVal, TEMP: tempVal });
        if (computed !== null) {
          document.getElementById("wqiValue").textContent = computed;
          const statusLabel = wqiStatusLabel(computed);
          const sEl = document.getElementById('wqi_status'); if (sEl) sEl.textContent = statusLabel;
          lastKnown.WQI = computed;
          lastKnown.WQI_STATUS = statusLabel;
        } else if (typeof lastKnown.WQI !== 'undefined') {
          document.getElementById("wqiValue").textContent = lastKnown.WQI;
          const sEl = document.getElementById('wqi_status'); if (sEl) sEl.textContent = (typeof lastKnown.WQI_STATUS !== 'undefined') ? lastKnown.WQI_STATUS : wqiStatusLabel(Number(lastKnown.WQI));
        } else {
          document.getElementById("wqiValue").textContent = data.WQI;
        }
      }

      // PH
      if (present(s.PH ?? s.pH ?? s.PH_VAL ?? s.PH_LEVEL)) {
        const p = safeNumber(s.PH ?? s.pH ?? s.PH_VAL ?? s.PH_LEVEL);
        document.getElementById("ph_level").textContent = p;
        lastKnown.PH = p;
      } else if (typeof lastKnown.PH !== 'undefined') {
        document.getElementById("ph_level").textContent = lastKnown.PH;
      } else {
        document.getElementById("ph_level").textContent = data.PH;
      }

      // Turbidity
      const turbEl = document.getElementById("turbidity");
      if (turbEl) {
        if (present(turbRaw)) {
          const n = Number(String(turbRaw).trim());
          const v = Number.isFinite(n) ? n.toFixed(1) : String(turbRaw);
          turbEl.textContent = v;
          lastKnown.TURB = Number.isFinite(n) ? Number(n) : v;
        } else if (typeof lastKnown.TURB !== 'undefined') {
          const lk = lastKnown.TURB;
          turbEl.textContent = Number.isFinite(Number(lk)) ? Number(lk).toFixed(1) : String(lk);
        } else {
          turbEl.textContent = data.TURB;
        }
      }

      // Temperature
      const tempEl = document.getElementById("temperature");
      if (tempEl) {
        if (present(tempRaw)) {
          const n2 = Number(String(tempRaw).trim());
          const v2 = Number.isFinite(n2) ? n2.toFixed(2) : String(tempRaw);
          tempEl.textContent = v2;
          lastKnown.TEMP = Number.isFinite(n2) ? Number(n2) : v2;
        } else if (typeof lastKnown.TEMP !== 'undefined') {
          const lk2 = lastKnown.TEMP;
          tempEl.textContent = Number.isFinite(Number(lk2)) ? Number(lk2).toFixed(2) : String(lk2);
        } else {
          tempEl.textContent = data.TEMP;
        }
      }

      // Ammonia (display with two decimals when numeric). Accept NH3_PPM variants.
      if (present(ammoRaw)) {
        const a = safeNumber(ammoRaw);
        const displayA = Number.isFinite(a) ? a.toFixed(2) : String(ammoRaw);
        document.getElementById("ammonia").textContent = displayA;
        lastKnown.AMMO = Number.isFinite(a) ? a : displayA;
      } else if (typeof lastKnown.AMMO !== 'undefined') {
        const lkA = lastKnown.AMMO;
        document.getElementById("ammonia").textContent = Number.isFinite(Number(lkA)) ? Number(lkA).toFixed(2) : String(lkA);
      } else {
        const dA = data.AMMO;
        document.getElementById("ammonia").textContent = Number.isFinite(Number(dA)) ? Number(dA).toFixed(2) : String(dA);
      }

      // DO
      if (present(s.DO ?? s.do ?? s.DO_MGL ?? s.DO_MG_L ?? s.DO_MG)) {
        const dval = safeNumber(s.DO ?? s.do ?? s.DO_MGL ?? s.DO_MG_L ?? s.DO_MG);
        document.getElementById("do").textContent = dval;
        lastKnown.DO = dval;
      } else if (typeof lastKnown.DO !== 'undefined') {
        document.getElementById("do").textContent = lastKnown.DO;
      } else {
        document.getElementById("do").textContent = data.DO;
      }

      // Populate status strings for turbidity and temperature (accept multiple possible keys)
      const turbStatusEl = document.getElementById('turbidity_status');
      if (turbStatusEl) {
        const turbStatusRaw = s.NTU_STATUS ?? s.NTU_STATUS_MSG ?? s.TURB_STATUS ?? s.TURBIDITY_STATUS ?? s.turb_status ?? s.ntu_status ?? s.TURB_STATUS_MSG ?? s.status_turb;
        if (present(turbStatusRaw)) {
          const ts = String(turbStatusRaw);
          turbStatusEl.textContent = ts;
          lastKnown.TURB_STATUS = ts;
        } else if (typeof lastKnown.TURB_STATUS !== 'undefined') {
          turbStatusEl.textContent = lastKnown.TURB_STATUS || '';
        } else {
          turbStatusEl.textContent = '';
        }
      }
      const tempStatusEl = document.getElementById('temperature_status');
      if (tempStatusEl) {
        const tempStatusRaw = s.TEMP_STATUS ?? s.TEMP_STATUS_MSG ?? s.TEMP_STATUS_TEXT ?? s.temp_status ?? s.temperature_status ?? s.tempStatus;
        if (present(tempStatusRaw)) {
          const tt = String(tempStatusRaw);
          tempStatusEl.textContent = tt;
          lastKnown.TEMP_STATUS = tt;
        } else if (typeof lastKnown.TEMP_STATUS !== 'undefined') {
          tempStatusEl.textContent = lastKnown.TEMP_STATUS || '';
        } else {
          tempStatusEl.textContent = '';
        }
      }

      // Ammonia status (accept NH3_ and AMMO_ variants)
      const ammoStatusEl = document.getElementById('ammonia_status');
      if (ammoStatusEl) {
        const ammoStatusRaw = s.NH3_STATUS ?? s.NH3_STATUS_MSG ?? s.NH3_STATUS_TEXT ?? s.NH3_PPM ?? s.AMMO_STATUS ?? s.AMMO_STATUS_MSG ?? s.ammo_status ?? s.nh3_status ?? s.nh3_status_msg;
        if (present(ammoStatusRaw)) {
          const at = String(ammoStatusRaw);
          ammoStatusEl.textContent = at;
          lastKnown.AMMO_STATUS = at;
        } else if (typeof lastKnown.AMMO_STATUS !== 'undefined') {
          ammoStatusEl.textContent = lastKnown.AMMO_STATUS || '';
        } else {
          ammoStatusEl.textContent = '';
        }
      }

      // DO status (accept common variants)
      const doStatusEl = document.getElementById('do_status');
      if (doStatusEl) {
        const doStatusRaw = s.DO_STATUS ?? s.DO_STATUS_MSG ?? s.DO_STATUS_TEXT ?? s.DISSOLVED_OXYGEN_STATUS ?? s.do_status ?? s.dissolved_oxygen_status;
        if (present(doStatusRaw)) {
          const ds = String(doStatusRaw);
          doStatusEl.textContent = ds;
          lastKnown.DO_STATUS = ds; lastKnown.do_status = ds; // persist both casings
          saveLastKnownToStorage();
        } else if (typeof lastKnown.DO_STATUS !== 'undefined' || typeof lastKnown.do_status !== 'undefined') {
          const stored = typeof lastKnown.DO_STATUS !== 'undefined' ? lastKnown.DO_STATUS : lastKnown.do_status;
          doStatusEl.textContent = stored || '';
        } else {
          doStatusEl.textContent = '';
        }
      }

      // PH status (accept common variants)
      const phStatusEl = document.getElementById('ph_status');
      if (phStatusEl) {
        const phStatusRaw = s.PH_STATUS ?? s.PH_STATUS_MSG ?? s.PH_STATUS_TEXT ?? s.pH_STATUS ?? s.ph_status ?? s.ph_status_msg;
        if (present(phStatusRaw)) {
          const ps = String(phStatusRaw);
          phStatusEl.textContent = ps;
          lastKnown.PH_STATUS = ps; lastKnown.ph_status = ps; // persist both casings
          saveLastKnownToStorage();
        } else if (typeof lastKnown.PH_STATUS !== 'undefined' || typeof lastKnown.ph_status !== 'undefined') {
          const stored = typeof lastKnown.PH_STATUS !== 'undefined' ? lastKnown.PH_STATUS : lastKnown.ph_status;
          phStatusEl.textContent = stored || '';
        } else {
          phStatusEl.textContent = '';
        }
      }

      // Persist any updated lastKnown values (including status fields)
      saveLastKnownToStorage();

      const raw = s.last_updated || s.updated || Date.now();
      document.getElementById("lastUpdatedValue").textContent = formatTimestamp(raw);

      // Decide which sensors are present this tick
      const presentMap = {
        WQI: present(s.WQI ?? s.wqi),
        PH:  present(s.PH ?? s.pH ?? s.PH_VAL ?? s.PH_LEVEL),
        TURB: present(turbRaw),
        TEMP: present(tempRaw),
        AMMO: present(ammoRaw),
        DO:   present(s.DO ?? s.do ?? s.DO_MGL ?? s.DO_MG_L ?? s.DO_MG)
      };

      // Always append this tick to the persisted chart history so it survives navigation/refresh
      appendChartTick(data);

      // If the monitoring UI is active, also render into the live Chart.js instance
      if (shouldUpdateUI && liveChart) {
        // Push a label for this tick (shared timeline)
        liveChart.data.labels.push('');

        // For each sensor dataset, pick the fresh value if present otherwise lastKnown fallback
        Object.keys(sensorConfig).forEach((key, idx) => {
          // Determine the freshest value for this sensor key, being tolerant to
          // casing/variant differences (PH/DO sometimes arrive as uppercase keys)
          let val;
          const kLower = String(key).toLowerCase();
          if (kLower === 'ph') {
            // Prefer fresh numeric value when present; avoid treating null as 0
            if (present(data.PH)) val = safeNumber(data.PH);
            else if (present(data.ph)) val = safeNumber(data.ph);
            else {
              // fallback to persisted lastKnown (check both casings)
              if (typeof lastKnown.PH !== 'undefined' && lastKnown.PH !== null && String(lastKnown.PH).trim() !== '') val = Number(lastKnown.PH);
              else if (typeof lastKnown.ph !== 'undefined' && lastKnown.ph !== null && String(lastKnown.ph).trim() !== '') val = Number(lastKnown.ph);
              else val = undefined;
            }
          } else if (kLower === 'do') {
            if (present(data.DO)) val = safeNumber(data.DO);
            else if (present(data.do)) val = safeNumber(data.do);
            else {
              if (typeof lastKnown.DO !== 'undefined' && lastKnown.DO !== null && String(lastKnown.DO).trim() !== '') val = Number(lastKnown.DO);
              else if (typeof lastKnown.do !== 'undefined' && lastKnown.do !== null && String(lastKnown.do).trim() !== '') val = Number(lastKnown.do);
              else val = undefined;
            }
          } else {
            // default behavior for other sensors
            val = (typeof data[key] !== 'undefined') ? data[key] : undefined;
            if (!presentMap[key] && typeof lastKnown[key] !== 'undefined') val = lastKnown[key];
          }

          // Ensure numeric types remain numeric where possible; if still undefined,
          // repeat the previous point in the live dataset to avoid falling to zero.
          // Only convert to Number when a present non-empty value exists.
          let pushVal;
          if (typeof val !== 'undefined' && val !== null && String(val).trim() !== '') {
            const n = Number(val);
            pushVal = Number.isFinite(n) ? n : val;
          } else {
            pushVal = null;
          }
          const ds = liveChart.data.datasets[idx];
          if (!ds) return;
          if (typeof pushVal === 'undefined' || pushVal === null) {
            // repeat last known plotted point if available
            if (ds.data.length > 0) {
              pushVal = ds.data[ds.data.length - 1];
            } else {
              pushVal = null;
            }
          }
          ds.data.push(pushVal);
          if (ds.data.length > maxPoints) ds.data.shift();
        });

        // Trim labels if needed
        if (liveChart.data.labels.length > maxPoints) liveChart.data.labels.shift();

        // Update y axis to active sensor's config and refresh chart
        const conf = sensorConfig[activeSensor];
        liveChart.options.scales.y.max = conf.max;
        liveChart.options.scales.y.ticks.stepSize = (conf.max <= 1) ? 0.1 : Math.max(1, Math.round(conf.max/5));
        // Ensure only the active dataset is visible
        liveChart.data.datasets.forEach(d => { d.hidden = (d.label !== conf.label); });
        liveChart.update('none');
      }
    }
  } catch (err) { }
}

/* ──────────────────────────────────────────────────────────────────
   SYSTEM TOOLS — Combo Box switching + State + SweetAlert + PDF Export
   ────────────────────────────────────────────────────────────────── */

/* Unified view: sensors and vessel actions are shown together; dropdown removed */

/* Sensor toggle + persistence */
const ST_SENSOR_KEYS = ['ph','turb','temp','do','load1','load2','ultra'];
function ST_toggleSensor(input, sensor) {
  const dot = document.getElementById("st-dot-"+sensor);
  const key = "st-sensor-"+sensor;
  const isOn = !!input.checked;
  if (dot) dot.className = "st-dot " + (isOn ? "st-on" : "st-off");
  localStorage.setItem(key, isOn ? "1" : "0");
  // Emit to server when socket connected; otherwise create a local log so actions
  // are visible even when the client is offline/disconnected.
  try {
    const username = '<?php echo addslashes($_SESSION['username']); ?>';
    const labels = { ph: 'PH', turb: 'Turbidity', temp: 'Temperature', ammo: 'Ammonia', do: 'Dissolved Oxygen', load1: 'Loadcell 1', load2: 'Loadcell 2', ultra: 'Feed Level (Ultrasonic)' };
    const label = labels[sensor] || sensor;
    const actionText = isOn ? `turned ON ${label}` : `turned OFF ${label}`;
    if (window.socket && window.socket.connected) {
      // Let server emit canonical log to other clients
      window.socket.emit('sensor.toggle', { sensor: sensor, value: isOn, user: username, role: 'ADMIN', ts: Date.now(), origin: 'local' });
    } else {
      // Local fallback: write to UI logs and POST to server (ST_addLog will attempt DB POST)
      ST_addLog('action', `[ADMIN] ${username} ${actionText}`);
    }
  } catch (e) { /* ignore logging errors */ }
}
function ST_loadSensorStates() {
  ST_SENSOR_KEYS.forEach(k => {
    const sw  = document.getElementById("st-sw-"+k);
    const dot = document.getElementById("st-dot-"+k);
    const v = localStorage.getItem("st-sensor-"+k) === "1";
    if (sw) sw.checked = v;
    if (dot) dot.className = "st-dot " + (v ? "st-on" : "st-off");
  });
}
/* Vessel state */
function ST_setVesselState(state, emit=true) {
  const statusEl = document.getElementById("st-vesselStatus");
  const powerBtn = document.getElementById("st-powerBtn");
  const sensorSwitches = document.querySelectorAll(".st-switch input[type='checkbox']");

  const toggleAllBtn = document.getElementById('toggleAllBtn');
  if (state === "ON") {
  if (statusEl){ statusEl.textContent = "Vessel Status: ON"; statusEl.className = "st-vessel-on"; }
  if (powerBtn){ powerBtn.textContent = "Shutdown Vessel"; powerBtn.className = "st-btn st-powerOff st-pill"; }
    sensorSwitches.forEach(sw => { sw.disabled = false; sw.parentElement.style.opacity = "1"; });
    if (toggleAllBtn) { toggleAllBtn.disabled = false; toggleAllBtn.style.opacity = "1"; toggleAllBtn.style.cursor = "pointer"; }
  } else {
  if (statusEl){ statusEl.textContent = "Vessel Status: OFF"; statusEl.className = "st-vessel-off"; }
  if (powerBtn){ powerBtn.textContent = "Power On Vessel"; powerBtn.className = "st-btn st-powerOn st-pill"; }
    sensorSwitches.forEach(sw => { sw.disabled = true; sw.parentElement.style.opacity = ".6"; });
    if (toggleAllBtn) { toggleAllBtn.disabled = true; toggleAllBtn.style.opacity = ".6"; toggleAllBtn.style.cursor = "not-allowed"; }
  }
  localStorage.setItem("vesselState", state);
  try {
    // Only emit when this was initiated locally; if socket missing, create a local log
    const username = '<?php echo addslashes($_SESSION['username']); ?>';
    if (emit && window.socket && window.socket.connected) {
      window.socket.emit('vessel.change', { state: state, user: username, role: 'ADMIN', ts: Date.now(), origin: 'local' });
    } else if (emit) {
      const action = (state === 'ON') ? 'powered ON the vessel' : 'shut down the vessel';
      ST_addLog('alert', `[ADMIN] ${username} ${action}`);
    }
  } catch(e) {}
}

/* Unified Power Button */
function ST_togglePower() {
  const state = localStorage.getItem("vesselState") || "ON";
  if (state === "ON") {
    Swal.fire({
      title: 'Shutdown Vessel?',
      text: "This will power off the system.",
      icon:'error', showCancelButton:true,
      confirmButtonColor:'#9d0208', cancelButtonColor:'#aaa',
      confirmButtonText:'Yes, shutdown'
    }).then((res)=>{
      if (res.isConfirmed) {
  ST_setVesselState("OFF");
  // Server will emit authoritative log.event for the shutdown action
        const rs = document.getElementById("st-rebootStatus");
        if (rs) rs.textContent = "Shutting down vessel...";
          setTimeout(()=>{
            const rs2 = document.getElementById("st-rebootStatus");
            if (rs2) rs2.textContent = "Vessel is now powered off.";
          },3000);
      }
    });
  } else {
    Swal.fire({
      title:'Power On Vessel?',
      text:'This will start the system.',
      icon:'success', showCancelButton:true,
      confirmButtonColor:'#06d6a0', cancelButtonColor:'#aaa',
      confirmButtonText:'Yes, power on'
    }).then((res)=>{
      if (res.isConfirmed) {
  ST_setVesselState("ON");
  // Server will emit authoritative log.event for the power-on action
        const rs = document.getElementById("st-rebootStatus");
        if (rs) rs.textContent = "Vessel powering on...";
          setTimeout(()=>{
            const rs2 = document.getElementById("st-rebootStatus");
            if (rs2) rs2.textContent = "Vessel is now running.";
          },3000);
      }
    });
  }
}


/* Logs + persistence + PDF export (displayed in Notifications tab) */
// Classify log entry into a category
function classifyLog(type, message) {
  const msg = message.toLowerCase();
  // Always treat login/logout as 'login' (ACCESS)
  if (/login|logged in|logout|logged out/.test(msg)) return 'login';
  if (type === 'info' || /\binfo\b/.test(msg)) return 'info';
  if (type === 'alert' || /shutdown|powered on/.test(msg)) return 'alert';
  if (/sensor|all sensors/.test(msg)) return 'action';
  if (type === 'warn' || /⚠️|delay|fail|error|alarm/.test(msg)) return 'alarm';
  return 'action';
}

function ST_addLog(type, message, opts){
  // opts: { noDb: true } => do not POST this log to server (used when rendering server rows)
  opts = opts || {};
  const box = document.getElementById("st-logBox");
  if (!box) return; // only when Notification tab DOM exists
  const tr = document.createElement("tr");
  // Parse timestamp and message
  let timestamp = (opts.timestamp) ? opts.timestamp : new Date().toLocaleString();
  let msg = message || '';
  try {
    // Collapse duplicate role prefixes like "[USER] user1 [USER] action" -> keep only first prefix
    let seenRole = false;
    msg = msg.replace(/\[(USER|ADMIN)\]\s*/gi, function(full, role){
      if (!seenRole) { seenRole = true; return '[' + role.toUpperCase() + '] '; }
      return '';
    }).trim();
    // If no role prefix present at all, default to ADMIN for admin page logs
    if (!/^\[(USER|ADMIN)\]/i.test(msg)) {
      msg = '[ADMIN] ' + msg;
    }
  } catch (e) { /* fallback to raw message */ }
  const category = classifyLog(type, message);
  tr.className = `st-log-entry cat-${category}`;
  tr.dataset.category = category;
  // Timestamp cell
  const tdTime = document.createElement("td");
  tdTime.textContent = timestamp;
  tdTime.className = "timestamp";
  // Message cell
  const tdMsg = document.createElement("td");
  tdMsg.textContent = msg;
  tdMsg.className = "message";
  // Category cell
  const tdCat = document.createElement("td");
  // Show 'Access' instead of 'Login' in the UI
  let catLabel = category.charAt(0).toUpperCase() + category.slice(1);
  if (category === 'login') catLabel = 'Access';
  if (category === 'info') catLabel = 'Info';
  tdCat.textContent = catLabel;
  tdCat.className = "category";
  tr.appendChild(tdTime);
  tr.appendChild(tdMsg);
  tr.appendChild(tdCat);
  box.prepend(tr);
  ST_saveLogs();
  if (typeof ST_loadLogs === 'function') ST_loadLogs();

  // Send log to server for DB storage unless caller asked us not to
  if (!opts.noDb) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", window.location.pathname, true);
      xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
      xhr.onload = function() {
        if (xhr.status !== 200) {
          console.error('DB log failed', xhr.responseText);
        } else {
          try {
            var resp = JSON.parse(xhr.responseText);
            if (!resp.success) {
              console.error('DB log error:', resp.error);
            }
          } catch (e) { console.error('DB log parse error', e, xhr.responseText); }
        }
      };
      xhr.send("log_to_event_log=1&desc=" + encodeURIComponent(msg) + "&status=" + encodeURIComponent(type.toUpperCase()));
    } catch (e) { console.error('DB log AJAX error', e); }
  }
}
function ST_saveLogs(){
  const box = document.getElementById("st-logBox");
  if (!box) return;
  const logs = Array.from(box.querySelectorAll(".st-log-entry")).map(tr=>{
    const tds = tr.querySelectorAll('td');
    let category = tr.dataset.category || 'action';
    // Always save 'login' as 'access' for consistency
    if (category === 'login') category = 'access';
    return {
      type: tr.className.replace(/st-log-entry|cat-(action|alert|login|alarm)/g, '').trim(),
      timestamp: tds[0]?.textContent || '',
      message: tds[1]?.textContent || '',
      category: category
    };
  });
  localStorage.setItem("systemLogs", JSON.stringify(logs));
}
function ST_loadLogs(){
  const box = document.getElementById("st-logBox");
  if (!box) return;
  box.innerHTML = '';
  let logs = JSON.parse(localStorage.getItem("systemLogs") || "[]");
  // Sort logs newest first (descending timestamp) so the latest entries show on top
  logs.sort((a, b) => {
    const ta = a.timestamp ? new Date(a.timestamp).getTime() : 0;
    const tb = b.timestamp ? new Date(b.timestamp).getTime() : 0;
    return tb - ta;
  });
  if (logs.length === 0) {
    const tr = document.createElement("tr");
    const td = document.createElement("td");
    td.colSpan = 3;
    td.style.textAlign = "center";
    td.style.background = "#e0e0e0";
    td.style.color = "#555";
    td.style.fontFamily = "monospace";
    td.style.fontSize = "1.2em";
    td.style.padding = "32px 0";
    td.textContent = "No notifications logs yet.";
    tr.appendChild(td);
    box.appendChild(tr);
    return;
  }
  logs.forEach(log=>{
    const tr = document.createElement("tr");
    // Always treat 'login' as 'access' for display and filtering
    let category = log.category || classifyLog(log.type, log.message || log.text);
    if (category === 'login') category = 'access';
    tr.className = `st-log-entry cat-${category}`;
    tr.dataset.category = category;
    // Timestamp cell
    const tdTime = document.createElement("td");
    tdTime.textContent = log.timestamp || '';
    tdTime.className = "timestamp";
    // Message cell
    const tdMsg = document.createElement("td");
    tdMsg.textContent = log.message || log.text || '';
    tdMsg.className = "message";
    // Category cell
    const tdCat = document.createElement("td");
    let catLabel = category.charAt(0).toUpperCase() + category.slice(1);
    if (category === 'access') catLabel = 'Access';
    if (category === 'info') catLabel = 'Info';
    tdCat.textContent = catLabel;
    tdCat.className = "category";
    tr.appendChild(tdTime);
    tr.appendChild(tdMsg);
    tr.appendChild(tdCat);
    box.appendChild(tr);
  });
}


function ST_clearLogs(){
  localStorage.removeItem("systemLogs");
  const box = document.getElementById("st-logBox");
  if (box) box.innerHTML = "";
}
function ST_confirmClearLogs(){
  Swal.fire({
    title:'Clear Logs?', text:'This will permanently delete all logs.',
    icon:'warning', showCancelButton:true,
    confirmButtonColor:'#ffb703', cancelButtonColor:'#aaa',
    confirmButtonText:'Yes, clear'
  }).then((r)=>{
    if (r.isConfirmed) {
      ST_clearLogs();
      Swal.fire('Cleared!','All logs have been deleted.','success');
    }
  });
}
function ST_exportLogsPDF(){
  // Accept filters as arguments (from modal)
  let cat = arguments[0] || 'all';
  // Treat 'access' and 'login' as equivalent for export filter
  let filterCat = (cat === 'access' || cat === 'login') ? 'access' : cat;
  let from = arguments[1];
  let to = arguments[2];
  const logs = JSON.parse(localStorage.getItem("systemLogs") || "[]");
  if (logs.length === 0) { Swal.fire("No Logs","There are no logs to export.","info"); return; }
  const fromTime = from ? new Date(from).getTime() : null;
  const toTime = to ? new Date(to).getTime() : null;
  // Filter logs only on export
  let filtered = logs.filter(log => {
    let logTime = 0;
    if (log.timestamp) logTime = new Date(log.timestamp).getTime();
    // Treat both 'login' and 'access' as equivalent for export filter
    let logCat = (log.category === 'login' || log.category === 'access') ? 'access' : log.category;
    const catMatch = (filterCat === 'all') || (logCat === filterCat);
    const fromMatch = !fromTime || (logTime >= fromTime);
    const toMatch = !toTime || (logTime <= toTime);
    return catMatch && fromMatch && toMatch;
  });
  // Sort logs by timestamp descending (newest first) to match the UI order
  filtered = filtered.sort((a, b) => {
    const ta = a.timestamp ? new Date(a.timestamp).getTime() : 0;
    const tb = b.timestamp ? new Date(b.timestamp).getTime() : 0;
    return tb - ta;
  });
  if (filtered.length === 0) { Swal.fire("No Logs","No logs match the selected filters.","info"); return; }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  doc.setFontSize(16);
  doc.text("Notification Logs Report", 14, 20);
  doc.setFontSize(12);
  doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 28);
  // Map logs to table: Timestamp | Message | Category
  const tableData = filtered.map(l => [
    l.timestamp || "",
    l.message || l.text || "",
    ((l.category === 'login') ? 'ACCESS' : (l.category || "").toUpperCase())
  ]);
  doc.autoTable({
    head: [['Timestamp','Log','Event']],
    body: tableData,
    startY: 35,
    styles: { fontSize: 10, cellPadding: 3 },
    headStyles: { fillColor: [30, 81, 98] }
  });
  // Set filename based on event category
  let filename = "NOTIFICATION LOGS.pdf";
  if (cat && cat !== 'all') {
    let label = cat.charAt(0).toUpperCase() + cat.slice(1);
    if (cat === 'access') label = 'Access';
    if (cat === 'info') label = 'Info';
    if (cat === 'alert') label = 'Alert';
    if (cat === 'action') label = 'Action';
    if (cat === 'alarm') label = 'Alarm';
    filename = label.toUpperCase() + ' LOGS.pdf';
  }
  doc.save(filename);
}

/* NEW: Turn All Sensors ON/OFF (bulk toggle by explicit state) */
function ST_toggleAllSensors(state){
  const keys=['ph','turb','temp','do','load1','load2','ultra'];
  keys.forEach(k=>{
    localStorage.setItem('st-sensor-'+k, state?'1':'0');
    const sw=document.getElementById('st-sw-'+k);
    const dot=document.getElementById('st-dot-'+k);
    if(sw) sw.checked=state;
    if(dot) dot.className="st-dot "+(state?"st-on":"st-off");
  });
    // Prefer emitting to server when socket connected; otherwise log locally so the
    // action appears in the UI even when there's no socket connection.
  try {
    const username = '<?php echo addslashes($_SESSION['username']); ?>';
    if (window.socket && window.socket.connected) {
  window.socket.emit('sensors.bulk', { keys: ['ph','turb','temp','do','load1','load2','ultra'], value: state, user: username, role: 'ADMIN', ts: Date.now(), origin: 'local' });
    } else {
      const action = state ? 'turned ON all sensors' : 'turned OFF all sensors';
      ST_addLog('action', `[ADMIN] ${username} ${action}`);
    }
  } catch(e) {}
}

/* 🔵 MERGED: Single toggle button handler with auto label */
function ST_allSensorsCurrentlyOn(){
  const keys=['ph','turb','temp','do','load1','load2','ultra'];
  return keys.every(k => localStorage.getItem('st-sensor-'+k) === '1');
}
function ST_toggleAllSensorsToggle(){
  const shouldTurnOn = !ST_allSensorsCurrentlyOn();
  ST_toggleAllSensors(shouldTurnOn);
  const btn = document.getElementById('toggleAllBtn');
  if (btn) btn.textContent = shouldTurnOn ? "ALL OFF" : "ALL ON";
}

/* NEW: JS-driven Logout that:
   - Stops monitoring timers
   - Turns ALL sensors OFF
   - Sets vessel to OFF (shutdown)
   - Writes logout + shutdown logs
   - Redirects to server logout (waveout.php)
*/
function performLogout(){
  try {
    stopMonitoring();
    // bulk OFF sensors
    ST_toggleAllSensors(false);
    // vessel off
    ST_setVesselState("OFF");
    ST_addLog("alert","System shutdown initiated by Admin");
    try {
      if (window.socket && window.socket.connected) {
        // Ask server to persist and broadcast the logout event
        window.socket.emit('log.event', { type: 'info', message: 'logged out', ts: Date.now(), origin: 'local' });
        // give server a short moment to process before redirect
        setTimeout(()=>{ window.location.href='waveout.php'; }, 250);
      } else {
        // fallback: local log (will POST to server)
        ST_addLog("info","<?php echo addslashes($_SESSION['username']); ?> logged out");
        setTimeout(()=>{ window.location.href='waveout.php'; }, 200);
      }
    } catch(e) {
      setTimeout(()=>{ window.location.href='waveout.php'; }, 200);
    }
  } catch(e) {
    // fail-safe redirect
    window.location.href='waveout.php';
  }
}

/* Inactivity auto-logout: 10 minutes idle -> show warning -> auto-logout */
(function(){
  const IDLE_MS = 10 * 60 * 1000; // 10 minutes
  const WARNING_MS = 15 * 1000; // 15 seconds warning countdown
  let idleTimer = null;
  let warningTimer = null;
  let countdownInterval = null;

  function resetIdle(){
    if(idleTimer) clearTimeout(idleTimer);
    if(warningTimer){ clearTimeout(warningTimer); warningTimer = null; }
    if(countdownInterval){ clearInterval(countdownInterval); countdownInterval = null; }
    idleTimer = setTimeout(onIdle, IDLE_MS);
  }

  function onIdle(){
    let secondsLeft = Math.floor(WARNING_MS / 1000);
    Swal.fire({
      title: 'Inactive',
      html: 'You have been inactive. Logging out in <strong id="sw-count">'+secondsLeft+'</strong> seconds.',
      icon: 'warning',
      showCancelButton: false,
      confirmButtonText: 'Stay signed in',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => {
        const el = document.getElementById('sw-count');
        countdownInterval = setInterval(() => {
          secondsLeft--;
          if(el) el.textContent = secondsLeft;
        }, 1000);
      }
    }).then((result) => {
      if(result.isConfirmed){
        resetIdle();
      } else {
        window.location.href = 'waveout.php';
      }
    });

    // Auto-logout after WARNING_MS if user doesn't interact
    warningTimer = setTimeout(() => {
      Swal.close();
      window.location.href = 'waveout.php';
    }, WARNING_MS);
  }

  ['mousemove','keydown','mousedown','touchstart','click','scroll'].forEach(evt => {
    window.addEventListener(evt, resetIdle, true);
  });

  // start the idle timer
  resetIdle();
})();

/* On Load: restore Tools states + logs and set initial behaviors */
window.addEventListener('load', ()=>{
  ST_loadLogs();           // populate logs (Notifications tab)
  ST_loadSensorStates();   // restore sensor toggles

  // Initialize Toggle All button label based on current sensor states
  const toggleBtn = document.getElementById('toggleAllBtn');
  if (toggleBtn) toggleBtn.textContent = ST_allSensorsCurrentlyOn() ? "ALL OFF" : "ALL ON";

  const state = localStorage.getItem("vesselState") || "ON";
  // Restore UI state without emitting/logging on page load
  ST_setVesselState(state, false);

  // Controller tab click handler
  const controllerLink = document.getElementById('controllerLink');
  if (controllerLink) {
    controllerLink.addEventListener('click', function(e) {
      const vesselState = localStorage.getItem('vesselState') || 'ON';
      if (vesselState === 'OFF') {
        e.preventDefault();
        Swal.fire('Vessel is OFF', 'Please turn ON the vessel to access the Controller.', 'warning');
      } else {
        window.location.href = 'controller.php?from=admin';
      }
    });
  }

  // Sensor toggles click handler
  document.querySelectorAll('.st-switch input[type="checkbox"]').forEach(sw => {
    sw.addEventListener('click', function(e) {
      const vesselState = localStorage.getItem('vesselState') || 'ON';
      if (vesselState === 'OFF') {
        e.preventDefault();
        Swal.fire('Vessel is OFF', 'Please turn ON the vessel to use sensors.', 'warning');
      }
    });
  });

  // Start monitoring only if current tab is Monitoring
  const active = document.querySelector(".nav-item.active")?.dataset.tab;
  if (active === 'water') startMonitoring();

  // ── Option 1: GET param hooks for login logging ──
  // If you redirect to this page with ?log=login we record a login event to Notifications.
  // Helper: wait for socket to be ready (connected) before emitting; fallback after timeout
  function emitWhenSocketReady(evName, payload, timeoutMs, fallback) {
    timeoutMs = timeoutMs || 2000;
    const start = Date.now();
    (function tryEmit(){
      try {
        if (window.socket && window.socket.connected) {
          window.socket.emit(evName, payload);
          return;
        }
      } catch(e) {}
      if (Date.now() - start < timeoutMs) {
        setTimeout(tryEmit, 100);
        return;
      }
      // timeout -> call fallback if provided
      try { if (typeof fallback === 'function') fallback(); } catch(e) {}
    })();
  }

  // Sync status UI (top-right badge)
  function createSyncStatus() {
    if (document.getElementById('st-syncStatus')) return;
    const el = document.createElement('div');
    el.id = 'st-syncStatus';
    el.style.position = 'fixed';
    el.style.top = '86px';
    el.style.right = '18px';
    el.style.zIndex = '9999';
    el.style.background = 'rgba(255,255,255,0.95)';
    el.style.border = '1px solid #ddd';
    el.style.padding = '6px 10px';
    el.style.borderRadius = '18px';
    el.style.boxShadow = '0 2px 8px rgba(0,0,0,0.06)';
    el.style.fontSize = '13px';
    el.style.color = '#222';
    el.innerHTML = '<span id="st-syncDot" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;background:#ccc;vertical-align:middle"></span><span id="st-syncText">Sync: offline</span><span id="st-syncTime" style="margin-left:8px;color:#666;font-size:11px"></span>';
    document.body.appendChild(el);
  }
  function updateSyncStatus(state, info) {
    const dot = document.getElementById('st-syncDot');
    const text = document.getElementById('st-syncText');
    const time = document.getElementById('st-syncTime');
    if (!dot || !text) return;
    if (state === 'connected') { dot.style.background = '#2ecc71'; text.textContent = 'Sync: connected'; }
    else if (state === 'disconnected') { dot.style.background = '#e74c3c'; text.textContent = 'Sync: disconnected'; }
    else if (state === 'active') { dot.style.background = '#f39c12'; text.textContent = 'Sync: active'; }
    else { dot.style.background = '#95a5a6'; text.textContent = 'Sync: offline'; }
    if (time) time.textContent = info ? ('last: ' + info) : '';
  }

  // Always show sync UI immediately on load so both connected and disconnected
  // clients see their status without needing to trigger a login param.
  createSyncStatus();

  const params = new URLSearchParams(window.location.search);
  const logParam = params.get('log');
  if (logParam === 'login') {
    // ensure sync status UI exists
    createSyncStatus();
    emitWhenSocketReady('log.event', { type: 'info', message: 'logged in', ts: Date.now(), origin: 'local' }, 2000, function(){
      ST_addLog("info","[ADMIN] <?php echo addslashes($_SESSION['username']); ?> logged in");
    });
    params.delete('log');
    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', newUrl);
  }

  // Listen for user login/logout logs from user_dashboard via localStorage events
  window.addEventListener('storage', function(e) {
    if (e.key === 'wave_user_log_event' && e.newValue) {
      try {
        const log = JSON.parse(e.newValue);
        if (log && log.type && log.message) {
          ST_addLog(log.type, log.message);
        }
      } catch (err) {}
    }
  });

  // Sync sensor toggles and vessel state across tabs (admin <-> user)
  window.addEventListener('storage', function(e) {
    try {
      // Sensor keys: st-sensor-<key>
      if (e.key && e.key.startsWith('st-sensor-')) {
        const key = e.key.replace('st-sensor-', '');
        const val = e.newValue === '1';
        const sw = document.getElementById('st-sw-' + key);
        const dot = document.getElementById('st-dot-' + key);
        if (sw) sw.checked = val;
        if (dot) dot.className = 'st-dot ' + (val ? 'st-on' : 'st-off');
      }

      // Vessel state sync
      if (e.key === 'vesselState') {
        const state = e.newValue || 'OFF';
        // Apply remote change but avoid creating a local log (server will emit canonical log)
        ST_setVesselState(state, false);
      }
    } catch (err) { /* ignore */ }
  });

  // --- Socket.IO client for LAN realtime (192.168.0.2:3000) ---
    try {
    const SOCKET_HOST = 'http://192.168.0.2:3000';
    const s = document.createElement('script');
    s.src = 'https://cdn.socket.io/4.7.2/socket.io.min.js';
  s.onload = function() {
      try {
        // Create an auth token derived from server-side secret (HMAC of username + role + ts)
        const socketAuth = (function(){
          const user = '<?php echo addslashes($_SESSION['username']); ?>';
          const role = 'ADMIN';
          // Use a single server-side timestamp that was used to compute the HMAC
          <?php $__socket_ts = time(); $__socket_hmac = hash_hmac('sha256', $_SESSION['username'] . "|ADMIN|" . $__socket_ts, WAVE_SOCKET_SECRET); ?>
          const ts = <?php echo $__socket_ts; ?>;
          // Server will validate token using same secret; token format: hmac::user::role::ts
          return '<?php echo $__socket_hmac; ?>::<?php echo addslashes($_SESSION['username']); ?>::ADMIN::' + ts;
        })();
  window.socket = io(SOCKET_HOST, { transports: ['websocket'], auth: { token: socketAuth } });
  window.socket.on('connect', () => { console.log('socket connected', window.socket.id); try { updateSyncStatus('connected', '<?php echo addslashes($_SESSION['username']); ?>'); } catch(e){} });
  window.socket.on('disconnect', () => { try { updateSyncStatus('disconnected'); } catch(e){} });

  // Announce presence to other clients when connected so they can show "active" state
  window.socket.on('connect', () => {
    try {
      const me = '<?php echo addslashes($_SESSION['username']); ?>';
      window.socket.emit('presence', { user: me, ts: Date.now(), origin: 'admin' });
    } catch(e) {}
  });

  // Announce current local state (sensors + vessel) on connect so other clients
  // can immediately sync their UI without requiring a manual refresh.
  window.socket.on('connect', () => {
    try {
      const me = '<?php echo addslashes($_SESSION['username']); ?>';
      const statePayload = { user: me, ts: Date.now(), origin: 'admin', vesselState: localStorage.getItem('vesselState') || 'OFF', sensors: {} };
      (ST_SENSOR_KEYS || []).forEach(k => { try { statePayload.sensors[k] = (localStorage.getItem('st-sensor-' + k) === '1'); } catch(e) { statePayload.sensors[k] = false; } });
      window.socket.emit('announce.state', statePayload);
    } catch(e) {}
  });

  // Show when other clients announce presence
  window.socket.on('presence', payload => {
    try {
      const me = '<?php echo addslashes($_SESSION['username']); ?>';
      if (payload && payload.user && payload.user !== me) {
        updateSyncStatus('active', payload.user + ' @ ' + new Date(payload.ts || Date.now()).toLocaleTimeString());
      }
    } catch(e) {}
  });

        // Apply state announces from other clients: update switches and vessel state
        window.socket.on('state.announce', payload => {
          try {
            const me = '<?php echo addslashes($_SESSION['username']); ?>';
            if (!payload || payload.user === me) return; // ignore our own announces
            // Apply sensors
            if (payload.sensors && typeof payload.sensors === 'object') {
              Object.keys(payload.sensors).forEach(k => {
                try {
                  const isOn = !!payload.sensors[k];
                  const sw = document.getElementById('st-sw-' + k);
                  const dot = document.getElementById('st-dot-' + k);
                  if (sw) sw.checked = isOn;
                  if (dot) dot.className = 'st-dot ' + (isOn ? 'st-on' : 'st-off');
                  try { localStorage.setItem('st-sensor-' + k, isOn ? '1' : '0'); } catch(e) {}
                } catch(e) {}
              });
            }
            // Apply vessel state but avoid emitting loops
            if (payload.vesselState) {
              try { ST_setVesselState(String(payload.vesselState || 'OFF'), false); localStorage.setItem('vesselState', String(payload.vesselState || 'OFF')); } catch(e) {}
            }
            // Update sync UI to indicate who triggered the state
            try { updateSyncStatus('active', payload.user + ' @ ' + new Date(payload.ts || Date.now()).toLocaleTimeString()); } catch(e) {}
          } catch(e) {}
        });

        const __WAVE_ADMIN_USER = '<?php echo addslashes($_SESSION['username']); ?>';
        window.socket.on('sensor.change', payload => {
          try {
            console.log('received sensor.change', payload);
            const key = payload.key;
            const isOn = !!payload.value;
            const sw = document.getElementById('st-sw-' + key);
            const dot = document.getElementById('st-dot-' + key);
            if (sw) sw.checked = isOn;
            if (dot) dot.className = 'st-dot ' + (isOn ? 'st-on' : 'st-off');
            try { localStorage.setItem('st-sensor-' + key, isOn ? '1' : '0'); } catch(e){}
            // UI-only update: logging is handled via server-emitted `log.event` to avoid duplicate/verbose messages
          } catch(e){}
        });

        window.socket.on('vessel.change', payload => {
          try {
            // Apply remote change but do not re-emit to avoid loops
            ST_setVesselState(payload.state, false);
            try { localStorage.setItem('vesselState', payload.state); } catch(e){}
            // Do not create a separate log here; server will emit a single `log.event` with the cleaned message
          } catch(e){}
        });

        window.socket.on('log.event', payload => {
          try {
            console.log('received log.event', payload);
            // Update sync status with last-received time
            try { updateSyncStatus('active', new Date().toLocaleTimeString()); } catch(e) {}
            // Always use the server-sent canonical message. The server normalizes
            // role and username and appends the "by <username>" suffix so the UI
            // remains consistent across devices.
            const msg = String(payload.message || payload.desc || '').trim();
            if (msg) ST_addLog(payload.type || 'info', msg, { noDb: true, timestamp: payload.ts ? new Date(payload.ts).toLocaleString() : undefined });
          } catch(e) {}
        });

          // Periodically update sync UI based on socket connectivity (handles auto-reconnect)
          setInterval(() => {
            try {
              if (window.socket && window.socket.connected) updateSyncStatus('connected');
              else updateSyncStatus('disconnected');
            } catch(e) {}
          }, 1500);

      } catch(e) { console.warn('socket init failed', e); }
    };
    document.head.appendChild(s);
  } catch(e) { console.warn('socket script error', e); }

  // Wrap admin toggles to emit events
  const origAdmin_ST_toggleSensor = window.ST_toggleSensor;
    window.ST_toggleSensor = function(input, sensor) {
    origAdmin_ST_toggleSensor(input, sensor);
    try {
      const isOn = !!input.checked;
      if (window.socket && window.socket.connected) {
        window.socket.emit('sensor.change', { key: sensor, value: isOn, user: '<?php echo addslashes($_SESSION['username']); ?>', role: 'ADMIN', ts: Date.now(), origin: 'local' });
      }
    } catch(e){}
  };

  const origAdmin_ST_togglePower = window.ST_togglePower;
  window.ST_togglePower = function() {
    origAdmin_ST_togglePower();
    // Do NOT emit here — ST_setVesselState handles emission after user confirms via SweetAlert.
  };

  // Poll server for new event_log rows (only when Notifications tab is active)
  let lastLogId = 0;
  function fetchLastLogIdFromLocal() {
    try {
      const logs = JSON.parse(localStorage.getItem('systemLogs') || '[]');
      if (logs.length === 0) return 0;
      // event_log.id isn't stored in systemLogs; store last id separately
      return parseInt(localStorage.getItem('systemLogs_last_id') || '0', 10) || 0;
    } catch(e) { return 0; }
  }
  function storeLastLogId(id) { localStorage.setItem('systemLogs_last_id', String(id)); }
  lastLogId = fetchLastLogIdFromLocal();

  async function pollServerLogs() {
    try {
      // Always poll server logs in background so admin receives entries even when
      // Notifications tab is not active (ensures sync when no realtime server).
      // Previously we only polled while Notifications tab was visible which caused
      // missed updates when actions came from other devices but no socket server.
      // const activeTab = document.querySelector('.nav-item.active')?.dataset.tab;
      // if (activeTab !== 'notifications') return; // only poll when notifications visible
      const resp = await fetch(window.location.pathname + '?api=logs&since_id=' + encodeURIComponent(lastLogId));
      if (!resp.ok) return;
      const data = await resp.json();
      if (!data.rows || !data.rows.length) return;
      // Merge rows into local logs (preserve ordering)
      const local = JSON.parse(localStorage.getItem('systemLogs') || '[]');
      // Server returns new rows in descending order (newest first)
      data.rows.forEach(r => {
  const ts = r.event_timestamp || new Date().toLocaleString();
  const message = r.event_desc || '';
        // Skip rows that were forwarded from sockets to avoid duplicate logs in the UI (they are already shown via socket)
        if (String(message).includes('[source:socket]')) {
          lastLogId = Math.max(lastLogId, parseInt(r.id,10) || lastLogId);
          return;
        }
        const logObj = { type: r.event_status ? r.event_status.toLowerCase() : 'info', timestamp: ts, message: message, category: r.event_status ? r.event_status.toLowerCase() : 'info' };
        // Prevent duplicates: check recent entries
        const dup = local.slice(0,40).some(l => l.message === logObj.message && l.timestamp === logObj.timestamp);
        if (!dup) {
          // Because server rows are newest-first, unshift keeps newest on top
          local.unshift(logObj);
          try { ST_addLog(logObj.type, logObj.message, { noDb: true, timestamp: ts }); } catch(e) {}
        }
        lastLogId = Math.max(lastLogId, parseInt(r.id,10) || lastLogId);
      });
      localStorage.setItem('systemLogs', JSON.stringify(local));
      storeLastLogId(lastLogId);
    } catch(e) {
      // silent
    }
  }
  // Poll every 3 seconds while on Notifications tab
  setInterval(pollServerLogs, 3000);
});
</script>
<!-- Periodic session validation to auto-show kick message when another device logs in -->
<script>
(function(){
  var sessionPollInterval = null;
  var kicked = false;
  function showKick(message){
    if (kicked) return; kicked = true;
    try {
      var overlay = document.createElement('div');
      overlay.id = 'kickOverlayAdmin';
      overlay.style.position = 'fixed';
      overlay.style.inset = '0';
      overlay.style.background = 'rgba(0,0,0,0.6)';
      overlay.style.display = 'flex';
      overlay.style.alignItems = 'center';
      overlay.style.justifyContent = 'center';
      overlay.style.zIndex = 99999;
      overlay.innerHTML = '<div style="background:#fff;padding:24px;border-radius:12px;max-width:720px;text-align:center;">'
        + '<h1 style="font-size:20px;margin:0 0 8px;color:#072f4a">Signed in elsewhere</h1>'
        + '<p style="margin:0 0 12px;font-size:16px;color:#0b2233">'+(message||'someone logged in using this account. you will be automatically log out')+'</p>'
        + '<p>You will be redirected to the login page in <span id="kickSecAdmin" style="font-weight:800;color:#0b3b5a">5</span>s.</p>'
        + '<p><a href="waveout.php">Log out now</a></p></div>';
      document.body.appendChild(overlay);
      var s = 5;
      var t = setInterval(function(){ s--; var el = document.getElementById('kickSecAdmin'); if (el) el.textContent = s; if (s<=0){ clearInterval(t); window.location.href='waveout.php'; } }, 1000);
      if (sessionPollInterval) clearInterval(sessionPollInterval);
    } catch(e) { window.location.href = 'waveout.php'; }
  }

  async function checkSessionOnce(){
    try {
      var res = await fetch('session_check.php', { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) return;
      var j = await res.json();
      if (j && j.valid === false) {
        showKick(j.message || 'someone logged in using this account. you will be automatically log out');
      }
    } catch(e) {}
  }

  sessionPollInterval = setInterval(checkSessionOnce, 3000);
  checkSessionOnce();
})();
</script>
<script>
// Run FA_Code_Generator_GUI.py via server endpoint
async function runFA(){
  const btn = document.getElementById('runFaBtn');
  try {
    if (btn) { btn.disabled = true; btn.textContent = 'Starting...'; }
    const resp = await fetch('run_fagui.php', { method: 'POST', credentials: 'same-origin' });
    if (!resp.ok) {
      const txt = await resp.text();
      throw new Error('Server error: ' + resp.status + ' ' + txt);
    }
    const j = await resp.json();
    if (j.success) {
      Swal.fire('Started', j.message || 'FA script started', 'success');
      try { ST_addLog('info', `[ADMIN] ${'<?php echo addslashes($_SESSION['username']); ?>'} started FA_Code_Generator_GUI.py`); } catch(e){}
    } else {
      Swal.fire('Error', j.message || 'Failed to start FA script', 'error');
    }
  } catch (err) {
    Swal.fire('Error', err.message || 'Failed to start FA script', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Generate 2FA Code'; }
  }
}

// Run WAVE_NetDiag.py as Administrator (best-effort elevation on Windows)
async function runNetDiag(){
  const btn = document.getElementById('runNetBtn');
  try {
    if (btn) { btn.disabled = true; btn.textContent = 'Starting...'; }
    const resp = await fetch('run_netdiag.php', { method: 'POST', credentials: 'same-origin' });
    if (!resp.ok) {
      const txt = await resp.text();
      throw new Error('Server error: ' + resp.status + ' ' + txt);
    }
    const j = await resp.json();
    if (j.success) {
      // Do not show a blocking SweetAlert on success; just log the action
      try { ST_addLog('info', `[ADMIN] ${'<?php echo addslashes($_SESSION['username']); ?>'} started WAVE_NetDiag.py`); } catch(e){}
    } else {
      Swal.fire('Error', j.message || 'Failed to start NetDiag', 'error');
    }
  } catch (err) {
    Swal.fire('Error', err.message || 'Failed to start NetDiag', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Run NetDiag (Admin)'; }
  }
}
</script>
</body>
</html>