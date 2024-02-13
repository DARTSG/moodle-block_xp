# Use an official PHP runtime as a parent image
FROM php:8.1-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libzip-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    default-mysql-client \
    libpng-dev \
    libicu-dev \
    zip \
    unzip \
    && docker-php-ext-install -j$(nproc) iconv \
    && docker-php-ext-install -j$(nproc) pdo_pgsql \
    && docker-php-ext-install -j$(nproc) zip \
    && docker-php-ext-install -j$(nproc) pdo_mysql \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install -j$(nproc) intl \
    && docker-php-ext-install -j$(nproc) mysqli

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install Node.js and NVM
ENV NVM_DIR /root/.nvm
ENV NODE_VERSION 16.20.2
RUN curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash \
    && . $NVM_DIR/nvm.sh \
    && nvm install $NODE_VERSION \
    && nvm alias default $NODE_VERSION \
    && nvm use default

ENV NODE_VERSION_DIR $NVM_DIR/versions/node/v$NODE_VERSION
ENV NODE_PATH $NODE_VERSION_DIR/lib/node_modules
ENV PATH      $NODE_VERSION_DIR/bin:$PATH

# Set locale
RUN apt-get install -y locales \
    && sed -i '/en_AU.UTF-8/s/^# //g' /etc/locale.gen \
    && locale-gen en_AU.UTF-8

RUN echo "max_input_vars = 5000" >> /usr/local/etc/php/conf.d/docker-php-ext-max-input-vars.ini

# Install Moodle Plugin CI
ENV COMPOSER_ALLOW_SUPERUSER 1
RUN composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
ENV PATH="/var/www/html/ci/bin:/var/www/html/ci/vendor/bin:${PATH}"

# Install Moosh
RUN git clone https://github.com/tmuras/moosh.git \
    && cd moosh \
    && composer install
ENV PATH="/var/www/html/moosh:${PATH}"

# Copy the application code to the container
COPY . /var/www/html/plugin

# Just to keep the container running
CMD ["apache2-foreground"]
