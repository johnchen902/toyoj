# Toy Online Judge

This is a very normal Online Judge.

***Web rewrite in progress***

## Installation Guide

This is what I did on my machine.
Adapt if necessary and use your wisdom.
I'm using Arch Linux, with kernel *linux-userns* from AUR.

1. Install sandbox:
   1. Make sure `unshare -U` works without root.
      * In other words, make sure unprivilleged user can create user namespace.
        Otherwise, the sandbox won't work.
   2. Install GCC (*gcc*) and make (*make*)
   3. Run `make release` in sandbox/
      * Note that flags like `-O2` are not enabled by default.
        You are expected to use `env CFLAGS=...` to alter the behavior.
   4. Copy sandbox/sandbox to /usr/bin/sandbox
   5. Run `chmod 4755 /usr/bin/newuidmap /usr/bin/newgidmap`
2. Install `toyoj` package
   1. Install Python (*python*) and setuptools (*python-setuptools*)
   2. Install the following dependency if you want the Arch version:
      * Flask (*python-flask*)
      * Psycopg (*python-psycopg2*)
      * Python-Markdown (*python-markdown*)
      * Bleach (*python-bleach*)
      * asyncpg (*python-asyncpg<sup>AUR</sup>*)
      * ConfigArgParse (*python-configargparse*)
   3. Run `python setup.py install` in python/
   4. Optionally install the following package for language support:
      * The GNU Compiler Collection (*gcc*)
      * The Glasgow Haskell Compiler (*ghc*)
3. Setup Database
   1. Install and setup PostgreSQL (*postgresql*)
   2. Create postgresql user *toyojweb* and *toyojjudge*
      * Any local user can connect as any database user by default.
        Deviate the default and change default DSN.
   3. Create a database (named *toyoj* or change default DSN) from toyoj.sql
4. Setup Web Server
   1. Install Nginx (*nginx-mainline*), Python (*python*), uWSGI (*uwsgi*)
      and its Python plugin (*uwsgi-plugin-python*) (???)
   2. Edit and copy web.conf to /etc/toyoj-web.conf (???)
   3. Copy web/uswgi.ini to /etc/uwsgi/toyoj.ini (???)
   4. Include nginx.conf in a server block of /etc/nginx/nginx.conf (???)
5. Setup Judge Backend
   1. See `toyojjudge --help`
   2. Run `toyojjudge [OPTIONS]`
   3. Run `./grant-cgroups PID_OF_JUDGE` as root in one minute
      * Or run `sudo ./grant-cgroups $$` in the same shell before step ii.
