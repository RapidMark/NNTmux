# syntax=docker/dockerfile:1
# Self-contained NNTmux for Unraid: upstream app image + MariaDB, Redis and Manticore under s6.
# Build the app image first from the repo root with the upstream Dockerfile (see Makefile).
ARG APP_IMAGE=nntmux-app:build

# NNTmux targets tmux 3.7b (install_tmux.sh upstream); Debian 12 ships 3.3a.
FROM debian:bookworm-slim AS tmux
ARG TMUX_VERSION=3.7b
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates git automake build-essential \
        pkg-config libevent-dev libncurses-dev bison \
    && git clone --branch "${TMUX_VERSION}" --depth 1 https://github.com/tmux/tmux.git /src \
    && cd /src && sh autogen.sh && ./configure --prefix=/usr/local && make -j"$(nproc)" && make install

FROM ${APP_IMAGE} AS app

# RapidYenc for NNTmux's native yEnc decoder, built with upstream's pinned script.
FROM debian:bookworm-slim AS rapidyenc
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl cmake make g++ \
    && mkdir -p /src/scripts /src/storage/app
COPY --from=app /app/scripts/build-rapidyenc.sh /src/scripts/
RUN bash /src/scripts/build-rapidyenc.sh

# FrankenPHP needs thread-safe PHP; the native decoder needs non-thread-safe PHP with FFI.
# The CLI (tmux workers, scheduler, horizon) runs on this one, the web server keeps FrankenPHP.
FROM php:8.5-cli-bookworm AS php-nts
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath exif gd intl pdo_mysql pdo_sqlite sockets pcntl redis imagick zip ffi opcache

FROM app

ARG S6_OVERLAY_VERSION=3.2.3.2
ARG MARIADB_VERSION=11.4

COPY --from=tmux /usr/local/bin/tmux /usr/local/bin/tmux
COPY --from=rapidyenc /src/storage/app/rapidyenc/librapidyenc.so /usr/local/lib/librapidyenc.so
COPY --from=php-nts /usr/local/bin/php /opt/php-nts/bin/php
COPY --from=php-nts /usr/local/lib/php/extensions/ /opt/php-nts/extensions/
COPY --from=php-nts /usr/local/etc/php/conf.d/ /opt/php-nts/conf.d/

USER 0

RUN apt-get update && apt-get install -y --no-install-recommends xz-utils wget gnupg redis-server p7zip-full \
    && install -d /etc/apt/keyrings \
    && wget -qO /etc/apt/keyrings/mariadb-keyring.pgp https://mariadb.org/mariadb_release_signing_key.pgp \
    && echo "deb [signed-by=/etc/apt/keyrings/mariadb-keyring.pgp] https://deb.mariadb.org/${MARIADB_VERSION}/debian bookworm main" > /etc/apt/sources.list.d/mariadb.list \
    && wget -qO /tmp/manticore-repo.deb https://repo.manticoresearch.com/manticore-repo.noarch.deb \
    && dpkg -i /tmp/manticore-repo.deb && rm /tmp/manticore-repo.deb \
    && apt-get update && apt-get install -y --no-install-recommends mariadb-server mariadb-client manticore libevent-core-2.1-7 \
    && apt-get upgrade -y \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz /tmp/
RUN tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz \
    && tar -C / -Jxpf /tmp/s6-overlay-x86_64.tar.xz \
    && rm /tmp/s6-overlay-*.tar.xz

COPY root/ /
RUN chmod +x /etc/s6-overlay/scripts/* /etc/s6-overlay/s6-rc.d/*/run

# `php` on the PATH is the non-thread-safe CLI; the thread-safe one stays as php-zts.
RUN printf 'extension_dir=%s\nffi.enable=true\n' "$(ls -d /opt/php-nts/extensions/no-debug-non-zts-*)" > /opt/php-nts/conf.d/00-nts.ini \
    && for f in /usr/local/etc/php/conf.d/*.ini; do case "${f##*/}" in docker-php-ext-*) ;; *) cp "$f" /opt/php-nts/conf.d/ ;; esac; done \
    && mv /usr/local/bin/php /usr/local/bin/php-zts \
    && printf '#!/bin/sh\nexport PHP_INI_SCAN_DIR=/opt/php-nts/conf.d\nexec /opt/php-nts/bin/php -c /usr/local/etc/php/php.ini "$@"\n' > /usr/local/bin/php \
    && chmod 755 /usr/local/bin/php

# tmux prints tabs in -F output as '_' without a UTF-8 locale, which breaks NNTmux's pane lookup.
ENV LANG=C.UTF-8 \
    PUID=99 PGID=100 UMASK=002 TZ=Etc/UTC \
    START_INDEXER=true \
    YENC_NATIVE_LIBRARY=/usr/local/lib/librapidyenc.so NNTP_COMPRESSED_HEADERS=true \
    CHECK_PASSWORDED_RARS=true ADD_PAR2=true PULSE_ENABLED=false FIX_NAMES=true MIN_RELEASE_SIZE_MB=50 \
    XDG_CONFIG_HOME=/config/caddy/config XDG_DATA_HOME=/config/caddy/data \
    S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 S6_BEHAVIOUR_IF_STAGE2_FAILS=2 S6_VERBOSITY=1

VOLUME ["/config", "/data"]
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=5 CMD ["curl", "-fs", "http://127.0.0.1/up"]

ENTRYPOINT ["/init"]
CMD []
