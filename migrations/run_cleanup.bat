@echo off
set LOGFILE=C:\xampp\htdocs\wave_project\migrations\cleanup.log
echo [%DATE% %TIME%] Starting cleanup >> "%LOGFILE%"
"C:\xampp\php\php.exe" "C:\xampp\htdocs\wave_project\migrations\cleanup_old_otps.php" 3 >> "%LOGFILE%" 2>&1
echo [%DATE% %TIME%] Finished cleanup >> "%LOGFILE%"
