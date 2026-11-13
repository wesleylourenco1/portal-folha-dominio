# Deploy do portal em Ubuntu (22.04/24.04)

## 1. Pacotes

```bash
sudo apt update
sudo apt install -y nginx mysql-server unzip git \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

(Se o PHP 8.4 não estiver no repositório padrão: `sudo add-apt-repository ppa:ondrej/php`.)

## 2. Banco

```bash
sudo mysql -e "CREATE DATABASE portal_folha CHARACTER SET utf8mb4; \
  CREATE USER 'portal'@'localhost' IDENTIFIED BY 'TROQUE-POR-UMA-SENHA-FORTE'; \
  GRANT ALL ON portal_folha.* TO 'portal'@'localhost';"
```

## 3. Aplicação

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/SEU_USUARIO/portal-folha-dominio.git
sudo chown -R $USER:www-data portal-folha-dominio && cd portal-folha-dominio/portal
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

No `php.ini` do FPM: `upload_max_filesize = 50M`, `post_max_size = 160M`, `max_file_uploads = 50` (zip de documentos).

No `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://portal.seudominio.com.br`,
`DB_CONNECTION=mysql` + credenciais, `QUEUE_CONNECTION=database`, `SESSION_SECURE_COOKIE=true`
e o `AGENTE_TOKEN` gerado com `php artisan portal:token-agente`.

```bash
php artisan migrate --force
php artisan portal:usuario rh@seudominio.com.br --nome="RH"
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache && chmod 600 .env
```

## 4. Nginx + HTTPS

```nginx
server {
    server_name portal.seudominio.com.br;
    root /var/www/portal-folha-dominio/portal/public;
    index index.php;
    client_max_body_size 160M;   # zip de documentos da admissão
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.4-fpm.sock; }
    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/portal /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d portal.seudominio.com.br
```

## 5. Fila (leitura de documentos com IA)

`/etc/systemd/system/portal-fila.service`:

```ini
[Unit]
Description=Fila do Portal da Folha
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/portal-folha-dominio/portal
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=1 --timeout=600
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now portal-fila
```

## 6. Backup

Faça backup diário do banco (`mysqldump`) e da pasta `storage/app/private` (PDFs e documentos), criptografados e fora do servidor.
