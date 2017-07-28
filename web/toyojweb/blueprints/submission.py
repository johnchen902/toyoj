from collections import namedtuple
from flask import Blueprint, render_template, abort
from toyojweb import database

blueprint = Blueprint('submission', __name__)

@blueprint.route('/')
def showall():
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            submissions = database.select(cur, '''
                id problem_id submitter_id language_name submit_time
                problem_title submitter_username
                testcases_count
                results_count time memory judge_time
                verdict_id verdict
            ''', '''
                FROM submissions_view
                ORDER BY id DESC
            ''')

    return render_template('submission-showall.html', submissions = submissions)

@blueprint.route('/<int:submission_id>/')
def show(submission_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            submission = database.select_one(cur, '''
                id problem_id submitter_id language_name submit_time
                problem_title submitter_username
                testcases_count
                results_count time memory judge_time
                verdict_id verdict
                code
            ''', '''
                FROM submissions_view
                WHERE id = %s
            ''', (submission_id,))
            if not submission:
                abort(404)

            submission['testcases'] = database.select(cur, '''
                testcase_id accepted verdict time memory judge_name judge_time
            ''', '''
                FROM results_view
                WHERE submission_id = %s
            ''', (submission_id,))

    return render_template('submission-show.html', submission = submission)
