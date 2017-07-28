from collections import namedtuple
from flask import Blueprint, render_template, abort
from toyojweb import database

blueprint = Blueprint('user', __name__)

User = namedtuple('User', 'id username register_time')

@blueprint.route('/')
def showall():
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            cur.execute(
            ''' SELECT ''' + ', '.join(User._fields) +
            ''' FROM users
                ORDER BY id
            ''')
            users = [User(*user) for user in cur]

    return render_template('user-showall.html', users = users)

@blueprint.route('/<int:user_id>/')
def show(user_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            cur.execute(
            ''' SELECT ''' + ', '.join(User._fields) +
            ''' FROM users
                WHERE id = %s
            ''', (user_id,))
            user = cur.fetchone()
            if user is None:
                abort(404)
            user = User(*user)

    return render_template('user-show.html', user = user)
