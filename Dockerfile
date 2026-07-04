FROM php:8.3-apache

RUN apt-get update && \
    apt-get install -y \
    ghostscript \
    qpdf && \
    rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN mkdir -p /var/www/html/temp && \
    chmod -R 775 /var/www/html/temp

EXPOSE 80
