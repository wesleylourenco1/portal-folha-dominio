@echo off
:: Cria (ou troca a senha de) um usuario do portal. A senha e digitada aqui e nao aparece na tela.
cd /d "%~dp0..\..\portal"
set /p EMAIL=E-mail do usuario:
set /p NOME=Nome:
for /f "delims=" %%p in ('where php 2^>nul') do set PHP=%%p
if "%PHP%"=="" for /f "delims=" %%p in ('dir /s /b "%LOCALAPPDATA%\Microsoft\WinGet\Packages\php.exe" 2^>nul') do set PHP=%%p
"%PHP%" artisan portal:usuario "%EMAIL%" --nome="%NOME%"
pause
