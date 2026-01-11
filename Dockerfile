# Base image
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    curl \
    nano \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd

# Enable Apache rewrite module
RUN a2enmod rewrite

# Configure Apache to serve /public
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf

# Copy Laravel app
COPY . .

# Mark /var/www/html safe for git
RUN git config --global --add safe.directory /var/www/html

# Install composer (copy from official composer image)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies (include dev for Scribe)
RUN composer install --optimize-autoloader --no-interaction

# Set permissions for storage and cache
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Expose port 80
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
