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

def select_iter(cursor, fields, tail, vars = None):
    """ Execute SELECT <fields> <tail> on cursor.
        Returns an iterator of OrderedDict([(fieldname : value)...])
        Watchout for closed cursor!
    """
    if isinstance(fields, str):
        fields = fields.replace(',', ' ').split()
    cursor.execute('SELECT ' + ', '.join(map(str, fields)) + ' ' + tail, vars)
    def to_dict(result):
        return OrderedDict(zip(fields, result))
    return (to_dict(result) for result in cursor)

def select(cursor, fields, tail, vars = None):
    """ Execute SELECT <fields> <tail> on cursor.
        Returns a list of OrderedDict([(fieldname : value)...])
    """
    return list(select_iter(cursor, fields, tail, vars))

def select_one(cursor, fields, tail, vars = None):
    """ Execute SELECT <fields> <tail> on cursor.
        Returns a single OrderedDict([(fieldname : value)...]) or None
        Asserts only one row is found.
    """
    i = select_iter(cursor, fields, tail, vars)
    result = next(i, None)
    assert next(i, None) is None, 'select_one found more than one row'
    return result
