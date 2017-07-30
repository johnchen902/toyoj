from flask import Blueprint, render_template, abort
from .. import database

blueprint = Blueprint('problem', __name__)

@blueprint.route('/')
def showall():
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            problems = database.fetch_list(cur,
            ''' SELECT
                    p.id, p.title, p.manager_id, p.create_time,
                    u.username AS manager_username
                FROM problems p
                JOIN users u ON (p.manager_id = u.id)
                ORDER BY id
            ''')

    return render_template('problem-showall.html', problems = problems)

@blueprint.route('/<int:problem_id>/')
def show(problem_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            problem = database.fetch_one(cur,
            ''' SELECT
                    p.id, p.title, p.manager_id, p.create_time, p.statement,
                    u.username AS manager_username
                FROM problems p
                JOIN users u ON (p.manager_id = u.id)
                WHERE p.id = %s
            ''', (problem_id,))
            if not problem:
                abort(404)

            problem['testcases'] = database.fetch_list(cur,
            ''' SELECT
                    apparent_id, time_limit, memory_limit, checker_name
                FROM testcases
                WHERE problem_id = %s
                ORDER BY apparent_id
            ''', (problem_id,))

    return render_template('problem-show.html', problem = problem)

@blueprint.route('/<int:problem_id>/edit')
def edit(problem_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            problem = database.fetch_one(cur,
            ''' SELECT
                    p.id, p.title, p.manager_id, p.create_time, p.statement,
                    u.username AS manager_username
                FROM problems p
                JOIN users u ON (p.manager_id = u.id)
                WHERE p.id = %s
            ''', (problem_id,))
            if not problem:
                abort(404)

    return render_template('problem-showsource.html', problem = problem)
