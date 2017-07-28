from datetime import datetime
from flask import Blueprint, render_template, abort

blueprint = Blueprint('user', __name__)

@blueprint.route('/')
def showall():
    users = [ {
        'id': 1,
        'username': 'test<br>',
        'register_time': datetime.now().astimezone()
    } ]
    return render_template('user-showall.html', users = users)

@blueprint.route('/<int:user_id>/')
def show(user_id):
    if user_id != 1:
        abort(404)
    user = {
        'id': 1,
        'username': 'test<br>',
        'register_time': datetime.now().astimezone()
    }
    return render_template('user-show.html', user = user)
