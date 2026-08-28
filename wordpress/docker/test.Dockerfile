# The digest pins the multi-platform index for PHP 8.3 on Debian Bookworm.
FROM php:8.3-cli-bookworm@sha256:177529735599a8244b2c903522f029839dce1c2ac4be122fdc00ada4b45a20e4

# Composer is copied from its pinned official image. The PHP extensions match
# the plugin's runtime and the tools used by install-wp-tests.sh.
COPY --from=composer:2.8.11@sha256:68e926a477000f12e8645e82a020b84904d49071c895c4951551fe80eed5d103 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        ca-certificates \
        curl \
        git \
        libicu-dev \
        libonig-dev \
        mariadb-client \
        subversion \
        unzip \
        zip \
    && docker-php-ext-install intl mbstring mysqli \
    && rm -rf /var/lib/apt/lists/*

COPY wordpress/bin/run-docker-tests.sh /usr/local/bin/run-docker-tests
RUN chmod 0755 /usr/local/bin/run-docker-tests

WORKDIR /workspace/wordpress
ENTRYPOINT ["/usr/local/bin/run-docker-tests"]
