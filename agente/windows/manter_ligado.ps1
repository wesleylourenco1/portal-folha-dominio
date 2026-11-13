# Mantém o agente sempre ligado (iniciado no login do Windows pela pasta Inicializar).
# A cada 30 segundos confere se o agente está rodando e religa se tiver parado.

$ErrorActionPreference = 'Continue'
$base = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$py = Join-Path $base '.venv\Scripts\python.exe'
$logs = Join-Path $base 'logs'
New-Item -ItemType Directory -Force $logs | Out-Null

$mutex = New-Object System.Threading.Mutex($false, 'Global\PortalFolha_Agente_ManterLigado')
if (-not $mutex.WaitOne(0)) { exit }

function Registrar($texto) {
    Add-Content -Path (Join-Path $logs 'manter_ligado.log') -Value "[$(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')] $texto" -Encoding UTF8
}

$alvo = Join-Path $base 'agente.py'
Registrar 'vigia iniciado'
Start-Sleep -Seconds 20
while ($true) {
    $rodando = Get-CimInstance Win32_Process -Filter "Name='python.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine.Contains($alvo) }
    if (-not $rodando) {
        $env:PYTHONUNBUFFERED = '1'; $env:PYTHONIOENCODING = 'utf-8'
        Start-Process -FilePath $py -ArgumentList '-W', 'ignore', $alvo -WorkingDirectory $base -WindowStyle Hidden `
            -RedirectStandardOutput (Join-Path $logs 'agente.log') -RedirectStandardError (Join-Path $logs 'agente_erro.log')
        Registrar 'agente iniciado'
    }
    Start-Sleep -Seconds 30
}
