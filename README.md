# Toy Online Judge

This is a very normal Online Judge.

***Web rewrite in progress***

## Installation Guide

This is what I did on my machine.
Adapt if necessary and use your wisdom.
I'm using Arch Linux, with kernel *linux-userns* from AUR.

1. Setup Database
   1. Install and setup PostgreSQL (*postgresql*)
   2. Create postgresql user *toyojweb* and *toyojjudge*
   3. Create database *toyoj* from toyoj.sql
   4. Allow user *http* to login as *toyojweb*
      * If you prefer password, modify the dsn in step 2-6
   5. Allow user *toyojjudge* to login as *toyojjudge*
      * If you prefer password, modify the dsn in step 3-8
2. Setup Web Server
   1. Install Nginx (*nginx-mainline*), Python (*python*), uWSGI (*uwsgi*),
      its Python plugin (*uwsgi-plugin-python*), Flask (*python-flask*),
      Psycopg (*python-psycopg2*), Python-Markdown (*python-markdown*) and
      Bleach (*python-bleach*)
   2. Copy web/toyoj/ to /srv/http/toyoj (???)
      * Remember to edit config (???)
   3. Copy web/uswgi.ini to /etc/uwsgi/toyoj.ini (???)
   4. Copy nginx.conf to /etc/nginx/toyoj.conf (???)
      * Include /etc/nginx/toyoj.conf in a server block of /etc/nginx/nginx.conf (???)
3. Setup Judge Backend
   1. Install GCC (*gcc*), Make (*make*), Python (*python*) and setuptools (*python-setuptools*)
   2. Run `make release` in sandbox/
   3. Copy sandbox/sandbox to /usr/bin/sandbox
   4. Run `python setup.py install` in judge/
   5. Install various compilers for language support
   6. Run `chmod 4755 /usr/bin/newuidmap /usr/bin/newgidmap`
   7. Add system user *toyojjudge* and allocate at least 1 subuid and 1 subgid
   8. Run `toyojjudge` as user *toyojjudge*
   9. Run `judge/grant-cgroups PID_OF_JUDGE` as root in one minute
