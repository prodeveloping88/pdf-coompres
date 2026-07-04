FROM php:8.3-apache

RUN apt-get update && \
    apt-get install -y qpdf && \
    rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

EXPOSE 80
