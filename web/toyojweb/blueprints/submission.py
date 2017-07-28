from collections import namedtuple
from flask import Blueprint, render_template, abort
from toyojweb import database

blueprint = Blueprint('submission', __name__)

ShortSubmission = namedtuple('ShortSubmission', '''
    id problem_id submitter_id language_name submit_time
    problem_title submitter_username
    testcases_count
    results_count time memory judge_time
    verdict_id verdict
''')
Submission = namedtuple('Submission',
        ' '.join(ShortSubmission._fields) + ' code')

Result = namedtuple('Result', '''
    testcase_id accepted verdict time memory judge_name judge_time
''')

@blueprint.route('/')
def showall():
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            cur.execute(
            ''' SELECT ''' + ', '.join(ShortSubmission._fields) +
            ''' FROM submissions_view
                ORDER BY id DESC
            ''')
            submissions = [ShortSubmission(*submission) for submission in cur]

    return render_template('submission-showall.html', submissions = submissions)

@blueprint.route('/<int:submission_id>/')
def show(submission_id):
    with database.connect() as conn:
        conn.readonly = True
        with conn.cursor() as cur:
            cur.execute(
            ''' SELECT ''' + ', '.join(Submission._fields) +
            ''' FROM submissions_view
                WHERE id = %s
            ''', (submission_id,))
            submission = cur.fetchone()
            if submission is None:
                abort(404)
            submission = Submission(*submission)._asdict()

            cur.execute(
            ''' SELECT ''' + ', '.join(Result._fields) +
            ''' FROM results_view
                WHERE submission_id = %s
            ''', (submission_id,))
            submission['testcases'] = [Result(*result) for result in cur]

    return render_template('submission-show.html', submission = submission)
