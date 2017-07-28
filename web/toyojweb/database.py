from flask import g
import psycopg2

def connect():
    conn = getattr(g, '_conn', None)
    if conn is None or conn.closed:
        # TODO add config for DSN
        conn = g._conn = psycopg2.connect('postgresql:///toyoj')
    return conn

def close(exception):
    conn = getattr(g, '_conn', None)
    if conn is not None:
        conn.close()
