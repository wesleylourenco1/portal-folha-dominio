# Roda o Portal da Folha localmente no Windows (antes do deploy no servidor):
#   - portal Laravel (php artisan serve) em http://localhost:8000 (e na rede local)
#   - fila (leitura de documentos com IA)
#   - agente da Domínio
# A cada 30 s confere os três e religa o que tiver parado. Logs em portal\storage\logs\local-*.log
#
# Iniciar sem janela:  wscript scripts\windows\rodar_local.vbs   (o instalar_local.ps1 coloca isso no login do Windows)

$ErrorActionPreference = 'Continue'
$raiz = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path))
$portal = Join-Path $raiz 'portal'
$agente = Join-Path $raiz 'agente'
$logs = Join-Path $portal 'storage\logs'
$porta = if ($env:PORTA_PORTAL) { $env:PORTA_PORTAL } else { '8000' }

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
        Select-Object -First 1 -ExpandProperty FullName
}
$py = Join-Path $agente '.venv\Scripts\python.exe'

$mutex = New-Object System.Threading.Mutex($false, 'Global\PortalFolha_RodarLocal')
if (-not $mutex.WaitOne(0)) { exit }

function Registrar($texto) {
    Add-Content -Path (Join-Path $logs 'local-vigia.log') -Value "[$(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')] $texto" -Encoding UTF8
}

function Rodando($marca) {
    [bool](Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine.Contains($marca) })
}

$servicos = @(
    @{ nome = 'portal'; marca = "artisan serve --host=0.0.0.0 --port=$porta"; exe = $php; dir = $portal
       args = @('artisan', 'serve', '--host=0.0.0.0', "--port=$porta") },
    @{ nome = 'fila'; marca = 'artisan queue:work'; exe = $php; dir = $portal
       args = @('artisan', 'queue:work', '--sleep=3', '--tries=1', '--timeout=600') },
    @{ nome = 'agente'; marca = (Join-Path $agente 'agente.py'); exe = $py; dir = $agente
       args = @('-W', 'ignore', (Join-Path $agente 'agente.py')) }
)

Registrar "vigia iniciado (php: $php)"
Start-Sleep -Seconds 15
while ($true) {
    foreach ($s in $servicos) {
        if (-not (Test-Path $s.exe)) { continue }
        if (-not (Rodando $s.marca)) {
            $env:PYTHONUNBUFFERED = '1'; $env:PYTHONIOENCODING = 'utf-8'
            Start-Process -FilePath $s.exe -ArgumentList $s.args -WorkingDirectory $s.dir -WindowStyle Hidden `
                -RedirectStandardOutput (Join-Path $logs "local-$($s.nome).log") `
                -RedirectStandardError (Join-Path $logs "local-$($s.nome)-erro.log")
            Registrar "$($s.nome) iniciado"
        }
    }
    Start-Sleep -Seconds 30
}
