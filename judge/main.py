#!/usr/bin/env python3
import asyncio
import asyncpg
import logging
import signal
import sys
from collections import namedtuple

class JudgeTask:
    def __init__(self, problem_id, submission_id, testcase_id):
        self.problem_id = problem_id
        self.submission_id = submission_id
        self.testcase_id = testcase_id
        self.accepted = None
        self.time = None
        self.memory = None
        self.verdict = None

Submission = namedtuple("Submission", ["language_name", "code"])
TestCase = namedtuple("TestCase", ["time_limit", "memory_limit",
        "checker_name", "input", "output"])

class TaskFetcher:
    def __init__(self, judge_name, pool):
        self._judge_name = judge_name
        self._pool = pool

    async def fetch(self):
        async with self._pool.acquire() as conn:
            event = asyncio.Event()
            def listener(con_ref, pid, channel, payload):
                event.set()
            await conn.add_listener("new_judge_task", listener)
            while True:
                event.clear()
                task = await self._fetch_nullable(conn)
                if task is not None:
                    return task
                await event.wait()

    async def fetch_nullable(self):
        async with self._pool.acquire() as conn:
            return await self._fetch_nullable(conn)

    async def _fetch_nullable(self, conn):
        row = await conn.fetchrow("""
            INSERT INTO result_judges (problem_id, submission_id, testcase_id, judge_name)
            (SELECT problem_id, submission_id, testcase_id, $1 AS judge_name
                FROM results_view
                WHERE judge_name IS NULL AND accepted IS NULL
                LIMIT 1)
            RETURNING problem_id, submission_id, testcase_id
        """, self._judge_name)
        if row is None:
            return None
        task = JudgeTask(row["problem_id"], row["submission_id"],
                         row["testcase_id"])
        task.submission = Submission(**await conn.fetchrow("""
            SELECT language_name, code
            FROM submissions
            WHERE id = $1
        """, task.submission_id))
        task.testcase = TestCase(**await conn.fetchrow("""
            SELECT time_limit, memory_limit, checker_name, input, output
            FROM testcases
            WHERE id = $1
        """, task.testcase_id))
        return task

    async def cancel_unfinished(self):
        await self._pool.execute("""
            DELETE FROM result_judges AS x USING results_view AS y
            WHERE x.submission_id = y.submission_id
                AND x.testcase_id = y.testcase_id
                AND y.judge_name = $1
                AND y.accepted IS NULL;
        """, self._judge_name)

class TaskRunner:
    def __init__(self):
        pass

    async def run(self, task):
        print("Running ", task.submission, task.testcase, "...")
        await asyncio.sleep(1.0)
        task.accepted = False
        task.time = 0
        task.memory = 0
        task.verdict = "XX"

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
    async with asyncpg.create_pool(dsn, min_size = 1, max_size = 2) as pool:
        task_fetcher = TaskFetcher("test-2", pool)
        task_runner = TaskRunner()
        task_writer = TaskWriter(pool)

        async def run_and_write(task):
            await task_runner.run(task)
            await task_writer.write(task)

        def log_exception(future):
            if not future.cancelled():
                exc = future.exception()
                if exc is not None:
                    logging.exception(exc)

        pending = set()
        try:
            while True:
                task = await task_fetcher.fetch()
                future = asyncio.ensure_future(run_and_write(task))
                future.add_done_callback(log_exception)
                pending.add(future)
                _, pending = await asyncio.wait(pending, timeout = 0)
        finally:
            for p in pending:
                p.cancel()
            if(pending):
                await asyncio.wait(pending)
            await task_fetcher.cancel_unfinished()

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
    loop.call_soon_threadsafe(terminate)

signal.signal(signal.SIGINT, signal_handler)

try:
    loop.run_until_complete(task)
except asyncio.CancelledError:
    pass
