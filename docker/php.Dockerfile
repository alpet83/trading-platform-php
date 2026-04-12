FROM php:8.4-cli

ENV APP_DIR=/app \
    COMPOSER_ALLOW_SUPERUSER=1 \
    DEBIAN_FRONTEND=noninteractive

WORKDIR ${APP_DIR}

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    git \
    unzip \
    pass \
    gnupg \
    gpg-agent \
    pinentry-curses \
    libcurl4-openssl-dev \
    libssl-dev \
    libmariadb-dev \
    libonig-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libyaml-dev \
    procps \
    screen \
    less \
    traceroute \    
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp

RUN docker-php-ext-install -j"$(nproc)" \
    curl \
    mysqli \
    mbstring \
    pcntl \
    sockets \
    bcmath \
    gd

# Xdebug for debugging; yaml for native YAML parsing (replaces ParseSimpleYamlBlock fallback)
RUN pecl install xdebug yaml \
 && docker-php-ext-enable xdebug yaml

# Optional (future async runtime):
# RUN pecl install swoole && docker-php-ext-enable swoole

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Option A (default): clone external library in image build
RUN git clone --depth=1 https://github.com/alpet83/alpet-libs-php ${APP_DIR}/lib

# Datafeed runtime sources and dependencies (hourly exchange loaders)
RUN git clone --depth=1 https://github.com/alpet83/datafeed ${APP_DIR}/datafeed \
 && mkdir -p ${APP_DIR}/datafeed/src/logs \
 && cd ${APP_DIR}/datafeed \
 && composer require --no-interaction --prefer-dist arthurkushman/php-wss smi2/phpclickhouse

# Provide shared ClickHouse helper to datafeed without extra volume mounts
RUN cp ${APP_DIR}/lib/clickhouse.php ${APP_DIR}/datafeed/lib/clickhouse.php

# Option B (alternative): use git submodule in repo instead of clone
#   git submodule add https://github.com/alpet83/alpet-libs-php lib
# Then replace the RUN git clone line with:
#   COPY lib ${APP_DIR}/lib

COPY src/composer.json ${APP_DIR}/src/composer.json
RUN cd ${APP_DIR}/src && composer install --no-dev --no-interaction --prefer-dist

COPY src ${APP_DIR}/src
COPY docker/entrypoints/*.sh /usr/local/bin/
COPY docker/php/conf.d/*.ini /usr/local/etc/php/conf.d/
RUN chmod +x /usr/local/bin/*.sh

WORKDIR ${APP_DIR}/src
CMD ["php", "-v"]