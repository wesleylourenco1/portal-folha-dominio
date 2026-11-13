# Instala uma tarefa do Windows que, quando a Área de Trabalho Remota desconecta (queda de internet,
# fechar o notebook...), devolve a sessão para o console. Assim a tela continua ativa para o robô.
# Execute como Administrador. Em uma VM dedicada ao robô (sem acesso remoto) não é necessário.

$ErrorActionPreference = 'Stop'
$usuario = $env:USERNAME
$pasta = Join-Path $env:ProgramData 'PortalFolhaAgente'
New-Item -ItemType Directory -Force $pasta | Out-Null

$script = @"
Start-Sleep -Seconds 3
foreach (`$linha in (qwinsta $usuario 2>`$null | Select-Object -Skip 1)) {
    if (`$linha -match '^\s*>?\s*(\S*)\s+$usuario\s+(\d+)\s') {
        if (`$Matches[1] -ne 'console') {
            & "`$env:WINDIR\System32\tscon.exe" `$Matches[2] /dest:console
            Add-Content '$pasta\voltar_console.log' "[`$(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')] sessao `$(`$Matches[2]) -> console (`$LASTEXITCODE)"
        }
    }
}
"@
Set-Content -Path (Join-Path $pasta 'voltar_console.ps1') -Value $script -Encoding UTF8

$classe = Get-CimClass -ClassName MSFT_TaskSessionStateChangeTrigger -Namespace Root/Microsoft/Windows/TaskScheduler
$gatilho = New-CimInstance -CimClass $classe -ClientOnly
$gatilho.StateChange = 4   # ao desconectar da sessão remota
$gatilho.Enabled = $true
$acao = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$pasta\voltar_console.ps1`""
Register-ScheduledTask -TaskName 'PortalFolha - manter sessao ativa' -Trigger $gatilho -Action $acao `
    -Settings (New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 2)) -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Write-Host 'Pronto: ao desconectar o acesso remoto, a sessao volta para o console.' -ForegroundColor Green
