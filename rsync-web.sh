#!/usr/bin/env bash
rsync $(dirname $0)/web/ /srv/http/toyoj -lru --progress --delete --exclude config/
rsync $(dirname $0)/toyoj.conf /etc/nginx/ -u --progress
