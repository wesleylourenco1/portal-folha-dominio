# Publica o portal no servidor Ubuntu (código vem do GitHub; este PC só comanda por SSH).
#
#   publicar.bat -Primeira      instala tudo no servidor (pede a senha do sudo lá, uma vez)
#   publicar.bat                atualiza o servidor com o último commit do GitHub
#   publicar.bat -CopiarDados   leva o banco e os PDFs deste PC para o servidor (substitui os de lá)
param(
    [switch]$Primeira,
    [switch]$CopiarDados,
    [string]$Servidor = 'servidorwp@100.80.80.99',
    [string]$Dominio = 'portalrh.frotaopcao.com.br',
    [string]$Dir = '/var/www/portalrh'
)
$ErrorActionPreference = 'Stop'
$raiz = Resolve-Path "$PSScriptRoot\..\.."
$chave = "$env:USERPROFILE\.ssh\portal_deploy"
$ssh = @('-i', $chave, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new')
$tmp = Join-Path $env:TEMP "portalrh-publicar"
New-Item -ItemType Directory -Force $tmp | Out-Null
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Remoto([string]$comando, [switch]$Interativo) {
    # -n: não lê o teclado (senão o ssh fica preso no terminal); -t: terminal para o sudo pedir a senha
    $args2 = @($ssh) + @($(if ($Interativo) { '-t' } else { '-n' })) + @($Servidor, $comando)
    & ssh $args2
    if ($LASTEXITCODE -ne 0) { throw "Comando no servidor falhou (código $LASTEXITCODE)." }
}
function Enviar([string]$local, [string]$remoto) {
    & scp -q @ssh $local "${Servidor}:$remoto"
    if ($LASTEXITCODE -ne 0) { throw "Falha ao enviar $local." }
}
function ArquivoLF([string]$nome, [string]$conteudo) {
    $p = Join-Path $tmp $nome
    [IO.File]::WriteAllText($p, ($conteudo -replace "`r", ''), $utf8)
    return $p
}
function LerEnvLocal([string]$var) {
    $l = Select-String -Path "$raiz\portal\.env" -Pattern "^$var=(.*)$" | Select-Object -First 1
    if ($l) { return $l.Matches[0].Groups[1].Value.Trim('"') } else { return '' }
}

# o servidor baixa o código do GitHub: avisa se há commit local não enviado
$git = 'C:\Program Files\Git\cmd\git.exe'
if (Test-Path $git) {
    & $git -C $raiz fetch -q origin
    $local = & $git -C $raiz rev-parse HEAD; $remoto = & $git -C $raiz rev-parse '@{u}'
    if ($local -ne $remoto) { Write-Warning "Há commits locais que não estão no GitHub; o servidor vai pegar só o que já foi enviado (git push)." }
}

if ($Primeira) {
    $appKey = LerEnvLocal 'APP_KEY'          # mesma chave: dados criptografados continuam legíveis se forem copiados
    $token = LerEnvLocal 'AGENTE_TOKEN'      # o agente da Domínio continua com o mesmo token
    if (-not $appKey) { throw 'APP_KEY não encontrada no portal\.env local.' }
    $conteudoEnv = @"
APP_NAME="Portal da Folha"
APP_ENV=production
APP_KEY=$appKey
APP_DEBUG=false
APP_URL=https://$Dominio
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=pt_BR
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
DB_CONNECTION=sqlite
DB_DATABASE=$Dir/portal/database/database.sqlite
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_PATH=/
SESSION_DOMAIN=null
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
AGENTE_TOKEN=$token
"@
    Write-Host '==> Enviando .env de produção e instalador'
    Enviar (ArquivoLF 'portalrh.env' $conteudoEnv) 'portalrh.env'
    Enviar (ArquivoLF 'instalar.sh' (Get-Content "$raiz\scripts\ubuntu\instalar.sh" -Raw -Encoding UTF8)) 'portalrh-instalar.sh'
    Remove-Item (Join-Path $tmp 'portalrh.env') -Force
    Remoto 'chmod 600 ~/portalrh.env'
    Write-Host '==> Instalando (digite a senha do sudo do servidor quando pedir)'
    Remoto "sudo DOMINIO=$Dominio DIR=$Dir bash ~/portalrh-instalar.sh && rm -f ~/portalrh-instalar.sh" -Interativo
}
elseif ($CopiarDados) {
    Write-Host "Isto SUBSTITUI o banco e os documentos do servidor pelos deste PC (o banco de lá ganha um backup)."
    if ((Read-Host 'Digite SIM para continuar') -ne 'SIM') { Write-Host 'Cancelado.'; exit 1 }
    $db = "$raiz\portal\database\database.sqlite"
    Copy-Item $db (Join-Path $tmp 'db.sqlite') -Force
    & tar -czf (Join-Path $tmp 'arquivos.tgz') -C "$raiz\portal\storage\app" private
    Enviar (Join-Path $tmp 'db.sqlite') 'portalrh-db.sqlite'
    Enviar (Join-Path $tmp 'arquivos.tgz') 'portalrh-arquivos.tgz'
    Remove-Item (Join-Path $tmp 'db.sqlite'), (Join-Path $tmp 'arquivos.tgz') -Force
    Remoto ("set -e; umask 007; cd $Dir/portal; cp -a database/database.sqlite database/database.sqlite.bak-`$(date +%Y%m%d%H%M%S); " +
        "install -m 660 -g www-data ~/portalrh-db.sqlite database/database.sqlite; tar -xzf ~/portalrh-arquivos.tgz -C storage/app; " +
        "chgrp -R www-data storage/app/private; chmod -R g+rwX storage/app/private; rm -f ~/portalrh-db.sqlite ~/portalrh-arquivos.tgz; " +
        "php8.4 artisan migrate --force; php8.4 artisan optimize; php8.4 artisan queue:restart; echo DADOS_COPIADOS")
}
else {
    Write-Host '==> Atualizando o servidor'
    Remoto "bash $Dir/scripts/ubuntu/atualizar.sh"
}
Write-Host "Feito: https://$Dominio"
