FROM dunglas/frankenphp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app/public


# Copy dependencies
COPY --chown=docker:docker .env ./
COPY --chown=docker:docker bin bin/
COPY --chown=docker:docker config config/
# COPY --chown=docker:docker hasura hasura/
# COPY --chown=docker:docker migrations migrations/
COPY --chown=docker:docker public public/
COPY --chown=docker:docker src src/
# COPY --chown=docker:docker graphql graphql/
# COPY --chown=docker:docker templates templates/
# COPY --chown=docker:docker translations translations/
# COPY --chown=docker:docker resources resources/
COPY --chown=docker:docker composer.* symfony.* ./


# CMD ["pwd"]
# COPY . /app/public/
EXPOSE 80

RUN install-php-extensions \
    pdo_mysql \
    pdo \
    pdo_pgsql \
    gd \
    intl \
    zip \
    opcache

RUN composer install --optimize-autoloader --no-interaction --no-scripts --no-progress --no-dev; \
    composer clear-cache;






