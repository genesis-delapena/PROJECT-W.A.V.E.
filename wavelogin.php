
<?php
session_start();

// Prevent browser caching of login page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
include 'wavedb.php';

// ── PHPMailer via Composer ───────────────────────────────────────────────
require __DIR__ . '/vendor/autoload.php';
// ────────────────────────────────────────────────────────────────────────

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // --- Invisible reCAPTCHA verification ---
  $token = $_POST['g-recaptcha-response'] ?? '';

  // Skip reCAPTCHA for local/IP development (localhost, 127.0.0.1, or direct IP hostnames)
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $remote = $_SERVER['REMOTE_ADDR'] ?? '';
  $isLocal = (strpos($host, 'localhost') !== false) || filter_var($host, FILTER_VALIDATE_IP) || in_array($remote, ['127.0.0.1', '::1']);
  if ($isLocal) {
    $result = ["success" => true];
  } else {
    $secretKey = "6LcIlusrAAAAAOeKfRP3d1085AHRKK_Lae7pUkFW";
    $response = @file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$secretKey&response=$token");
    $result   = $response ? json_decode($response, true) : ["success" => false];
  }

  if (empty($result["success"])) {
      $_SESSION["error"] = "CAPTCHA verification failed.";
      header("Location: wavelogin.php");
      exit;
  }

  // Username/password validation
  $username = trim($_POST["username"]);
  $password = trim($_POST["password"]);

  if (strlen($username) < 4 || strlen($username) > 10 || !preg_match('/^[A-Za-z0-9_]+$/', $username)) {
      $_SESSION["error"] = "Username must be 4–10 characters (letters, numbers, underscores only).";
      header("Location: wavelogin.php");
      exit;
  }
  if (strlen($password) < 6 || strlen($password) > 12) {
      $_SESSION["error"] = "Password must be 6–12 characters.";
      header("Location: wavelogin.php");
      exit;
  }

  //Case-sensitive username check
  $stmt = $conn->prepare("SELECT MG_UName, MG_PWD, LAR_level, MG_Email 
                          FROM auth_table 
                          WHERE BINARY MG_UName=?");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $stmt->store_result();
  $stmt->bind_result($dbUser, $dbPass, $dbLevel, $dbEmail);

  if ($stmt->num_rows > 0) {
      $stmt->fetch();

      if (password_verify($password, $dbPass)) {
          if (empty($dbEmail)) {
              $_SESSION["error"] = "Account does not have an email configured for OTP.";
              header("Location: wavelogin.php");
              exit;
          }

          // Generate OTP
          $otp    = random_int(100000, 999999);
          $expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

      // Store OTP (insert-only so we keep a history row per request)
      $insert = $conn->prepare("INSERT INTO admin_otps (username, otp_code, expiry) VALUES (?, ?, ?)");
      $insert->bind_param("sss", $dbUser, $otp, $expiry);
      $insert->execute();
      if ($insert->errno) {
        // If insertion fails because of a UNIQUE index, the migration must be applied.
        error_log("OTP insert error (wavelogin): " . $insert->error);
        $_SESSION["error"] = "Internal error creating OTP. Contact administrator.";
        $insert->close();
        header("Location: wavelogin.php");
        exit;
      }
      $insert->close();

          // Fetch system Gmail credentials
          $res = $conn->query("SELECT smtp_email, smtp_pass FROM system_email LIMIT 1");
          if ($res && $res->num_rows > 0) {
              $sysEmail = $res->fetch_assoc();
              $smtpUser = $sysEmail['smtp_email'];
              $smtpPass = $sysEmail['smtp_pass'];
          } else {
              $_SESSION["error"] = "System email not configured. Contact admin.";
              header("Location: wavelogin.php");
              exit;
          }

          // Send OTP email
          $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
          try {
              $mail->isSMTP();
              $mail->Host       = 'smtp.gmail.com';
              $mail->SMTPAuth   = true;
              $mail->Username   = $smtpUser;
              $mail->Password   = $smtpPass;
              $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
              $mail->Port       = 587;

              $mail->setFrom($smtpUser, 'W.A.V.E System');
              $mail->addAddress($dbEmail, $dbUser);
              $mail->isHTML(true);
              $mail->Subject = 'Your W.A.V.E OTP Code';
              $mail->Body    = "
                <div style='font-family:Arial,sans-serif;font-size:14px;color:#333'>
                  <p>Hello <b>{$dbUser}</b>,</p>
                  <p>Your OTP code is:</p>
                  <p style='font-size:22px;letter-spacing:3px'><b>{$otp}</b></p>
                  <p>This code will expire in <b>5 minutes</b>. If this is not you, please ignore this message.</p>
                </div>";

              $mail->send();
          } catch (\PHPMailer\PHPMailer\Exception $e) {
              $_SESSION["error"] = "Could not send OTP: " . $mail->ErrorInfo;
              header("Location: wavelogin.php");
              exit;
          }

          // Keep pending identity for OTP step
          $_SESSION["pending_user"]  = $dbUser;
          $_SESSION["pending_level"] = $dbLevel;

          header("Location: otp_verify.php");
          exit;
      } else {
          $_SESSION["error"] = "Invalid password.";
          header("Location: wavelogin.php");
          exit;
      }
  } else {
      $_SESSION["error"] = "User not found.";
      header("Location: wavelogin.php");
      exit;
  }

  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>W.A.V.E Login</title>
  <link rel="stylesheet" href="wave.css">
  <link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Righteous&family=Varela+Round&display=swap" rel="stylesheet">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <link rel="icon" type="image/png" href="wave_logo2.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    .grecaptcha-badge { visibility: hidden !important; }
    .loginbutton {
      all: unset !important;
      display: inline-block !important;
      min-width: 90px !important;
      max-width: 160px !important;
      padding: 8px 18px !important;
      margin-top: 15px !important;
      text-align: center !important;
      font-size: 15px !important;
      font-weight: 600 !important;
      text-transform: uppercase !important;
      border-radius: 8px !important;
      border: none !important;
      background: linear-gradient(90deg, #00b4db, #0083b0) !important;
      color: #fff !important;
      cursor: pointer !important;
      box-shadow: 0 2px 6px rgba(0,0,0,0.18) !important;
      transition: background 0.3s ease, transform 0.2s ease, box-shadow 0.3s ease !important;
    }
    .loginbutton:hover {
      background: linear-gradient(90deg, #0083b0, #00b4db) !important;
      transform: scale(1.02) !important;
      box-shadow: 0 4px 10px rgba(0,0,0,0.22) !important;
    }
    .loginbutton:active { transform: scale(0.98) !important; }

    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
      width: 100%;
    }
    .password-wrapper input {
      flex: 1;
      padding-right: 40px;
    }
    .password-wrapper i {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 18px;
      cursor: pointer;
      color: #555;
    }
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear { display: none; }
    input[type="password"]::-webkit-credentials-auto-fill-button {
      display: none !important; visibility: hidden;
    }

    input[type="text"], input[type="password"], input[type="email"] {
      background-color: #f0f0f0;
      border: 1px solid #ccc;
      border-radius: 6px;
      padding: 10px;
      font-size: 16px;
      color: #000;
    }
    input::placeholder { color: #777; }

/* === Glassy inputs (idle state) === */
.input-group input {
  width: 100%;
  padding: 14px 16px;
  background: rgba(255, 255, 255, 0.15); /* more glassy */
  border: 1px solid rgba(255, 255, 255, 0.25);
  border-radius: 12px;
  outline: none;
  color: #e0f7fa;
  font-size: 0.95rem;
  box-sizing: border-box;
  text-align: center;
  transition: all 0.3s ease;
  text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

/* === Placeholder in idle mode === */
.input-group input::placeholder {
  color: rgba(224, 247, 250, 0.65);
  text-shadow: none;
}

/* === Focused state (clicked) === */
.input-group input:focus {
  background: rgba(255, 255, 255, 0.9);  /* solid glass -> white */
  color: black;                        /* text turns white */
  border-color: #00b4d8;
  box-shadow: 0 0 14px rgba(0, 180, 216, 0.8);
}

  </style>
</head>
<body>
  <main class="login-container">
    <div class="icon-wrap" aria-hidden="true">
      <img src="wave_logo2.png" class="boat-icon" height="100" width="100">
      <div class="boat-shadow"></div>
    </div>

    <h2>W.A.V.E.</h2>

    <form method="POST" autocomplete="off" novalidate id="loginForm">
      <div class="input-group">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" 
               maxlength="10" pattern="^[A-Za-z0-9_]{4,10}$" 
               title="Username must be 4–10 characters (letters, numbers, underscores only)" 
               required />
      </div>

      <div class="input-group">
        <label for="password">Password</label>
        <div class="password-wrapper">
          <input id="password" name="password" type="password" 
                 minlength="6" maxlength="12" 
                 title="Password must be 6–12 characters" 
                 required />
          <i class="fas fa-eye" onclick="togglePassword('password', this)"></i>
        </div>
      </div>

      <button 
        class="loginbutton g-recaptcha" 
        data-sitekey="6LcIlusrAAAAAOCcvufP-dYuBifpqYuFUfmKf-JY" 
        data-callback="onSubmit" 
        data-action="login">
        Login
      </button>
    </form>

    <?php if (!empty($_SESSION["error"])) : ?>
     <p style="color:red; margin-top:10px;"><?php echo htmlspecialchars($_SESSION["error"]); ?></p>
     <?php unset($_SESSION["error"]); ?>
    <?php endif; ?>

    <p class="note">Water-Based Automated Vessel for Efficient Feeding & Environmental Monitoring</p>

    <div class="recaptcha-disclosure">
      This site is protected by reCAPTCHA and the Google 
      <a href="https://policies.google.com/privacy" target="_blank" class="recaptcha-white">Privacy Policy</a> and 
      <a href="https://policies.google.com/terms" target="_blank" class="recaptcha-white">Terms of Service</a> apply.
    </div>
  </main>

  <script>
  function onSubmit(token) {
    document.getElementById("loginForm").submit();
  }
  window.addEventListener("load", () => {
    document.getElementById("username").focus();
    document.getElementById("password").value = "";
  });

  function togglePassword(fieldId, icon) {
    const field = document.getElementById(fieldId);
    if (field.type === "password") {
      field.type = "text";
      icon.classList.remove("fa-eye");
      icon.classList.add("fa-eye-slash");
    } else {
      field.type = "password";
      icon.classList.remove("fa-eye-slash");
      icon.classList.add("fa-eye");
    }
  }
  </script>
</body>
</html>
