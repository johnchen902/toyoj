from collections import namedtuple
from flask import Blueprint, render_template, abort
from toyojweb import database

blueprint = Blueprint('problem', __name__)

@blueprint.route('/')
def showall():
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            problems = database.select(cur,
            ''' id title manager_id create_time
                manager_username ''',
            ''' FROM problems_view ORDER BY id ''')

    return render_template('problem-showall.html', problems = problems)

@blueprint.route('/<int:problem_id>/')
def show(problem_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            problem = database.select_one(cur,
            ''' id title statement manager_id create_time
                manager_username ''',
            ''' FROM problems_view WHERE id = %s ''',
                (problem_id,))
            if not problem:
                abort(404)

            problem['testcases'] = database.select(cur,
                'id time_limit memory_limit checker_name',
                'FROM testcases WHERE problem_id = %s',
                (problem_id,))

    return render_template('problem-show.html', problem = problem)

@blueprint.route('/<int:problem_id>/edit')
def edit(problem_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            problem = database.select_one(cur,
            ''' id title statement manager_id create_time
                manager_username ''',
            ''' FROM problems_view WHERE id = %s ''',
                (problem_id,))
            if not problem:
                abort(404)

    return render_template('problem-showsource.html', problem = problem)
