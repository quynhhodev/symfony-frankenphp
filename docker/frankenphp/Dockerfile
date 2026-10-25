# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base: PHP + extension, dùng chung cho dev và prod.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base

# Project nằm ở /app/public chứ không phải /app: compose mount ./ vào đây và
# migration-job khai workingDir này. Web root của Caddy là `public/` tính từ
# WORKDIR, tức /app/public/public, nên .env, src/... không bị phục vụ ra ngoài.
WORKDIR /app/public

# zip để composer giải nén package; opcache đã có sẵn trong image gốc.
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    amqp

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV SERVER_NAME=:80
EXPOSE 80

# ---------------------------------------------------------------------------
# dev: compose mount code vào và tự chạy `composer install` (cả dev deps, cho
# PHPUnit), nên image không chứa code. Chạy bằng root để ghi được vendor/ và
# var/ trên thư mục mount.
# ---------------------------------------------------------------------------
FROM base AS dev

# ---------------------------------------------------------------------------
# prod: image CI build và đẩy lên Docker Hub. Phải là stage CUỐI, vì
# build-push-action trong do-ci.yml không khai `target`.
# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod
# Cổng trên 1024: user thường mở được mà không cần capability nào, nên pod chạy
# được với `capabilities.drop: [ALL]`. Service vẫn mở cổng 80 ra ngoài.
ENV SERVER_NAME=:8080
EXPOSE 8080
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Chỉ copy composer.* trước: sửa code mà dependency không đổi thì layer
# `composer install` vẫn dùng cache.
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader \
    && composer clear-cache

# Liệt kê từng thư mục thay vì `COPY . .`, để thứ gì lọt qua .dockerignore cũng
# không vào được image. .env vẫn cần: Dotenv báo lỗi nếu thiếu file này, còn giá
# trị thật lấy từ ConfigMap/Secret (biến môi trường thật luôn thắng .env).
COPY .env ./
COPY bin bin/
COPY config config/
COPY migrations migrations/
COPY public public/
COPY src src/

# dump-autoload cũng sinh vendor/autoload_runtime.php (plugin symfony/runtime).
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && php bin/console cache:warmup

# Chạy bằng user thường. Caddy cần ghi /data/caddy, /config/caddy; Symfony cần
# ghi var/.
#
# Không cần `setcap` ở đây: image gốc đã gắn sẵn cap_net_bind_service=ep lên
# binary frankenphp. setcap lại chỉ chép thêm cả binary thành một layer ~59MB.
# Vì binary mang file capability đó, pod `drop: [ALL]` phải `add: [NET_BIND_SERVICE]`,
# thiếu thì kernel từ chối exec ("Operation not permitted", exit 126).
#
# USER khai bằng SỐ: `runAsNonRoot` của k8s chỉ kiểm tra được uid dạng số, gặp tên
# user thì pod kẹt CreateContainerConfigError.
RUN useradd --uid 1000 --no-create-home app \
    && chown -R app:app /data/caddy /config/caddy var
USER 1000:1000
