FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_sqlite curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
