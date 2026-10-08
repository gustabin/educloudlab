# CI / Linux verification image (M12): PHP CLI + the extensions the app needs + Python 3.11 runner venv.
# The application code is copied in (never mounted), so a developer's .env, vendor/ or venv never leak into a run.
# Base images are pinned by digest; bump them deliberately (docs/development/CI.md).
ARG PHP_IMAGE=php:8.3-cli-bookworm@sha256:c24b55afdf860c874b9ec6267ced132ff52de97d308ab08e6cf41e8596e509f7

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM ${PHP_IMAGE}
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 python3-venv libicu-dev libzip-dev unzip git curl \
 && docker-php-ext-install mysqli intl zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/ci.ini

# Runner venv: the same hash-pinned wheels as production (manylinux hash in worker/requirements.txt).
COPY worker/requirements.txt worker/requirements-dev.txt /tmp/req/
RUN python3 -m venv /opt/venv \
 && /opt/venv/bin/pip install --no-cache-dir --require-hashes --only-binary=:all: -r /tmp/req/requirements.txt \
 && /opt/venv/bin/pip install --no-cache-dir -r /tmp/req/requirements-dev.txt

RUN useradd --create-home --uid 10010 ci && mkdir /app && chown ci /app
USER ci
WORKDIR /app

# Dependencies first (cached layer), then the code.
COPY --chown=ci composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader
COPY --chown=ci . .
RUN composer dump-autoload --optimize && chmod +x ci/*.sh

CMD ["ci/run.sh"]
