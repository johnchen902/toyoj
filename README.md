# Toy Online Judge

This is a very normal Online Judge.

***WORK IN PROGRESS***

## Installation Guide

*It works on my machine*, which is an Arch Linux with user namespace enabled.
1. Setup PostgreSQL
   1. Setup PostgreSQL usually
   2. Create postgresql user **toyojweb** and **toyojjudge**
   3. Create database **toyoj** from toyoj.sql
   4. Allow user **http** to login as **toyojweb**
   5. Allow user **judge** to login as **toyojjudge**
2. Setup php-fpm
   1. Setup php-fpm usually
   2. Install **php-pgsql** from official repository
   3. Enable **pdo_pgsql.so** in /etc/php/php.ini
3. Setup Nginx
   1. Setup Nginx usually
   2. Runs `composer install` in web/
   3. Copy web/ to /srv/http/toyoj
   4. Modify /srv/http/toyoj/config/config.php if needed
   4. Include toyoj.conf in a server block of /etc/nginx/nginx.conf
4. Setup judge back-end
   1. Install **python** from official repository
   2. Install **cachetools** and **asyncpg** from pypi
   3. ??? (WIP)
