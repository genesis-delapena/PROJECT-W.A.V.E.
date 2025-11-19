<?php
session_start();

// Prevent browser caching of login page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
include 'wavedb.php'; // Database connection included here

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- STEP 1: Input Validation ---
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

    // --- STEP 2: Database Retrieval and Verification (Retains prepared statements) ---
    $stmt = $conn->prepare("SELECT MG_UName, MG_PWD, LAR_level 
                            FROM auth_table 
                            WHERE BINARY MG_UName=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($dbUser, $dbPass, $dbLevel);

    if ($stmt->num_rows > 0) {
        $stmt->fetch();

        // Verify hashed password
        if (password_verify($password, $dbPass)) {
            // SUCCESSFUL LOGIN (without OTP step)
            $_SESSION["loggedin"] = true;
            $_SESSION["username"] = $dbUser;
            $_SESSION["level"]    = $dbLevel;
            
            // Redirect to the main application page
            header("Location: ad_dashboard.php"); 
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
    // The database connection ($conn) will close automatically on script end
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <style>
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
        class="loginbutton" 
        type="submit">
        Login
      </button>
    </form>

    <?php if (!empty($_SESSION["error"])) : ?>
     <p style="color:red; margin-top:10px;"><?php echo htmlspecialchars($_SESSION["error"]); ?></p>
     <?php unset($_SESSION["error"]); ?>
    <?php endif; ?>

    <p class="note">Water-Based Automated Vessel for Efficient Feeding & Environmental Monitoring</p>

    </main>

  <script>
  // REMOVE: function onSubmit(token) { ... }
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