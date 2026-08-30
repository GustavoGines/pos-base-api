@echo off
:: ============================================================
:: SISTEMA POS - Registrador de Tareas en Segundo Plano
:: Ejecutar COMO ADMINISTRADOR una sola vez
:: ============================================================
setlocal

:: USAR php-win.exe PARA EVITAR PANTALLAS NEGRAS DE CMD
set PHP_EXE=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php-win.exe
set BACKEND_DIR=C:\laragon\www\Sistema_POS\pos-backend
set ARTISAN=%BACKEND_DIR%\artisan

echo [Sistema POS] Registrando tareas de segundo plano...

schtasks /delete /tn "SistemaPOS_Reverb" /f >nul 2>&1
schtasks /delete /tn "SistemaPOS_Schedule" /f >nul 2>&1

schtasks /create /tn "SistemaPOS_Reverb" /tr "\"%PHP_EXE%\" \"%ARTISAN%\" reverb:start --no-interaction" /sc ONLOGON /delay 0000:30 /ru "%USERDOMAIN%\%USERNAME%" /f
if %errorlevel%==0 (echo [OK] Tarea SistemaPOS_Reverb creada.) else (echo [ERROR] Fallo SistemaPOS_Reverb - Correr como Admin)

schtasks /create /tn "SistemaPOS_Schedule" /tr "\"%PHP_EXE%\" \"%ARTISAN%\" schedule:run --no-interaction" /sc MINUTE /mo 1 /ru "%USERDOMAIN%\%USERNAME%" /f
if %errorlevel%==0 (echo [OK] Tarea SistemaPOS_Schedule creada para correr cada minuto.) else (echo [ERROR] Fallo SistemaPOS_Schedule - Correr como Admin)

echo.
echo Iniciando tareas ahora mismo...
schtasks /run /tn "SistemaPOS_Reverb" >nul 2>&1
timeout /t 5 /nobreak >nul
schtasks /run /tn "SistemaPOS_Schedule" >nul 2>&1

echo.
echo Procesos PHP corriendo en segundo plano:
tasklist /fi "IMAGENAME eq php-win.exe" /fo table
echo.
echo Listo. Las tareas se iniciaran de forma 100% invisible en cada inicio de sesion.
