ARG PHP_CLI_IMAGE=php:8.4.27-cli-bookworm
ARG COMPOSER_IMAGE=composer:2
ARG PECL_REDIS_VERSION=6.3.0
# 国内 apt 默认清华源；不稳定时可构建参数覆盖，例如 mirrors.ustc.edu.cn / mirrors.aliyun.com
ARG DEBIAN_MIRROR=mirrors.tuna.tsinghua.edu.cn

FROM ${COMPOSER_IMAGE} AS composer_bin

FROM ${PHP_CLI_IMAGE}

ARG PECL_REDIS_VERSION
ARG DEBIAN_MIRROR=mirrors.tuna.tsinghua.edu.cn

WORKDIR /var/www/html

# 使用可配置 Debian 镜像源，并开启 apt 重试（Bookworm：deb822 或经典 sources.list）
RUN set -eux; \
    printf 'Acquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' \
        > /etc/apt/apt.conf.d/80-retries; \
    if [ -f /etc/apt/sources.list.d/debian.sources ]; then \
        sed -i "s|deb.debian.org|${DEBIAN_MIRROR}|g" /etc/apt/sources.list.d/debian.sources; \
        sed -i "s|security.debian.org|${DEBIAN_MIRROR}|g" /etc/apt/sources.list.d/debian.sources; \
    elif [ -f /etc/apt/sources.list ]; then \
        sed -i "s|deb.debian.org|${DEBIAN_MIRROR}|g" /etc/apt/sources.list; \
        sed -i "s|security.debian.org|${DEBIAN_MIRROR}|g" /etc/apt/sources.list; \
    fi

RUN set -eux; \
    apt-get update; \
    attempt=1; \
    until apt-get install -y --no-install-recommends \
        curl \
        ffmpeg \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        postgresql-client \
        unzip; do \
      if [ "$attempt" -ge 5 ]; then exit 1; fi; \
      attempt=$((attempt + 1)); \
      echo "apt-get install failed, retry ${attempt}/5..."; \
      apt-get update || true; \
      sleep 5; \
    done; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install \
        gd \
        mbstring \
        pdo_pgsql \
        pdo_sqlite \
        pcntl \
        zip; \
    pecl install "redis-${PECL_REDIS_VERSION}"; \
    docker-php-ext-enable redis; \
    apt-get purge -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev; \
    rm -rf /tmp/pear ~/.pearrc; \
    rm -rf /var/lib/apt/lists/*

# COMPOSER_IMAGE 默认 composer:2；国内可先加速拉取并本地 tag 后再构建。
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_PROCESS_TIMEOUT=2000

# 本地 Compose 会把工作区挂载到 /var/www/html。开发镜像只保留 PHP、
# Composer、入口脚本和运行时配置，避免将会被挂载覆盖的源码、vendor
# 以及本机 storage 产物重复写入镜像。生产镜像由 Dockerfile.prod 构建。

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/zz-opcache-dev.ini
COPY docker/php/php-docker-overrides.ini /usr/local/etc/php/conf.d/99-overrides.ini
COPY docker/entrypoint.sh /usr/local/bin/geoflow-entrypoint

RUN chmod +x /usr/local/bin/geoflow-entrypoint

ENTRYPOINT ["/usr/local/bin/geoflow-entrypoint"]
CMD ["php", "artisan", "about"]
