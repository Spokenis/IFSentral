FROM php:8.2-apache AS builder

RUN apt-get update && apt-get install -y git unzip curl && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

COPY composer.json composer.lock* /var/www/html/

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

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
RUN docker-php-ext-install pdo pdo_mysql

# Habilita mod_rewrite
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copia vendor do builder (aproveita cache se composer.json não mudou)
COPY --from=builder /var/www/html/vendor /var/www/html/vendor

# Copia scripts de inicialização
COPY wait-for-it.sh /usr/local/bin/wait-for-it.sh
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/wait-for-it.sh /usr/local/bin/docker-entrypoint.sh

# Copia o restante do código
COPY . /var/www/html/

# Ajusta permissões
RUN chown -R www-data:www-data /var/www/html

# Entrypoint para gerenciar inicialização
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

