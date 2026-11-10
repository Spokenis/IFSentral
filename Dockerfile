FROM php:8.2-apache AS builder

RUN apt-get update && apt-get install -y git unzip curl && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

COPY composer.json composer.lock* /var/www/html/

RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

FROM php:8.2-apache

# Instala dependências do sistema
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    netcat-openbsd \
    openssl \
    mosquitto-clients \
    && rm -rf /var/lib/apt/lists/* \
    && pip3 install mysql-connector-python --break-system-packages

# Instala extensões PHP
# posix é usado por src/mqtt/mqtt_health_check.php (posix_getpgid) para
# verificar se o processo do worker MQTT ainda está vivo
RUN docker-php-ext-install pdo pdo_mysql posix

# Habilita mod_rewrite
RUN a2enmod rewrite
# Habilita SSL e headers modules
RUN a2enmod ssl headers
# IP real do cliente atrás do proxy (docker/apache/ifsentral-http.conf)
RUN a2enmod remoteip

WORKDIR /var/www/html

# Copia vendor do builder (aproveita cache se composer.json não mudou)
COPY --from=builder /var/www/html/vendor /var/www/html/vendor

# Copia scripts de inicialização
COPY wait-for-it.sh /usr/local/bin/wait-for-it.sh
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/wait-for-it.sh /usr/local/bin/docker-entrypoint.sh

# Copia o restante do código
COPY . /var/www/html/

# Copy apache ssl vhost (if present in build context)
COPY docker/apache/ifsentral-ssl.conf /etc/apache2/sites-available/ifsentral-ssl.conf
COPY docker/apache/ifsentral-http.conf /etc/apache2/sites-available/ifsentral-http.conf
RUN a2ensite ifsentral-ssl.conf ifsentral-http.conf || true
# Processos do Apache abaixo do limite de conexões do MySQL (ver o arquivo)
COPY docker/apache/mpm-limites.conf /etc/apache2/conf-available/mpm-limites.conf
RUN a2enconf mpm-limites
RUN a2dissite 000-default.conf || true
# Não anuncia versões (Apache nas páginas de erro, PHP em X-Powered-By)
RUN printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/sem-versao.conf \
    && a2enconf sem-versao \
    && printf 'expose_php = Off\n' > /usr/local/etc/php/conf.d/ifscentral-sem-versao.ini

# Sessões PHP fora do contêiner (volume php_sessions, compartilhado pelos dois
# contêineres web): o usuário continua logado quando o proxy o manda para o
# outro contêiner e quando os contêineres são recriados numa atualização.
# config.php repete o gc_maxlifetime para os scripts fora desta imagem
RUN mkdir -p /var/lib/php/sessions && chown www-data:www-data /var/lib/php/sessions \
    && printf 'session.save_path = /var/lib/php/sessions\n; 30 dias sem uso (o padrão de 24 min derrubava o login)\nsession.gc_maxlifetime = 2592000\n; Cookie de sessão sempre protegido, mesmo nas páginas que abrem a sessão antes\n; do config.php (o ZAP achou PHPSESSID sem Secure/HttpOnly/SameSite em 10/11/2026).\n; Secure: o navegador só usa HTTPS (o HTTP redireciona; só o envio dos dispositivos fica em HTTP, sem sessão)\nsession.cookie_secure = 1\nsession.cookie_httponly = 1\nsession.cookie_samesite = Lax\nsession.use_strict_mode = 1\nsession.use_only_cookies = 1\n' \
       > /usr/local/etc/php/conf.d/ifscentral-sessoes.ini

# Ajusta permissões
RUN chown -R www-data:www-data /var/www/html

# Entrypoint para gerenciar inicialização
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

