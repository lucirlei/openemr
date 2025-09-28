version: "3.9"

services:
  web:
    image: openemr/openemr:flex-3.22-php-8.2
    container_name: openemr-web
    restart: unless-stopped
    environment:
      MYSQL_HOST: db
      MYSQL_ROOT_PASS: root
      MYSQL_USER: openemr
      MYSQL_PASS: openemr
      OE_USER: admin
      OE_PASS: pass
      APACHE_DOCUMENT_ROOT: /var/www/localhost/htdocs/openemr/public
    ports:
      - "8080:80"
      - "8443:443"
    volumes:
      - ./:/var/www/localhost/htdocs/openemr:rw
    depends_on:
      db:
        condition: service_healthy

  php:
    image: openemr/openemr:flex-3.22-php-8.2
    container_name: openemr-php
    restart: unless-stopped
    working_dir: /var/www/localhost/htdocs/openemr
    command: ["/bin/sh", "-c", "while true; do sleep 3600; done"]
    environment:
      MYSQL_HOST: db
      MYSQL_ROOT_PASS: root
      MYSQL_USER: openemr
      MYSQL_PASS: openemr
    volumes:
      - ./:/var/www/localhost/htdocs/openemr:rw
    depends_on:
      db:
        condition: service_started

  db:
    image: mariadb:11.8
    container_name: openemr-db
    restart: unless-stopped
    command:
      - mariadbd
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: openemr
      MYSQL_USER: openemr
      MYSQL_PASSWORD: openemr
    ports:
      - "3306:3306"
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s

volumes:
  db_data:
