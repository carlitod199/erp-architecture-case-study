@echo off
rem ============================================================
rem VERO - Bateria de testes A5-QA (Windows/WAMP)
rem Uso:  run_bateria.bat            (suite completa 1x)
rem       run_bateria.bat --2x       (2x seguidas = prova de idempotencia)
rem       run_bateria.bat --so=20    (so um script, ex.: 20_fluxos)
rem Resultado: tests\bateria\RELATORIO_EXECUCAO.md + _out\*.json
rem ============================================================
setlocal
set PHP=php
if not exist "%PHP%" set PHP=php
"%PHP%" "%~dp0run_all.php" %*
set RC=%ERRORLEVEL%
echo.
echo Exit code: %RC%  (0 = bateria verde)
endlocal & exit /b %RC%
