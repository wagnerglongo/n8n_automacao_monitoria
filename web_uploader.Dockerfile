FROM php:8.2-apache

# 1) Dependências do sistema e extensões PHP
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    git curl unzip \
    libpng-dev libjpeg-dev libfreetype6-dev \
    fontconfig fonts-dejavu-core fonts-liberation \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd mbstring pdo_mysql \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# 2) Composer (copiado da imagem oficial)
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 3) Código-fonte
WORKDIR /var/www/html
COPY ./web/ /var/www/html/

# 4) Dependências PHP via Composer (inclui dompdf/dompdf)
# - Se já existir composer.json, isso apenas adiciona/atualiza o dompdf.
# - Se não existir, o comando vai criar automaticamente um composer.json mínimo.
RUN composer require dompdf/dompdf:^2.0 --no-interaction --prefer-dist --no-dev \
 && composer dump-autoload -o

# 5) Pastas de runtime e permissões
RUN mkdir -p /var/www/html/uploads /var/www/html/pdfs \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 775 /var/www/html/uploads /var/www/html/pdfs



