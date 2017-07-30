from collections import OrderedDict
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

def fetch_iter(cursor, query, vars = None):
    """ Execute query on cursor.
        Returns an iterator of OrderedDict([(fieldname : value)...])
        Watchout for closed cursor!
    """
    cursor.execute(query, vars)
    fields = [desc.name for desc in cursor.description]
    return (OrderedDict(zip(fields, result)) for result in cursor)

def fetch_list(cursor, query, vars = None):
    """ Execute query on cursor.
        Returns a list of OrderedDict([(fieldname : value)...])
    """
    return list(fetch_iter(cursor, query, vars))

def fetch_one(cursor, query, vars = None):
    """ Execute query on cursor.
        Returns a single OrderedDict([(fieldname : value)...]) or None
        Asserts only one row is found.
    """
    i = fetch_iter(cursor, query, vars)
    result = next(i, None)
    assert next(i, None) is None, 'fetch_one found more than one row'
    return result
