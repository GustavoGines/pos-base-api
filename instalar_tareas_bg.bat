@echo off
:: ============================================================
:: CAJA4U (SISTEMA POS) - Registrador de Tareas en Segundo Plano
:: Ejecutar COMO ADMINISTRADOR
:: Registra e inicia: Reverb (WebSockets), Queue Worker y Scheduler
:: ============================================================
setlocal enabledelayedexpansion

:: Detectar dinamicamente la ruta actual (portable)
set "BACKEND_DIR=%~dp0"
if "%BACKEND_DIR:~-1%"=="\" set "BACKEND_DIR=%BACKEND_DIR:~0,-1%"

:: Resolver la ruta canonica de Laragon (3 niveles arriba de pos-backend) sin "..\.."
for %%I in ("%BACKEND_DIR%\..\..\..") do set "LARAGON_ROOT=%%~fI"

set "PHP_EXE=%LARAGON_ROOT%\bin\php\php-8.3.30-Win32-vs16-x64\php-win.exe"
if not exist "%PHP_EXE%" (
    for /d %%D in ("%LARAGON_ROOT%\bin\php\php-*") do (
        if exist "%%~fD\php-win.exe" set "PHP_EXE=%%~fD\php-win.exe"
    )
)

set "ARTISAN=%BACKEND_DIR%\artisan"

echo [Caja4U] Registrando tareas de segundo plano...
echo Ruta Backend: %BACKEND_DIR%
echo Ruta PHP: %PHP_EXE%

:: Detener instancias previas de php-win.exe para liberar puertos y codigo viejo
taskkill /F /IM php-win.exe /T >nul 2>&1

schtasks /delete /tn "SistemaPOS_Reverb" /f >nul 2>&1
schtasks /delete /tn "SistemaPOS_Schedule" /f >nul 2>&1
schtasks /delete /tn "SistemaPOS_Queue" /f >nul 2>&1

schtasks /create /tn "SistemaPOS_Reverb" /tr "\"%PHP_EXE%\" \"%ARTISAN%\" reverb:start --no-interaction" /sc ONLOGON /delay 0000:30 /ru "SYSTEM" /rl HIGHEST /f
if %errorlevel%==0 (echo [OK] Tarea SistemaPOS_Reverb creada.) else (echo [ERROR] Fallo SistemaPOS_Reverb - Correr como Admin)

schtasks /create /tn "SistemaPOS_Schedule" /tr "\"%PHP_EXE%\" \"%ARTISAN%\" schedule:run --no-interaction" /sc MINUTE /mo 1 /ru "SYSTEM" /rl HIGHEST /f
if %errorlevel%==0 (echo [OK] Tarea SistemaPOS_Schedule creada para correr cada minuto.) else (echo [ERROR] Fallo SistemaPOS_Schedule - Correr como Admin)

schtasks /create /tn "SistemaPOS_Queue" /tr "\"%PHP_EXE%\" \"%ARTISAN%\" queue:work --tries=3 --timeout=90 --no-interaction" /sc ONLOGON /delay 0000:30 /ru "SYSTEM" /rl HIGHEST /f
if %errorlevel%==0 (echo [OK] Tarea SistemaPOS_Queue creada.) else (echo [ERROR] Fallo SistemaPOS_Queue - Correr como Admin)

:: Blindar configuracion de las tareas (permitir en notebooks con bateria, sin limite de 72hs y auto-reinicio cada 1 min si MySQL tarda en arrancar)
powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "$ErrorActionPreference='SilentlyContinue'; $s = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1); Set-ScheduledTask -TaskName 'SistemaPOS_Reverb' -Settings $s | Out-Null; Set-ScheduledTask -TaskName 'SistemaPOS_Queue' -Settings $s | Out-Null; Set-ScheduledTask -TaskName 'SistemaPOS_Schedule' -Settings $s | Out-Null" >nul 2>&1

:: Asegurar que Laragon inicie automaticamente con Windows
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "Laragon" /t REG_SZ /d "\"%LARAGON_ROOT%\laragon.exe\"" /f >nul 2>&1

echo.
echo Abriendo puerto 8080 en el Firewall de Windows para WebSockets locales...
netsh advfirewall firewall delete rule name="Sistema POS - WebSockets" >nul 2>&1
netsh advfirewall firewall add rule name="Sistema POS - WebSockets" dir=in action=allow protocol=TCP localport=8080 >nul 2>&1
if %errorlevel%==0 (echo [OK] Puerto 8080 abierto en el Firewall.) else (echo [ERROR] Fallo al abrir puerto 8080)

echo.
echo Limpiando basura de cache...
del /q /f "%BACKEND_DIR%\bootstrap\cache\*.tmp" >nul 2>&1

echo Iniciando tareas ahora mismo...
schtasks /run /tn "SistemaPOS_Reverb" >nul 2>&1
timeout /t 3 /nobreak >nul
schtasks /run /tn "SistemaPOS_Schedule" >nul 2>&1
schtasks /run /tn "SistemaPOS_Queue" >nul 2>&1

echo.
echo Procesos PHP corriendo en segundo plano:
tasklist /fi "IMAGENAME eq php-win.exe" /fo table
echo.
echo Listo. Las tareas se iniciaran de forma 100% invisible en cada inicio de sesion.
