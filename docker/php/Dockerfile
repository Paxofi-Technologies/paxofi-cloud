# PaxofiCloud PHP-FPM runtime image for local development and CI.
# Runtime only: no Composer, git or compilers. Dependencies are installed with
# the separate `composer` service (official composer:2 image). The production
# image (code baked in) arrives with the staging infrastructure.
FROM php:8.4-fpm

RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql opcache; \
    rm -rf /tmp/*

COPY conf.d/paxoficloud.ini /usr/local/etc/php/conf.d/zz-paxoficloud.ini
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-paxoficloud.conf

WORKDIR /app

# Run as the unprivileged www-data user, never root.
USER www-data

EXPOSE 9000
CMD ["php-fpm", "--nodaemonize"]
