
<?php

session_start();
include 'wavedb.php';

require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION["pending_user"])) {
    header("Location: wavelogin.php");
    exit;
}


$user  = $_SESSION["pending_user"];
$level = $_SESSION["pending_level"] ?? 1;
$error = "";
$msg   = "";

$maskedEmail = '';
$stmt = $conn->prepare("SELECT MG_Email FROM auth_table WHERE MG_UName=?");
$stmt->bind_param("s", $user);
$stmt->execute();
$stmt->bind_result($dbEmailDisplay);
$stmt->fetch();
$stmt->close();
if (!empty($dbEmailDisplay)) {
  $atPos = strpos($dbEmailDisplay, '@');
  if ($atPos !== false) {
    $name = substr($dbEmailDisplay, 0, $atPos);
    $domain = substr($dbEmailDisplay, $atPos);
    if (strlen($name) > 4) {
      $maskedEmail = substr($name, 0, 2) . str_repeat('*', strlen($name)-4) . substr($name, -2) . $domain;
    } elseif (strlen($name) === 4) {
      $maskedEmail = substr($name, 0, 2) . substr($name, -2) . $domain;
    } elseif (strlen($name) === 3) {
      $maskedEmail = substr($name, 0, 1) . '*' . substr($name, -2) . $domain;
    } elseif (strlen($name) === 2) {
      $maskedEmail = $name . $domain;
    } elseif (strlen($name) === 1) {
      $maskedEmail = $name . $domain;
    } else {
      $maskedEmail = $dbEmailDisplay;
    }
  } else {
    $maskedEmail = $dbEmailDisplay;
  }
}

// debug removed

/** ───────────────── Resend cooldown (persistent) ─────────────────
 * First time on this page (after login OTP): 60s
 * First RESEND: 180s (3 min)
 * Second+ RESEND: 300s (5 min)
 */
if (!isset($_SESSION['resend_until'])) {
    $_SESSION['resend_until'] = time() + 60;
}
if (!isset($_SESSION['resend_count'])) {
    $_SESSION['resend_count'] = 0;
}
$now       = time();
$remaining = max(0, $_SESSION['resend_until'] - $now);

/* ───────────── Back ───────────── */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["back"])) {
    unset($_SESSION["pending_user"], $_SESSION["pending_level"], $_SESSION['resend_until'], $_SESSION['resend_count']);
    session_regenerate_id(true);
    header("Location: wavelogin.php");
    exit;
}

/* ───────────── Verify ───────────── */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["verify"])) {
    $otp = trim($_POST["otp"]);

  // Fetch the latest OTP for this user (include id so we can mark it used)
  $stmt = $conn->prepare("SELECT id, otp_code, expiry FROM admin_otps WHERE username=? ORDER BY created_at DESC LIMIT 1");
  $stmt->bind_param("s", $user);
  $stmt->execute();
  $stmt->bind_result($dbId, $dbOtp, $dbExpiry);
  $stmt->fetch();
  $stmt->close();

    if ($otp === $dbOtp && strtotime($dbExpiry) > time()) {
  // Mark the used OTP as expired (do not delete row so history remains)
  $clear = $conn->prepare("UPDATE admin_otps SET expiry=? WHERE id=?");
  $now = date("Y-m-d H:i:s");
  $clear->bind_param("si", $now, $dbId);
  $clear->execute();
  $clear->close();

    // We started this script with the default session to read pending_user/pending_level.
    // To create a role-specific session cookie we must close the current session, delete
    // the old session cookie, then start a new session with a different session_name.
    // Close the temporary (login) session but do NOT delete other session cookies
    // This avoids removing unrelated session cookies (e.g., admin sessions in other tabs)
    unset($_SESSION['resend_until'], $_SESSION['resend_count']);
    // close current session (keeps its cookie intact)
    session_write_close();

    // Start a new session with role-specific name (this will create a separate session cookie)
    if ($level == 2) {
      session_name('WAVE_ADMIN');
    } else {
      session_name('WAVE_USER');
    }
    session_start();
    session_regenerate_id(true);
    $_SESSION["username"]  = $user;
    $_SESSION["LAR_level"] = $level;
    // Generate a strong per-session token and persist it to DB to enforce single active session.
    try {
      if (empty($_SESSION['session_token'])) {
        $_SESSION['session_token'] = bin2hex(random_bytes(32));
      }
      $token = $_SESSION['session_token'];
      // Ensure the active_sessions table exists (safe to run repeatedly)
      $createSql = "CREATE TABLE IF NOT EXISTS active_sessions (
        username VARCHAR(100) NOT NULL PRIMARY KEY,
        session_token VARCHAR(128) NOT NULL,
        last_seen DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
      $conn->query($createSql);

      $upsert = $conn->prepare("INSERT INTO active_sessions (username, session_token, last_seen) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE session_token=VALUES(session_token), last_seen=NOW()");
      if ($upsert) {
        $upsert->bind_param('ss', $user, $token);
        $upsert->execute();
        $upsert->close();
      }
    } catch (Exception $e) {
      // Non-fatal: allow login to continue but log error server-side
      error_log('Session token persist error: ' . $e->getMessage());
    }
    // remove any temporary pending values if present in this new session
    unset($_SESSION["pending_user"], $_SESSION["pending_level"]);

    if ($level == 2) {
      header("Location: ad_dashboard.php?log=login&tab=water");
    } else {
      // Append ?log=login so admin dashboard (if open) can register the user login via URL hook
      header("Location: user_dashboard.php?tab=water&log=login");
    }
    exit;
    } else {
        $error = "Invalid or expired OTP.";
        // cooldown intentionally not reset
    }
}

