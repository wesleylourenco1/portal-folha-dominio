@echo off
rem Atalho para o publicar.ps1 (funciona no cmd e no PowerShell, sem mudar a política de scripts do Windows)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0publicar.ps1" %*
