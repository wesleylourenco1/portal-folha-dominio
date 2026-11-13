#!/usr/bin/env bash
# Instala o Portal da Folha num Ubuntu que JÁ tem outros sites (Apache + cloudflared com config local).
# Não mexe nos sites existentes: porta só local, pool PHP próprio, banco SQLite próprio
# e uma única entrada nova no túnel (validada antes de recarregar).
#
# Uso (no servidor):  sudo bash portalrh-instalar.sh
# Variáveis opcionais: DOMINIO, PORTA, USUARIO, DIR, REPO, ENV_ORIGEM
set -euo pipefail

DOMINIO="${DOMINIO:-portalrh.frotaopcao.com.br}"
PORTA="${PORTA:-9101}"
USUARIO="${USUARIO:-${SUDO_USER:-servidorwp}}"
DIR="${DIR:-/var/www/portalrh}"
REPO="${REPO:-https://github.com/wesleylourenco1/portal-folha-dominio.git}"
ENV_ORIGEM="${ENV_ORIGEM:-/home/$USUARIO/portalrh.env}"
APP="$DIR/portal"
PHP=/usr/bin/php8.4
CF=/etc/cloudflared/config.yml

passo() { echo; echo "==> $*"; }
[ "$(id -u)" = 0 ] || { echo "Rode com sudo."; exit 1; }

passo "1/9 Pacotes do PHP 8.4 (FPM + extensões)"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-gd php8.4-bcmath \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl git unzip >/dev/null

passo "2/9 Código em $DIR"
mkdir -p "$DIR"; chown "$USUARIO":www-data "$DIR"
if [ ! -d "$DIR/.git" ]; then
  sudo -H -u "$USUARIO" git clone -q "$REPO" "$DIR"
else
  sudo -H -u "$USUARIO" git -C "$DIR" pull -q --ff-only
fi

passo "3/9 .env de produção"
if [ ! -f "$APP/.env" ]; then
  [ -f "$ENV_ORIGEM" ] || { echo "Falta $ENV_ORIGEM (envie com publicar.ps1 -Primeira)."; exit 1; }
  install -o "$USUARIO" -g www-data -m 640 "$ENV_ORIGEM" "$APP/.env"
  rm -f "$ENV_ORIGEM"
else
  echo "   .env já existe, mantido."
fi

passo "4/9 Dependências e banco"
sudo -H -u "$USUARIO" touch "$APP/database/database.sqlite"
chmod 660 "$APP/database/database.sqlite"
chgrp -R www-data "$APP/storage" "$APP/bootstrap/cache" "$APP/database"
chmod -R g+rwX "$APP/storage" "$APP/bootstrap/cache" "$APP/database"
sudo -H -u "$USUARIO" bash -c "cd '$APP' && $PHP /usr/bin/composer install --no-dev -o --no-interaction -q \
  && $PHP artisan migrate --force && $PHP artisan optimize"

passo "5/9 Pool PHP-FPM próprio (usuário $USUARIO, limites de upload do zip)"
cat > /etc/php/8.4/fpm/pool.d/portalrh.conf <<EOF
[portalrh]
user = $USUARIO
group = www-data
listen = /run/php/portalrh.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 160M
php_admin_value[max_file_uploads] = 50
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 300
php_admin_value[expose_php] = Off
EOF
systemctl enable -q php8.4-fpm
systemctl restart php8.4-fpm

passo "6/9 Apache: site só em 127.0.0.1:$PORTA"
cat > /etc/apache2/sites-available/portalrh-$PORTA.conf <<EOF
Listen 127.0.0.1:$PORTA
<VirtualHost 127.0.0.1:$PORTA>
    ServerName $DOMINIO
    DocumentRoot $APP/public
    <Directory $APP/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/portalrh.sock|fcgi://portalrh"
    </FilesMatch>
    LimitRequestBody 167772160
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    ErrorLog \${APACHE_LOG_DIR}/portalrh-error.log
    CustomLog \${APACHE_LOG_DIR}/portalrh-access.log combined
</VirtualHost>
EOF
a2enmod -q proxy_fcgi setenvif rewrite headers >/dev/null
a2ensite -q "portalrh-$PORTA" >/dev/null
if ! apache2ctl configtest; then
  a2dissite -q "portalrh-$PORTA" >/dev/null   # não deixa config quebrada para os outros sites
  echo "   Config do Apache inválida: site desativado, nada recarregado."; exit 1
fi
systemctl reload apache2

passo "7/9 Fila (leitura dos documentos com IA)"
cat > /etc/systemd/system/portalrh-fila.service <<EOF
[Unit]
Description=Portal da Folha - fila
After=network.target

[Service]
User=$USUARIO
Group=www-data
WorkingDirectory=$APP
ExecStart=$PHP artisan queue:work --sleep=3 --tries=1 --timeout=600 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable -q --now portalrh-fila
systemctl restart portalrh-fila

passo "8/9 Teste local"
codigo=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $DOMINIO" -H 'X-Forwarded-Proto: https' "http://127.0.0.1:$PORTA/login")
echo "   GET /login -> HTTP $codigo"
[ "$codigo" = 200 ] || { echo "   O portal não respondeu 200. Veja /var/log/apache2/portalrh-error.log e $APP/storage/logs."; exit 1; }

passo "9/9 Túnel cloudflared: $DOMINIO -> 127.0.0.1:$PORTA"
if grep -q "hostname: $DOMINIO" "$CF"; then
  echo "   Já existe no $CF, mantido."
else
  copia="$CF.bak-portalrh-$(date +%Y%m%d%H%M%S)"
  cp -a "$CF" "$copia"
  # insere antes da regra final (http_status:404)
  awk -v h="$DOMINIO" -v p="$PORTA" '
    /^[[:space:]]*-[[:space:]]*service:[[:space:]]*http_status:404/ && !feito {
      print "  - hostname: " h; print "    service: http://127.0.0.1:" p; print ""; feito=1 }
    { print }' "$copia" > "$CF"
  if cloudflared tunnel --config "$CF" ingress validate; then
    systemctl restart cloudflared
    echo "   Túnel recarregado (backup em $copia)."
  else
    cp -a "$copia" "$CF"
    echo "   Validação falhou: config original restaurada, nada mudou no túnel."; exit 1
  fi
fi

echo
echo "Pronto. Acesse https://$DOMINIO"
echo "Se não abrir: no painel da Cloudflare, o DNS $DOMINIO deve ser CNAME para o túnel (<id>.cfargotunnel.com), proxied."
echo "Crie o usuário do portal:  cd $APP && $PHP artisan portal:usuario seu@email --nome=\"RH\""
