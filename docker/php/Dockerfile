FROM php:8.5-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpq-dev \
    && docker-php-ext-install zip pdo_pgsql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN git config --system --add safe.directory /workspace
WORKDIR /workspace/packages/engine
