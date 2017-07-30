from flask import Blueprint, render_template, abort
from .. import database

blueprint = Blueprint('testcase', __name__)

@blueprint.route('/<int:testcase_id>/')
def show(problem_id, testcase_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            testcase = database.fetch_one(cur,
            ''' SELECT
                    t.id, t.problem_id, t.apparent_id,
                    t.time_limit, t.memory_limit,
                    t.checker_name, t.input, t.output,
                    p.title AS problem_title
                FROM testcases t
                JOIN problems p ON (t.problem_id = p.id)
                WHERE problem_id = %s AND apparent_id = %s
            ''', (problem_id, testcase_id))
            if not testcase:
                abort(404)

    return render_template('testcase-show.html', testcase = testcase)
