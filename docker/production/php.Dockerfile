# PaxofiCloud PHP-FPM runtime image with the application baked in
# (deploy threat model P-1, P-2, P-9).
#
# Built by CI from a clean checkout after `composer install --no-dev`; nothing
# is fetched during the image build, so no credentials ever enter it.
#   docker build -f docker/production/php.Dockerfile -t paxoficloud-php:<sha> .
FROM php:8.4-fpm@sha256:1071b5609d16d60674f78eea8cf9bd7e9e79169ecb566d015c931c5ee789bb13

RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql opcache; \
    rm -rf /tmp/* /usr/src/php.tar.xz

COPY docker/php/conf.d/paxoficloud.ini /usr/local/etc/php/conf.d/zz-paxoficloud.ini
COPY docker/production/php-production.ini /usr/local/etc/php/conf.d/zzz-paxoficloud-production.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-paxoficloud.conf

WORKDIR /app
# Code is owned by root and only readable by www-data: a compromised PHP
# process cannot modify the application.
COPY --chown=root:root bin/ bin/
COPY --chown=root:root config/ config/
COPY --chown=root:root migrations/ migrations/
COPY --chown=root:root public/ public/
COPY --chown=root:root src/ src/
COPY --chown=root:root vendor/ vendor/
COPY --chown=root:root composer.json composer.lock ./

ARG APP_REVISION=unknown
LABEL org.opencontainers.image.title="paxoficloud-php" \
      org.opencontainers.image.revision="${APP_REVISION}" \
      org.opencontainers.image.source="https://github.com/Paxofi-Technologies/paxofi-cloud"
ENV APP_REVISION=${APP_REVISION}

USER www-data
EXPOSE 9000
CMD ["php-fpm", "--nodaemonize"]
