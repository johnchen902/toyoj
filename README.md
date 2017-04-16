# Toy Online Judge

*missing description*
***WORK IN PROGRESS***

## Installation Guide

*It works on my machine*, which is an Arch Linux with user namespace enabled.
1. Setup PostgreSQL
   1. Install **postgresql** from official repository.
   2. ??? (See [ArchWiki](https://wiki.archlinux.org/index.php/PostgreSQL))
   3. Enable and start **postgresql.service**
   4. Create postgresql user **toyoj-web** and **toyoj-judge**
   5. Create database **toyoj** from toyoj.sql
2. Setup php-fpm
   1. Install **php-fpm** and **php-pgsql** from official repository.
   2. Enable **pgsql.so** in /etc/php/php.ini.
   3. Enable and start **php-fpm.service**.
3. Setup Nginx
   1. Install **nginx-mainline** from official repository.
   2. Copy ??? to /srv/http/toyoj
   3. Copy ??? to /etc/nginx/nginx.conf
   4. Enable and start **nginx.service**
4. Setup judge back-end
   1. Install **python** from official repository.
   2. Install **cachetools** and **asyncpg** from pypi.
   3. ??? (WIP)
