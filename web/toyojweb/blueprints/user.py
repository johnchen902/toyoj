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
            users = database.select(cur, 'id username register_time',
                    'FROM users ORDER BY id')

    return render_template('user-showall.html', users = users)

@blueprint.route('/<int:user_id>/')
def show(user_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            user = database.select_one(cur, 'id username register_time',
                    'FROM users WHERE id = %s', (user_id,))
            if not user:
                abort(404)

    return render_template('user-show.html', user = user)
