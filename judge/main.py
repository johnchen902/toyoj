#!/usr/bin/env python3
import asyncio
import asyncpg
import signal
import sys

class TaskFetchLoop:
    def __init__(self, judge_name, pool, task_runner):
        self._judge_name = judge_name
        self._pool = pool
        self._task_runner = task_runner

    async def run(self):
        task_set = set()
        try:
            while True:
                task = await self._fetch_task()
                task_set.add(asyncio.ensure_future(self._run_task(task)))
                _, task_set = await asyncio.wait(task_set, timeout = 0)
        finally:
            if(task_set):
                await asyncio.shield(asyncio.wait(task_set))

    async def _run_task(self, task):
        problem_id = task["problem_id"]
        submission_id = task["submission_id"]
        testcase_id = task["testcase_id"]
        accepted, time, memory, verdict = \
                await self._task_runner.run(submission_id, testcase_id)
        await self._write_result(problem_id, submission_id, testcase_id,
                accepted, time, memory, verdict)

    async def _fetch_task(self):
        while True:
            task = await self._fetch_task_nullable()
            if task is not None:
                return task
            await asyncio.sleep(1)
    async def _fetch_task_nullable(self):
        return await self._pool.fetchrow("""
            INSERT INTO result_judges (problem_id, submission_id, testcase_id, judge_name)
            (SELECT problem_id, submission_id, testcase_id, $1 AS judge_name
                FROM results_view
                WHERE judge_name IS NULL AND accepted IS NULL
                LIMIT 1)
            RETURNING problem_id, submission_id, testcase_id
        """, self._judge_name)
    async def _write_result(self, problem_id, submission_id, testcase_id,
            accepted, time, memory, verdict):
        return await self._pool.execute("""
            INSERT INTO results (problem_id, submission_id, testcase_id,
                accepted, time, memory, verdict)
            VALUES ($1, $2, $3, $4, $5, $6, $7)
        """, problem_id, submission_id, testcase_id,
                accepted, time, memory, verdict)

class TaskRunner:
    def __init__(self, pool):
        self._pool = pool

    async def run(self, submission_id, testcase_id):
        submission = await self._fetch_submission(submission_id)
        testcase = await self._fetch_testcase(testcase_id)
        print("Running ", submission, testcase, "...")
        await asyncio.sleep(1.0)
        return False, 0, 0, "XX"

    async def _fetch_submission(self, submission_id):
        return await self._pool.fetchrow("""
            SELECT language_name, code
            FROM submissions
            WHERE id = $1
        """, submission_id)
    async def _fetch_testcase(self, testcase_id):
        return await self._pool.fetchrow("""
            SELECT time_limit, memory_limit, checker_name, input, output
            FROM testcases
            WHERE id = $1
        """, testcase_id)

async def run(dsn):
    async with asyncpg.create_pool(dsn, min_size = 1, max_size = 1) as pool:
        task_runner = TaskRunner(pool)
        task_fetch_loop = TaskFetchLoop("test-2", pool, task_runner)

        await task_fetch_loop.run()

if len(sys.argv) != 2:
    print("Usage: ./main.py DSN", file = sys.stderr)
    print("See asyncpg document for DSN format", file = sys.stderr)
    sys.exit(1)
dsn = sys.argv[1]

loop = asyncio.get_event_loop()
task = asyncio.ensure_future(run(dsn))

def terminate():
    task.cancel()
def signal_handler(a, b):
    terminate()

signal.signal(signal.SIGINT, signal_handler)

try:
    loop.run_until_complete(task)
except asyncio.CancelledError:
    pass
