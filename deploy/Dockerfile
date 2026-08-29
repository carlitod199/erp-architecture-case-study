# syntax=docker/dockerfile:1
# =============================================================================
# VERO — imagem da APLICAÇÃO (código DENTRO da imagem — decisão E-01).
# Base php:8.3-fpm (Debian). Build a partir da RAIZ do repo:
#     docker build -f deploy/Dockerfile -t vero-app:<tag> .
# Se você já tem uma base pinada por digest COM as extensões, troque os FROM
# por ela e remova o bloco docker-php-ext-install (mantendo mbstring+curl).
# Contrato de runtime: app em /var/www/vero, doc root = raiz (SEM public/),
# user vero-php 10001, FPM em socket unix /run/php/vero.sock.
# =============================================================================

# ---- Stage 1: resolve vendor (composer) -------------------------------------
FROM php:8.3-fpm AS build
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip libonig-dev; \
    docker-php-ext-install -j"$(nproc)" mbstring; \
    rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
# 1) só o manifesto -> camada de deps cacheável
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
# 2) código + autoload otimizado (opcache.validate_timestamps=0 => gerar no build)
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---- Stage 2: runtime -------------------------------------------------------
FROM php:8.3-fpm

# Extensões (a lista da sua base + mbstring/curl que faltavam). Remova se a base já traz.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      libonig-dev libcurl4-openssl-dev libicu-dev libzip-dev libxml2-dev \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev; \
    docker-php-ext-configure gd --with-jpeg --with-freetype; \
    docker-php-ext-install -j"$(nproc)" \
      pdo_mysql mysqli opcache intl zip gd soap bcmath mbstring curl; \
    pecl install redis; docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove; \
    rm -rf /var/lib/apt/lists/*

# Usuário do processo — uid/gid 10001 fixos (contrato).
RUN groupadd -g 10001 vero-php && useradd -u 10001 -g 10001 -M -s /usr/sbin/nologin vero-php

# Config PHP (hardening + opcache + upload) e pool FPM (socket + user 10001).
# O session.save_path do Redis (com a senha) é escrito pelo ENTRYPOINT em runtime.
COPY deploy/php/zz-vero.ini   /usr/local/etc/php/conf.d/zz-vero.ini
COPY deploy/php/vero-fpm.conf /usr/local/etc/php-fpm.d/zz-vero.conf

# Código (E-01) + vendor resolvido no stage build.
WORKDIR /var/www/vero
COPY --from=build --chown=10001:10001 /app /var/www/vero

# Diretórios graváveis dentro do open_basedir (montar como volume/dataset em runtime).
RUN set -eux; \
    mkdir -p storage/uploads storage/certs storage/sessions storage_private logs /run/php; \
    chown -R 10001:10001 storage storage_private logs /run/php

# Entrypoint: materializa /run/secrets/* -> .env + session-Redis. Roda como root
# (precisa ler os secrets e escrever conf.d); o php-fpm dropa para vero-php no pool.
COPY deploy/entrypoint.sh /usr/local/bin/vero-entrypoint
RUN chmod 0755 /usr/local/bin/vero-entrypoint

# Sem HEALTHCHECK aqui: VERO não tem endpoint DB-free; o healthcheck fica no
# container do nginx (/healthz). Este container só fala FPM via socket unix.
STOPSIGNAL SIGQUIT
ENTRYPOINT ["/usr/local/bin/vero-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]
