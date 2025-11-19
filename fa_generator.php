<?php
// --------------------------------------------
// PHP: RUN THE EXE IF REQUESTED BY AJAX
// --------------------------------------------
if (isset($_GET['run'])) {

    header("Content-Type: text/plain");

   $use_python_script = true;

// FULL path to python.exe
$python = "C:\\Users\\YourUser\\AppData\\Local\\Programs\\Python\\Python312\\python.exe";

// Run the Python script instead of EXE
$exe = __DIR__ . DIRECTORY_SEPARATOR . 'FA_Code_Generator.py';


    // Execute the EXE and capture output
    $cmd = escapeshellcmd($exe);
    $output = shell_exec($cmd . " 2>&1");

    echo $output ? $output : "No output returned from EXE.";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>W.A.V.E — Feature Code Generator</title>

<style>
/* ===== WAVE THEME UI (INTERNAL CSS) ===== */

body {
    font-family: 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #1e4e78, #2e75ac, #3aa3d1);
    margin: 0;
    padding: 0;
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    color: white;
}

.container {
    background: rgba(255,255,255,0.12);
    backdrop-filter: blur(15px);
    padding: 30px;
    border-radius: 18px;
    width: 650px;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.35);
}

h1 {
    font-size: 26px;
    margin-bottom: 20px;
    font-weight: 700;
    letter-spacing: 2px;
}

button {
    width: 85%;
    padding: 15px;
    font-size: 16px;
    font-weight: bold;
    background: #00b4d8;
    border: none;
    border-radius: 12px;
    color: #fff;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
    transition: 0.25s;
}

button:hover {
    transform: translateY(-3px);
    background: #0096c7;
}

.output-box {
    background: rgba(0,0,0,0.35);
    margin-top: 20px;
    padding: 16px;
    height: 300px;
    text-align: left;
    border-radius: 12px;
    overflow-y: auto;
    font-family: Consolas, monospace;
    box-shadow: inset 0 0 12px rgba(0,0,0,0.5);
}

.output-box pre {
    white-space: pre-wrap;
    color: #dff7ff;
}
</style>

</head>
<body>

<div class="container">
    <h1>W.A.V.E Feature Code Generator</h1>

    <button id="runBtn">RUN GENERATOR</button>

    <div class="output-box" id="outputBox">
        <p>Waiting for execution…</p>
    </div>
</div>

<script>
// ===== AJAX BUTTON HANDLER =====
document.getElementById("runBtn").addEventListener("click", function () {
    const box = document.getElementById("outputBox");

    box.innerHTML += "<p><b>Running FA_Code_Generator.exe...</b></p>";

    fetch("?run=1")
        .then(res => res.text())
        .then(data => {
            box.innerHTML += `<pre>${data}</pre>`;
            box.scrollTop = box.scrollHeight;
        })
        .catch(err => {
            box.innerHTML += `<p style='color:red;'>ERROR: ${err}</p>`;
        });
});
</script>

</body>
</html>