FROM dunglas/frankenphp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app/public
COPY . .


# CMD ["pwd"]
# COPY . /app/public/

RUN install-php-extensions \
    pdo_mysql \
    gd \
    intl \
    zip \
    opcache

RUN composer install --no-dev --optimize-autoloader




