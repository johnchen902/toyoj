#!/usr/bin/env python3
import asyncio
import asyncpg
import signal
import sys

class JudgeTask:
    def __init__(self, problem_id, submission_id, testcase_id):
        self.problem_id = problem_id
        self.submission_id = submission_id
        self.testcase_id = testcase_id
        self.accepted = None
        self.time = None
        self.memory = None
        self.verdict = None

class TaskFetcher:
    def __init__(self, judge_name, pool):
        self._judge_name = judge_name
        self._pool = pool

    async def fetch(self):
        while True:
            task = await self.fetch_nullable()
            if task is not None:
                return task
            await asyncio.sleep(1)
    async def fetch_nullable(self):
        row = await self._pool.fetchrow("""
            INSERT INTO result_judges (problem_id, submission_id, testcase_id, judge_name)
            (SELECT problem_id, submission_id, testcase_id, $1 AS judge_name
                FROM results_view
                WHERE judge_name IS NULL AND accepted IS NULL
                LIMIT 1)
            RETURNING problem_id, submission_id, testcase_id
        """, self._judge_name)
        if row is None:
            return None
        return JudgeTask(row["problem_id"], row["submission_id"],
                         row["testcase_id"])

class TaskRunner:
    def __init__(self, pool):
        self._pool = pool

    async def run(self, task):
        submission = await self._fetch_submission(task.submission_id)
        testcase = await self._fetch_testcase(task.testcase_id)
        print("Running ", submission, testcase, "...")
        await asyncio.sleep(1.0)
        task.accepted = False
        task.time = 0
        task.memory = 0
        task.verdict = "XX"

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

class TaskWriter:
    def __init__(self, pool):
        self._pool = pool

    async def write(self, task):
        return await self._pool.execute("""
            INSERT INTO results (problem_id, submission_id, testcase_id,
                accepted, time, memory, verdict)
            VALUES ($1, $2, $3, $4, $5, $6, $7)
        """, task.problem_id, task.submission_id, task.testcase_id,
                task.accepted, task.time, task.memory, task.verdict)

async def run(dsn):
    async with asyncpg.create_pool(dsn, min_size = 1, max_size = 1) as pool:
        task_fetcher = TaskFetcher("test-2", pool)
        task_runner = TaskRunner(pool)
        task_writer = TaskWriter(pool)

        async def run_and_write(task):
            await task_runner.run(task)
            await task_writer.write(task)

        pending = set()
        try:
            while True:
                task = await task_fetcher.fetch()
                future = asyncio.ensure_future(run_and_write(task))
                pending.add(future)
                _, pending_tasks = await asyncio.wait(pending, timeout = 0)
        finally:
            if(pending):
                await asyncio.shield(asyncio.wait(pending))

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
