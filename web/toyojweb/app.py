from flask import Flask, url_for, render_template
from toyojweb import database, markdown
from toyojweb.blueprints import user, submission, problem

app = Flask(__name__)
app.jinja_env.trim_blocks = True
app.jinja_env.lstrip_blocks = True
app.jinja_env.filters['markdown'] = markdown.safe_markdown

app.register_blueprint(problem.blueprint, url_prefix = '/problems')
app.register_blueprint(submission.blueprint, url_prefix = '/submissions')
app.register_blueprint(user.blueprint, url_prefix = '/users')

app.teardown_appcontext(database.close)

@app.route('/')
def index():
    return render_template('index.html')
