#!/usr/bin/env bash
# Atualiza o portal já instalado (roda como o usuário dono, sem sudo). Chamado pelo publicar.ps1.
set -euo pipefail
DIR="${DIR:-/var/www/portalrh}"
PHP=/usr/bin/php8.4
cd "$DIR"
git pull -q --ff-only
cd portal
$PHP /usr/bin/composer install --no-dev -o --no-interaction -q
$PHP artisan migrate --force
$PHP artisan optimize
$PHP artisan queue:restart   # o systemd sobe a fila de novo com o código novo
echo "Atualizado: $(git -C "$DIR" log --oneline -1)"
