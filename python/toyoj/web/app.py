from flask import Flask, url_for, render_template
from . import database, markdown
from .blueprints import user, submission, problem, testcase

app = Flask(__name__)

def init_app(app):
    app.jinja_env.trim_blocks = True
    app.jinja_env.lstrip_blocks = True
    app.jinja_env.filters['markdown'] = markdown.safe_markdown

    blueprints = [
        (problem.blueprint, '/problems'),
        (testcase.blueprint, '/problems/<int:problem_id>/testcases'),
        (submission.blueprint, '/submissions'),
        (user.blueprint, '/users'),
    ]
    for blueprint, url_prefix in blueprints:
        app.register_blueprint(blueprint, url_prefix = url_prefix)

    app.teardown_appcontext(database.close)

init_app(app)

@app.route('/')
def index():
    return render_template('index.html')
