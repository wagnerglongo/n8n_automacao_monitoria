FROM php:8.1-apache

# 1) Dependências do sistema e extensões PHP
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    git curl unzip pkg-config \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libonig-dev \
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

# 4) Dependências PHP via Composer
RUN if [ ! -f composer.json ]; then \
      composer init --no-interaction --name=app/web --stability=stable --autoload 'psr-4={"App\\\":\"src/"}'; \
    fi \
 && composer require dompdf/dompdf:^2.0 --no-interaction --prefer-dist \
 && composer dump-autoload -o


# 5) Pastas de runtime e permissões
RUN mkdir -p /var/www/html/uploads /var/www/html/pdfs \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 775 /var/www/html/uploads /var/www/html/pdfs
