<?php
$timestamp = date('Y-m-d h:i:s A');
// ──────────────── EVENT LOG AJAX HANDLER (Robust, Python-style) ────────────────
if (isset($_POST['log_to_event_log'])) {
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
  $user = $_SESSION['username'] ?? 'Unknown';
  $desc = $_POST['desc'] ?? '';
  $type = $_POST['status'] ?? '';
  $event_status = classifyLog($type, $desc);
  $result = log_notification($conn, $user, $desc, $event_status);
  header('Content-Type: application/json');
  echo json_encode($result);
  exit;
}

session_start();
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
/* ─────────────────────────────────────────────────────────────────────────────
   OPTIONAL SAME-ORIGIN PROXY API
   - Enables fetch from same origin to avoid CORS.
   - Frontend will call ?api=get first, then fallback to Flask host.
   ───────────────────────────────────────────────────────────────────────────── */
if (isset($_GET['api']) && $_GET['api'] === 'get') {
    // Adjust the Flask server IP/port here if needed:
    $flaskUrl = "http://192.168.1.3:5000/get";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $flaskUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch);
    $err = curl_errno($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    header('Content-Type: application/json');
    if ($err || $info['http_code'] !== 200 || !$res) {
        echo json_encode(["error" => "Failed to fetch from Flask", "code" => $info['http_code'] ?? 0]);
    } else {
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
<title>WAVE Dashboard</title>
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
.water-grid { display: grid; grid-template-columns: 2fr 3fr; gap: 12px; margin-bottom: 8px; }
.big-card.sensor-card { min-height: 160px; font-size: 1.05rem; }
.right-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: repeat(3, 1fr); gap: 10px; }
.sensor-card { background: linear-gradient(145deg, #7ed6f7, #5faee3); border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); color: #222; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 48px; font-size: 1rem; padding: 10px 8px; }
.sensor-card.wide { grid-column: span 2; }
.sensor-card h3 { margin: 0 0 6px 0; font-size: 1.02rem; font-weight: 700; }
.sensor-card p { margin: 0; font-size: 1.08rem; font-weight: 600; }
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
/* Live chart area */
.chart-container { height: 400px; margin-top: 20px; }
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
  border-radius: 16px; padding: 20px;
  box-shadow: 0 8px 18px rgba(0,0,0,0.3);
  text-align:center; color:#f1faff;
  transition: transform .2s ease, box-shadow .3s ease;
}
.st-card:hover { transform: translateY(-6px); box-shadow: 0 12px 25px rgba(0,150,200,0.5); }

/* 👉 Match sidebar icon style for cards’ icons */
.st-icon {
  display:inline-flex; align-items:center; justify-content:center;
  width:56px; height:56px;
  border-radius:12px;
  background: linear-gradient(180deg,#49d7ff,#1aa6ff);
  color:#fff; font-size:22px; box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 4px 10px rgba(0,0,0,.25);
  margin-bottom: 10px;
}

/* status dot */
.st-dot { display:inline-block; width:12px; height:12px; border-radius:50%; margin-right:6px; background:#bbb; vertical-align:middle; }
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
.st-powerOn { background: linear-gradient(135deg, #06d6a0, #1b9aaa); }
.st-powerOn:hover { background: linear-gradient(135deg, #04ad84, #15807c); }
.st-powerOff{ background: linear-gradient(135deg, #e63946, #d00000); }
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

/* Vessel status pill */
#st-vesselStatus { font-weight: bold; margin-top: 10px; padding: 8px 12px; border-radius: 8px; display: inline-block; }
.st-vessel-on  { background:#06d6a0; color:#fff; box-shadow:0 0 12px #06d6a0; }
.st-vessel-off { background:#e63946; color:#fff; box-shadow:0 0 8px #e63946; }

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
  height: 300px;
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
    <h2>Monitoring <span id="perfHint" class="badge-hint" style="display:none;">live updates paused</span></h2> 
    <div class="water-grid"> 
      <div class="big-card sensor-card" onclick="switchChart('WQI')"> 
        <h3>Water Quality Index</h3> 
        <p id="wqiValue" name="wqi_value">--</p> 
      </div> 
      <div class="right-grid"> 
        <div class="sensor-card" onclick="switchChart('DO')"><h3>Dissolved Oxygen (mg/L)</h3><p id="do">--</p></div> 
        <div class="sensor-card" onclick="switchChart('TURB')"><h3>Turbidity (NTU)</h3><p id="turbidity">--</p></div> 
        <div class="sensor-card" onclick="switchChart('AMMO')"><h3>Ammonia (mg/L)</h3><p id="ammonia">--</p></div> 
        <div class="sensor-card" onclick="switchChart('PH')"><h3>pH Level</h3><p id="ph_level">--</p></div> 
        <div class="sensor-card wide" onclick="switchChart('TEMP')"><h3>Water Temperature (°C)</h3><p id="temperature">--</p></div>
      </div> 
    </div> 

    <div class="card wide chart-container">
      <h3 id="chartTitle">WQI Live Chart</h3>
  <canvas id="liveChart" height="140" style="width:100%;max-width:100%;display:block;"></canvas>
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
  <iframe src="feeder.php" style="width:100%;height:80vh;border:none;"></iframe>
</div>

<!-- ──────────────────────────
     NOTIFICATIONS (FULL-WIDTH LOGS – no dropdown)
     ────────────────────────── -->
<div id="notificationsSection" class="section" style="<?php echo ($current_tab === 'notifications') ? '' : 'display:none;'; ?>">
  <h2>Notifications Logs</h2>
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
  <h2>System Tools</h2>

  <!-- Top-left Combo Box -->
  <div class="tools-toolbar">
    <!-- Dropdown removed - sensors and actions are unified into a single view -->
  </div>

  <!-- Full-width stage: one view at a time -->
  <div id="toolsStageSensors" class="tools-stage" style="display:block;">
    <div class="tool-actions" style="margin-bottom:15px;">
      <!-- 🔵 MERGED single toggle button -->
      <button class="st-btn st-diag st-pill" id="toggleAllBtn" onclick="ST_toggleAllSensorsToggle()">Turn ALL ON</button>
    </div>
    <!-- Vessel actions placed at the far right edge of the viewport (fixed) on wide screens -->
  <div class="top-right-actions" style="position:fixed; top:140px; right:18px; display:flex; gap:10px; align-items:center; z-index:9999;">
      <p id="st-vesselStatus" class="st-vessel-on" style="margin:0; padding:8px 12px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,0.08);">Vessel Status: ON</p>
      <button id="st-powerBtn" class="st-btn st-powerOff st-pill" onclick="ST_togglePower()">Shutdown Vessel</button>
    </div>

    <style>
      /* Ensure top-right actions are fixed on wide screens and static on narrow screens */
      @media (max-width: 900px) {
        #toolsSection .top-right-actions { position: static !important; margin: 0 0 14px 0; justify-content: flex-end; }
        #toolsSection .top-right-actions p, #toolsSection .top-right-actions button { font-size: 0.95rem; }
        #toolsStageSensors { padding-top: 56px; }
      }
      @media (min-width: 901px) {
        /* Fixed controls sit below the header (header height = 100px + small offset) */
  #toolsSection .top-right-actions { position: fixed !important; top: 140px !important; right: 18px !important; }
        #toolsStageSensors { padding-top: 6px; }
      }
    </style>
    <div class="st-grid">
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-vial"></i></div>
        <span class="st-dot st-off" id="st-dot-ph"></span>
        <p>PH LEVEL</p>
        <label class="st-switch"><input type="checkbox" id="st-sw-ph" onchange="ST_toggleSensor(this,'ph')"><span class="st-slider"></span></label>
      </div>
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-tint"></i></div>
        <span class="st-dot st-off" id="st-dot-turb"></span>
        <p>TURBIDITY</p>
        <label class="st-switch"><input type="checkbox" id="st-sw-turb" onchange="ST_toggleSensor(this,'turb')"><span class="st-slider"></span></label>
      </div>
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-thermometer-half"></i></div>
        <span class="st-dot st-off" id="st-dot-temp"></span>
        <p>TEMPERATURE</p>
        <label class="st-switch"><input type="checkbox" id="st-sw-temp" onchange="ST_toggleSensor(this,'temp')"><span class="st-slider"></span></label>
      </div>
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-flask"></i></div>
        <span class="st-dot st-off" id="st-dot-ammo"></span>
        <p>AMMONIA</p>
        <label class="st-switch"><input type="checkbox" id="st-sw-ammo" onchange="ST_toggleSensor(this,'ammo')"><span class="st-slider"></span></label>
      </div>
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-wind"></i></div>
        <span class="st-dot st-off" id="st-dot-do"></span>
        <p>DISSOLVED OXYGEN</p>
        <label class="st-switch"><input type="checkbox" id="st-sw-do" onchange="ST_toggleSensor(this,'do')"><span class="st-slider"></span></label>
      </div>
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-balance-scale"></i></div>
        <span class="st-dot st-off" id="st-dot-load1"></span>
        <p>LOADCELL 1</p>
        <label class="st-switch"><input type="checkbox" id="st-sw-load1" onchange="ST_toggleSensor(this,'load1')"><span class="st-slider"></span></label>
      </div>
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-balance-scale"></i></div>
        <span class="st-dot st-off" id="st-dot-load2"></span>
        <p>LOADCELL 2</p>
        <label class="st-switch"><input type="checkbox" id="st-sw-load2" onchange="ST_toggleSensor(this,'load2')"><span class="st-slider"></span></label>
      </div>
      <div class="st-card">
        <div class="st-icon"><i class="fas fa-satellite-dish"></i></div>
        <span class="st-dot st-off" id="st-dot-ultra"></span>
        <p>FEED LEVEL</p>
        <p>(ULTRA SONIC)</p>
        <label class="st-switch"><input type="checkbox" id="st-sw-ultra" onchange="ST_toggleSensor(this,'ultra')"><span class="st-slider"></span></label>
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

function startMonitoring() {
  if (pollTimer) return;
  const hint = document.getElementById('perfHint'); if (hint) hint.style.display = 'none';
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
  AMMO: { label: "Ammonia",     color: "purple", max: 20  },
  DO:   { label: "DO",          color: "blue",   max:     15  }
};

let activeSensor = "WQI";
let liveChart;
const maxPoints = 60;

const lastValueLabelPlugin = {
  id: 'lastValueLabel',
  afterDatasetsDraw(chart) {
    const { ctx } = chart;
    const ds = chart
    if (!ds || ds.data.length === 0) return;
    const meta = chart.getDatasetMeta(0);
    const lastIndex = ds.data.length - 1;
    const lastPoint = meta.data[lastIndex];
    if (!lastPoint) return;
    const value = ds.data[lastIndex];
    ctx.save();
    ctx.font = '12px Segoe UI, sans-serif';
    ctx.fillStyle = '#333';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'bottom';
    ctx.fillText(String(value), lastPoint.x + 6, lastPoint.y - 6);
    ctx.restore();
  }
};

const lastUpdatedPlugin = {
  id: 'lastUpdatedPlugin',
  afterDraw(chart) {
    const ctx = chart.ctx;
    const area = chart.chartArea;
    const txt = document.getElementById("lastUpdatedValue").textContent;
    if (!txt || txt === "--") return;
    ctx.save();
    ctx.font = '12px Segoe UI, sans-serif';
    ctx.fillStyle = '#444';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    ctx.fillText("Last updated: " + txt, area.left + 6, area.bottom + 4);
    ctx.restore();
  }
};

function setupChart(sensorKey){
  const ctx = document.getElementById('liveChart').getContext('2d');
  if (liveChart) liveChart.destroy();

  const conf = sensorConfig[sensorKey];
  document.getElementById('chartTitle').innerText = conf.label + ' Live Chart';

  liveChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [{
        label: conf.label,
        borderColor: conf.color,
        backgroundColor: 'transparent',
        data: [],
        fill: false,
        tension: 0.25,
        pointRadius: 2.5,
        pointHoverRadius: 6,
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 0 },
      interaction: { mode: 'nearest', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          enabled: true,
          callbacks: { label: function(ctx){ return `${conf.label}: ${ctx.parsed.y}`; } }
        }
      },
      scales: {
        x: { display: true, ticks: { maxRotation: 0, autoSkip: true } },
        y: { min: 0, max: conf.max, ticks: { stepSize: Math.max(1, Math.round(conf.max/5)) } }
      },
      layout: { padding: { bottom: 22 } }
    },
    plugins: [lastValueLabelPlugin, lastUpdatedPlugin]
  });
  chartReady = true;
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
const FLASK_BASE = "http://192.168.1.3:5000";
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
async function fetchData() {
  // If chart not ready or Monitoring tab not active, skip to reduce jank
  const active = document.querySelector(".nav-item.active")?.dataset.tab;
  if (active !== 'water' || !chartReady) return;

  try {
    const wrapper = await robustFetchJson();
    if (wrapper && typeof wrapper.message === 'object') {
      const s = wrapper.message;
      const data = {
        WQI:  safeNumber(s.WQI  ?? s.wqi),
        PH:   safeNumber(s.PH   ?? s.pH),
        TURB: safeNumber(s.TURB ?? s.turb),
        TEMP: safeNumber(s.TEMP ?? s.temp),
        AMMO: safeNumber(s.AMMO ?? s.ammo),
        DO:   safeNumber(s.DO   ?? s.do)
      };
      document.getElementById("wqiValue").textContent    = data.WQI;
      document.getElementById("ph_level").textContent    = data.PH;
      document.getElementById("turbidity").textContent   = data.TURB;
      document.getElementById("temperature").textContent = data.TEMP;
      document.getElementById("ammonia").textContent     = data.AMMO;
      document.getElementById("do").textContent          = data.DO;
      const raw = s.last_updated || s.updated || Date.now();
      document.getElementById("lastUpdatedValue").textContent = formatTimestamp(raw);

      const ds = liveChart.data.datasets[0];
      liveChart.data.labels.push('');
      ds.data.push(data[activeSensor]);
      if (ds.data.length > maxPoints) { ds.data.shift(); liveChart.data.labels.shift(); }
      const conf = sensorConfig[activeSensor];
      liveChart.options.scales.y.max = conf.max;
      liveChart.update('none');
    }
  } catch (err) { }
}

/* ──────────────────────────────────────────────────────────────────
   SYSTEM TOOLS — Combo Box switching + State + SweetAlert + PDF Export
   ────────────────────────────────────────────────────────────────── */

/* Unified view: sensors and vessel actions are shown together; dropdown removed */

/* Sensor toggle + persistence */
const ST_SENSOR_KEYS = ['ph','turb','temp','ammo','do','load1','load2','ultra'];
function ST_toggleSensor(input, sensor) {
  const dot = document.getElementById("st-dot-"+sensor);
  const key = "st-sensor-"+sensor;
  const isOn = !!input.checked;
  if (dot) dot.className = "st-dot " + (isOn ? "st-on" : "st-off");
  localStorage.setItem(key, isOn ? "1" : "0");
  ST_addLog("action", `${sensor.toUpperCase()} sensor turned ${isOn ? "ON" : "OFF"}`);
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
function ST_setVesselState(state) {
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
        ST_addLog("alert","Vessel shutdown initiated by Admin");
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
        ST_addLog("alert","Vessel powered ON by Admin");
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

function ST_addLog(type, message){
  const box = document.getElementById("st-logBox");
  if (!box) return; // only when Notification tab DOM exists
  const tr = document.createElement("tr");
  // Parse timestamp and message
  let timestamp = new Date().toLocaleString();
  let msg = message;
  if (message.startsWith('[USER]') || message.startsWith('[ADMIN]')) {
    const match = message.match(/^\[(USER|ADMIN)\]\s*(.*)$/);
    if (match) msg = match[0];
  } else {
    msg = '[ADMIN] ' + message;
  }
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

  // Send log to server for DB storage (robust, with debug)
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
  const keys=['ph','turb','temp','ammo','do','load1','load2','ultra'];
  keys.forEach(k=>{
    localStorage.setItem('st-sensor-'+k, state?'1':'0');
    const sw=document.getElementById('st-sw-'+k);
    const dot=document.getElementById('st-dot-'+k);
    if(sw) sw.checked=state;
    if(dot) dot.className="st-dot "+(state?"st-on":"st-off");
  });
  ST_addLog("action",`All sensors turned ${state?"ON":"OFF"}`);
}

/* 🔵 MERGED: Single toggle button handler with auto label */
function ST_allSensorsCurrentlyOn(){
  const keys=['ph','turb','temp','ammo','do','load1','load2','ultra'];
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
    ST_addLog("info","<?php echo addslashes($_SESSION['username']); ?> logged out");
    setTimeout(()=>{
      // give a tiny moment to flush localStorage writes
      setTimeout(()=>{ window.location.href='waveout.php'; }, 50);
    }, 500);
  } catch(e) {
    // fail-safe redirect
    window.location.href='waveout.php';
  }
}

/* On Load: restore Tools states + logs and set initial behaviors */
window.addEventListener('load', ()=>{
  ST_loadLogs();           // populate logs (Notifications tab)
  ST_loadSensorStates();   // restore sensor toggles

  // Initialize Toggle All button label based on current sensor states
  const toggleBtn = document.getElementById('toggleAllBtn');
  if (toggleBtn) toggleBtn.textContent = ST_allSensorsCurrentlyOn() ? "ALL OFF" : "ALL ON";

  const state = localStorage.getItem("vesselState") || "ON";
  ST_setVesselState(state);

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
  const params = new URLSearchParams(window.location.search);
  const logParam = params.get('log');
  if (logParam === 'login') {
    ST_addLog("info","[ADMIN] <?php echo addslashes($_SESSION['username']); ?> logged in");
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
      const activeTab = document.querySelector('.nav-item.active')?.dataset.tab;
      if (activeTab !== 'notifications') return; // only poll when notifications visible
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
        const logObj = { type: r.event_status ? r.event_status.toLowerCase() : 'info', timestamp: ts, message: message, category: r.event_status ? r.event_status.toLowerCase() : 'info' };
        // Prevent duplicates: check recent entries
        const dup = local.slice(0,40).some(l => l.message === logObj.message && l.timestamp === logObj.timestamp);
        if (!dup) {
          // Because server rows are newest-first, unshift keeps newest on top
          local.unshift(logObj);
          try { ST_addLog(logObj.type, logObj.message); } catch(e) {}
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
</body>
</html>