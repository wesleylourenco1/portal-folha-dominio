# Prepara a máquina/VM do robô: ambiente Python, dependências, .env e inicialização automática.
# Uso (PowerShell, na pasta agente):  powershell -ExecutionPolicy Bypass -File windows\instalar.ps1
# Requisito: Python 3.12 instalado (winget install Python.Python.3.12).

$ErrorActionPreference = 'Stop'
$base = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $base

Write-Host '1/4 Criando ambiente Python...'
if (-not (Test-Path '.venv')) { py -3.12 -m venv .venv }
& .\.venv\Scripts\python.exe -m pip install -q --upgrade pip
& .\.venv\Scripts\python.exe -m pip install -q -r requirements.txt

Write-Host '2/4 Configuração (.env)...'
if (-not (Test-Path '.env')) {
    $portal = Read-Host 'URL do portal (ex.: https://portal.seudominio.com.br)'
    $token = Read-Host 'AGENTE_TOKEN (gerado no portal com php artisan portal:token-agente)'
    $exe = Read-Host 'Caminho do contabil.exe [C:\Contabil\contabil.exe]'
    if (-not $exe) { $exe = 'C:\Contabil\contabil.exe' }
    $pasta = Read-Host 'Pasta dos PDFs da rotina automática [C:\Robo\relatorios]'
    if (-not $pasta) { $pasta = 'C:\Robo\relatorios' }
    New-Item -ItemType Directory -Force $pasta | Out-Null
    @"
PORTAL_URL=$portal
AGENTE_TOKEN=$token
DOMINIO_EXE=$exe
DOMINIO_USUARIO=
DOMINIO_SENHA=
PASTA_RELATORIOS=$pasta
INTERVALO_SEGUNDOS=30
"@ | Set-Content .env -Encoding UTF8
    # o .env fica legível só para o usuário atual
    icacls .env /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
}

Write-Host '3/4 Testando conexão com o portal...'
& .\.venv\Scripts\python.exe agente.py --uma-vez

Write-Host '4/4 Inicialização automática no login do Windows...'
$vbs = Join-Path $base 'windows\iniciar_oculto.vbs'
$lnk = (New-Object -ComObject WScript.Shell).CreateShortcut("$([Environment]::GetFolderPath('Startup'))\Agente Portal da Folha.lnk")
$lnk.TargetPath = "$env:WINDIR\System32\wscript.exe"; $lnk.Arguments = "`"$vbs`""; $lnk.WorkingDirectory = $base; $lnk.Save()
Start-Process wscript.exe -ArgumentList "`"$vbs`""

Write-Host ''
Write-Host 'Agente instalado e rodando.' -ForegroundColor Green
Write-Host 'Próximos passos: crie na Domínio a Rotina Automática do adiantamento (docs/rotina-adiantamento.md)'
Write-Host 'e, se esta máquina recebe acesso remoto, rode windows\manter_sessao_ativa.ps1 como Administrador.'