/* ───────────── Resend ───────────── */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["resend"])) {
    if (time() < ($_SESSION['resend_until'] ?? 0)) {
        $wait  = $_SESSION['resend_until'] - time();
        $error = "Please wait {$wait}s before resending.";
    } else {
        // Fetch the user's email
        $stmt = $conn->prepare("SELECT MG_Email FROM auth_table WHERE MG_UName=?");
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $stmt->bind_result($dbEmail);
        $stmt->fetch();
        $stmt->close();

        if (!empty($dbEmail)) {
            // New OTP
            $otp    = random_int(100000, 999999);
            $expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

      // Insert a new OTP row so every resend creates a history entry
      $insert = $conn->prepare("INSERT INTO admin_otps (username, otp_code, expiry) VALUES (?, ?, ?)");
      $insert->bind_param("sss", $user, $otp, $expiry);
      $insert->execute();
      if ($insert->errno) {
        error_log("OTP insert error (otp_verify): " . $insert->error);
        $error = "Internal error creating OTP. Contact administrator.";
      }
      $insert->close();

            // System SMTP creds
            $res = $conn->query("SELECT smtp_email, smtp_pass FROM system_email LIMIT 1");
            if ($res && $res->num_rows > 0) {
                $sysEmail = $res->fetch_assoc();
                $smtpUser = $sysEmail['smtp_email'];
                $smtpPass = $sysEmail['smtp_pass'];
            } else {
                $error = "System email not configured.";
            }

            if (empty($error)) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $smtpUser;
                    $mail->Password   = $smtpPass;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom($smtpUser, 'W.A.V.E System');
                    $mail->addAddress($dbEmail, $user);
                    $mail->isHTML(true);
                    $mail->Subject = 'Your New OTP Code';
                    $mail->Body    = "
                      <p>Hello <b>{$user}</b>,</p>
                      <p>Your new OTP code is:</p>
                      <p style='font-size:22px;letter-spacing:3px'><b>{$otp}</b></p>
                      <p>This code will expire in <b>5 minutes</b>.</p>
                    ";

                    $mail->send();
                    $msg = "A new OTP has been sent to your email.";

                    // Progressive cooldown update
                    if ($_SESSION['resend_count'] === 0) {
                        $_SESSION['resend_until'] = time() + 180; // 3 minutes
                    } else {
                        $_SESSION['resend_until'] = time() + 300; // 5 minutes
                    }
                    $_SESSION['resend_count'] = min($_SESSION['resend_count'] + 1, 99);
                    $remaining = $_SESSION['resend_until'] - time();
                } catch (Exception $e) {
                    $error = "Failed to resend OTP: " . $mail->ErrorInfo;
                }
            }
        } else {
            $error = "No email address found for this account.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>OTP Verification</title>
  <link rel="stylesheet" href="wave.css?v=17">
  <link rel="icon" type="image/png" href="wave_logo2.png">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body {
      font-family: 'Varela Round', Arial, sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      margin: 0;
      color: #fff;
    }
    .otp-box {
      background: rgba(255, 255, 255, 0.15);
      padding: 40px 30px;
      border-radius: 20px;
      backdrop-filter: blur(12px);
      text-align: center;
      max-width: 480px;
      width: 100%;
      box-shadow: 0 8px 32px rgba(0,0,0,0.25);
    }
    .otp-box h2 { font-size: 1.8rem; margin-bottom: 10px; text-transform: uppercase; }
    .otp-box p.subtitle { font-size: 0.95rem; margin-bottom: 20px; opacity: 0.9; }

    /* Layout: inputs and resend inline but in separate forms (no nesting) */
    .row-inline {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    /* OTP inputs (form #otpForm) */
    #otpForm .otp-inputs { display: flex; gap: 8px; }
    #otpForm .otp-inputs input {
      width: 48px; height: 56px;
      font-size: 20px; font-weight: bold; text-align: center;
      border-radius: 8px; border: none; outline: none;
      background: rgba(255,255,255,0.92); color: #333;
      box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    }
    #otpForm .otp-inputs input:focus {
      border: 2px solid #0288d1;
      box-shadow: 0 0 6px rgba(0,180,216,0.7);
    }

    /* Resend form (separate) */
    .resend-form { display: flex; align-items: center; }
    .resend-btn-inline {
      padding: 10px 14px;
      border: none;
      border-radius: 8px;
      background: #43a047;
      color: #fff;
      font-size: 14px;
      font-weight: bold;
      cursor: pointer;
      transition: background 0.3s ease, transform 0.2s ease;
    }
    .resend-btn-inline:hover { background: #2e7d32; transform: scale(1.05); }
    .resend-btn-inline:disabled { background: #777; cursor: not-allowed; }

    /* Verify + Back buttons (clean, professional) */
    .verify-btn, .back-btn {
      display: block;
      width: 220px;
      max-width: 90vw;
      padding: 14px 0;
      border: none;
      border-radius: 10px;
      font-size: 16px;
      font-weight: bold;
      cursor: pointer;
      margin: 0 auto 12px auto;
      transition: all 0.3s ease;
      color: #fff;
      text-align: center;
    }
    .verify-btn {
      background: linear-gradient(135deg, #0288d1, #26c6da);
      box-shadow: 0 4px 12px rgba(2,136,209,0.4);
    }
    .verify-btn:hover {
      background: linear-gradient(135deg, #0277bd, #00acc1);
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(2,136,209,0.6);
    }
    .back-btn {
      background: linear-gradient(135deg, #e53935, #e57373);
      box-shadow: 0 4px 12px rgba(229,57,53,0.4);
    }
    .back-btn:hover {
      background: linear-gradient(135deg, #c62828, #ef5350);
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(229,57,53,0.6);
    }

    .cooldown { font-size: 0.85rem; color: #ffeb3b; margin: 6px 0 0; }
    .msg { color: #80e27e; margin-top: 10px; font-size: 0.9rem; }
    .error { color: #ff6b6b; margin-top: 10px; font-size: 0.9rem; }
  </style>
</head>
<body>
  <div class="otp-box">
    <h2>OTP Verification</h2>
    <p class="subtitle">Enter the 6-digit code we sent to your email<br>
    <span style="font-size:1.05em;color:#111;font-weight:600;letter-spacing:0.5px;">
      <?= htmlspecialchars($maskedEmail) ?>
    </span>
    </p>

    <!-- Inline row with two separate forms (no nesting) -->
    <div class="row-inline">
      <!-- OTP FORM (only inputs + hidden field) -->
      <form method="POST" id="otpForm" autocomplete="off" novalidate>
        <div class="otp-inputs">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" required autofocus>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
        </div>
    
        <input type="hidden" name="otp" id="otpHidden">
      </form>

      <!-- RESEND FORM (separate so the button is tappable) -->
      <form method="POST" class="resend-form">
        <button type="submit" name="resend" class="resend-btn-inline" id="resendBtn" <?= $remaining > 0 ? 'disabled' : '' ?>>↻ Resend</button>
      </form>
    </div>

    <!-- Verify button submits otpForm using the "form" attribute -->
    <button type="submit" name="verify" class="verify-btn" form="otpForm">Verify OTP</button>

    <!-- Back button -->
    <form method="POST" id="backForm">
      <input type="hidden" name="back" value="1">
      <button type="button" class="back-btn" id="backBtn">⬅ Back to Login</button>
    </form>

    <!-- Cooldown -->
    <p id="cooldownText" class="cooldown" data-remaining="<?= (int)$remaining ?>" style="<?= $remaining > 0 ? '' : 'display:none;' ?>">
      Resend available in <?= (int)$remaining ?>s
    </p>

    <?php if ($msg): ?><p class="msg"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  </div>

  <script>
    // Join 6 inputs into hidden field on submit
    const inputs = document.querySelectorAll("#otpForm .otp-inputs input");
    const otpHidden = document.getElementById("otpHidden");

    inputs.forEach((input, index) => {
      input.addEventListener("input", () => {
        if (!/^[0-9]$/.test(input.value)) { input.value = ""; return; }
        if (input.value && index < inputs.length - 1) inputs[index + 1].focus();
      });
      input.addEventListener("keydown", (e) => {
        if (e.key === "Backspace" && !input.value && index > 0) inputs[index - 1].focus();
      });
    });

    document.getElementById("otpForm").addEventListener("submit", () => {
      otpHidden.value = Array.from(inputs).map(i => i.value).join("");
    });

    // Cooldown timer persists (server gives remaining seconds)
    const cd = document.getElementById("cooldownText");
    const resendBtn = document.getElementById("resendBtn");
    let remaining = parseInt(cd.getAttribute("data-remaining") || "0", 10);
    if (remaining > 0) {
      const timer = setInterval(() => {
        remaining--;
        cd.textContent = `Resend available in ${remaining}s`;
        if (remaining <= 0) {
          clearInterval(timer);
          cd.style.display = "none";
          resendBtn.disabled = false;
        }
      }, 1000);
    }

    // Back confirm
    document.getElementById("backBtn").addEventListener("click", () => {
      Swal.fire({
        title: 'Are you sure?',
        text: "Your OTP session will be cleared and you must login again.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, go back',
        cancelButtonText: 'Cancel'
      }).then(res => { if (res.isConfirmed) document.getElementById("backForm").submit(); });
    });
  </script>
</body>
</html>
