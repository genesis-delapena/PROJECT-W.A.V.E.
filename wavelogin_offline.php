<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
include 'wavedb.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Use null-coalescing to avoid notices if fields are missing
  $username = (string) trim($_POST["username"] ?? '');
  $password = (string) trim($_POST["password"] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{4,10}$/', $username)) {
        $_SESSION["error"] = "Invalid username format.";
        header("Location: wavelogin_offline.php");
        exit;
    }
    if (strlen($password) < 6 || strlen($password) > 12) {
        $_SESSION["error"] = "Password must be 6–12 characters.";
        header("Location: wavelogin_offline.php");
        exit;
    }

  $stmt = $conn->prepare("SELECT MG_UName, MG_PWD, LAR_level, MG_Email, USB_OFA, USB_FATS FROM auth_table WHERE BINARY MG_UName=?");
  $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
  $stmt->bind_result($dbUser, $dbPass, $dbLevel, $dbEmail, $dbUsbOfa, $dbUsbFats);

    if ($stmt->num_rows > 0) {
        $stmt->fetch();
  if (password_verify($password, (string)$dbPass)) {
      // Validate USB authentication code (input name: usb_ofa)
      $usb_input = isset($_POST['usb_ofa']) ? trim($_POST['usb_ofa']) : '';

      // If the DB has an expiry timestamp, check expiry first
      if (!empty($dbUsbFats) && strtotime($dbUsbFats) < time()) {
        // exact message requested
        $_SESSION["error"] = "the authentication code is expired";
        $stmt->close();
        header("Location: wavelogin_offline.php");
        exit;
      }

      // Require the input to match stored USB_OFA (treat empty DB value as mismatch)
      if (empty($dbUsbOfa) || $usb_input !== $dbUsbOfa) {
        $_SESSION["error"] = "Invalid authentication code.";
        $stmt->close();
        header("Location: wavelogin_offline.php");
        exit;
      }
      // Create a role-specific session cookie (mirror online flow)
      // Close the temporary login session first
      unset($_SESSION["error"]);
      session_write_close();

      // Choose session name per role: 2 = admin, 1 = user
      if (intval($dbLevel) === 2) {
        session_name('WAVE_ADMIN');
      } else {
        session_name('WAVE_USER');
      }
      session_start();
      session_regenerate_id(true);

      // Populate the role session with the values dashboards expect
      $_SESSION["username"]   = $dbUser;
      $_SESSION["LAR_level"]  = intval($dbLevel);
      $_SESSION["user_name"]  = $dbUser;
      $_SESSION["user_role"]  = intval($dbLevel) === 2 ? 'admin' : 'user';
      $_SESSION["user_email"] = $dbEmail ?: $dbUser . "@offline.local";
      $_SESSION["offline_mode"] = true;

      // Generate and persist a per-session token to enforce single active session (best-effort)
      try {
        if (empty($_SESSION['session_token'])) {
          $_SESSION['session_token'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['session_token'];
        // Ensure the active_sessions table exists
        $createSql = "CREATE TABLE IF NOT EXISTS active_sessions (
          username VARCHAR(100) NOT NULL PRIMARY KEY,
          session_token VARCHAR(128) NOT NULL,
          last_seen DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($createSql);
        $upsert = $conn->prepare("INSERT INTO active_sessions (username, session_token, last_seen) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE session_token=VALUES(session_token), last_seen=NOW()");
        if ($upsert) {
          $upsert->bind_param('ss', $dbUser, $token);
          $upsert->execute();
          $upsert->close();
        }
      } catch (Exception $e) {
        error_log('Offline session token persist error: ' . $e->getMessage());
      }

      // Redirect to dashboards according to role (user -> user_dashboard)
      if (intval($dbLevel) === 2) {
        header("Location: ad_dashboard.php?log=login&tab=water");
      } elseif (intval($dbLevel) === 1) {
        header("Location: user_dashboard.php?tab=water&log=login");
      } else {
        // fallback: go back to offline login with error
        $_SESSION = array();
        session_write_close();
        // restart fresh default session to show error
        session_start();
        $_SESSION["error"] = "Invalid access level.";
        header("Location: wavelogin_offline.php");
      }
      exit;

        } else {
            $_SESSION["error"] = "Incorrect password.";
        }
    } else {
        $_SESSION["error"] = "User not found.";
    }

    $stmt->close();
    header("Location: wavelogin_offline.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>W.A.V.E Offline Login</title>
  <link rel="stylesheet" href="wave.css">
  <link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Righteous&family=Varela+Round&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="wave_logo2.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    /* Place eye icon inside the password input on the right */
    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
      width: 100%;
    }
    .password-wrapper input {
      flex: 1;
      padding-right: 40px; /* room for the eye icon */
    }
    .password-wrapper i {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 18px;
      cursor: pointer;
      color: #555;
      user-select: none;
    }
    /* Hide MS reveal button on Edge/IE inside password fields */
    input[type="password"]::-ms-reveal, input[type="password"]::-ms-clear { display: none; }
  </style>
</head>
<body>
  <main class="login-container">
    <div class="icon-wrap">
      <img src="wave_logo2.png" class="boat-icon" height="100" width="100">
    </div>

    <h2>W.A.V.E. (Offline Mode)</h2>

    <form method="POST" autocomplete="off">
      <div class="input-group">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" maxlength="10" required>
      </div>

      <div class="input-group">
        <label for="password">Password</label>
        <div class="password-wrapper">
          <input id="password" name="password" type="password" minlength="6" maxlength="12" required>
          <i class="fas fa-eye" onclick="togglePassword('password', this)"></i>
        </div>
      </div>

      <div class="input-group">
        <label for="usb_ofa">Authentication Code</label>
        <input id="usb_ofa" name="usb_ofa" type="text" maxlength="6" required>
      </div>

      <button type="submit" class="loginbutton">Login</button>
    </form>

    <?php if (!empty($_SESSION["error"])) : ?>
      <p style="color:red; margin-top:10px;"><?php echo htmlspecialchars($_SESSION["error"]); ?></p>
      <?php unset($_SESSION["error"]); ?>
    <?php endif; ?>

    <p class="note">Offline Access – W.A.V.E System</p>
  </main>

  <script>
    function togglePassword(id, icon) {
      const input = document.getElementById(id);
      input.type = input.type === "password" ? "text" : "password";
      icon.classList.toggle("fa-eye");
      icon.classList.toggle("fa-eye-slash");
    }
  </script>
</body>
</html>