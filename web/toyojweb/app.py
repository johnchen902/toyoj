from flask import Flask, url_for, render_template
from toyojweb import database
from toyojweb.blueprints import user

app = Flask(__name__)
app.jinja_env.trim_blocks = True
app.jinja_env.lstrip_blocks = True

app.register_blueprint(user.blueprint, url_prefix = '/users')
app.teardown_appcontext(database.close)

@app.route('/')
def index():
    return render_template('index.html')
