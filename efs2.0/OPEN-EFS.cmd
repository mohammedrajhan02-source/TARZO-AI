@echo off
setlocal
title Ezy Filling System - Open EFS
if not exist "%~dp0..\..\xampp-control.exe" (
  echo Put the efs folder inside your XAMPP htdocs folder first.
  echo Expected location: C:\xampp\htdocs\efs\index.php
  echo Read START-HERE.html for the complete instructions.
  echo.
)
echo Start Apache and MySQL in the XAMPP Control Panel.
echo Then open http://localhost/efs/ in your browser.
echo If Apache uses port 8080, use http://localhost:8080/efs/ instead.
echo.
start "" "http://localhost/efs/"
pause
