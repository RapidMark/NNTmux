# syntax=docker/dockerfile:1
# Self-contained NNTmux for Unraid: upstream app image + MariaDB, Redis and Manticore under s6.
# Build the app image first from the repo root with the upstream Dockerfile (see Makefile).
ARG APP_IMAGE=nntmux-app:build
FROM ${APP_IMAGE}

ARG S6_OVERLAY_VERSION=3.2.3.2
ARG MARIADB_VERSION=11.4

USER 0

RUN apt-get update && apt-get install -y --no-install-recommends xz-utils wget gnupg redis-server \
    && install -d /etc/apt/keyrings \
    && wget -qO /etc/apt/keyrings/mariadb-keyring.pgp https://mariadb.org/mariadb_release_signing_key.pgp \
    && echo "deb [signed-by=/etc/apt/keyrings/mariadb-keyring.pgp] https://deb.mariadb.org/${MARIADB_VERSION}/debian bookworm main" > /etc/apt/sources.list.d/mariadb.list \
    && wget -qO /tmp/manticore-repo.deb https://repo.manticoresearch.com/manticore-repo.noarch.deb \
    && dpkg -i /tmp/manticore-repo.deb && rm /tmp/manticore-repo.deb \
    && apt-get update && apt-get install -y --no-install-recommends mariadb-server mariadb-client manticore \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz /tmp/
RUN tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz \
    && tar -C / -Jxpf /tmp/s6-overlay-x86_64.tar.xz \
    && rm /tmp/s6-overlay-*.tar.xz

COPY root/ /
RUN chmod +x /etc/s6-overlay/scripts/* /etc/s6-overlay/s6-rc.d/*/run

ENV PUID=99 PGID=100 UMASK=002 TZ=Etc/UTC \
    START_INDEXER=true \
    CHECK_PASSWORDED_RARS=true ADD_PAR2=true PULSE_ENABLED=false FIX_NAMES=true \
    XDG_CONFIG_HOME=/config/caddy/config XDG_DATA_HOME=/config/caddy/data \
    S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 S6_BEHAVIOUR_IF_STAGE2_FAILS=2 S6_VERBOSITY=1

VOLUME ["/config", "/data"]
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=5 CMD ["curl", "-fs", "http://127.0.0.1/up"]

ENTRYPOINT ["/init"]
CMD []
