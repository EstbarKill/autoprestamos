@echo off
title Evaluación Biblioteca USB - Kiosk
setlocal

:: ==== CONFIGURACIÓN ====
set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"

set "PROFILE=%~dp0KioskProfile"
set "URL=https://evaluacion.tecnobibliounisimon.com/index.html"

:: ==== VALIDAR EXISTENCIA DE CHROME ====
if not exist "%CHROME%" (
    echo ❌ No se encontró Google Chrome en esta computadora.
    pause
    exit /b
)


:: ==== ABRIR EN MODO KIOSCO ====
start "" "%CHROME%" ^
  --new-window ^
  --user-data-dir="%PROFILE%" ^
  --no-first-run ^
  --start-fullscreen ^
  --kiosk ^
  "%URL%" ^
  --disable-infobars ^
  --disable-translate ^
  --overscroll-history-navigation=0 ^
  --disable-pinch ^
  --no-default-browser-check

endlocal
exit