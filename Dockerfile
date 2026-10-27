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
RUN a2dissite 000-default.conf || true

# Sessões PHP fora do contêiner (volume php_sessions, compartilhado pelos dois
# contêineres web): o usuário continua logado quando o proxy o manda para o
# outro contêiner e quando os contêineres são recriados numa atualização
RUN mkdir -p /var/lib/php/sessions && chown www-data:www-data /var/lib/php/sessions \
    && echo "session.save_path = /var/lib/php/sessions" > /usr/local/etc/php/conf.d/ifscentral-sessoes.ini

# Ajusta permissões
RUN chown -R www-data:www-data /var/www/html

# Entrypoint para gerenciar inicialização
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

