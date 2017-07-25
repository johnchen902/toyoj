import asyncio
import logging
from collections import namedtuple

logger = logging.getLogger(__name__)

class JudgeTask:
    def __init__(self,
            problem_id, submission_id, testcase_id, submission, testcase,
            accepted = None, time = None, memory = None, verdict = None):
        self.problem_id = problem_id
        self.submission_id = submission_id
        self.testcase_id = testcase_id
        self.submission = submission
        self.testcase = testcase
        self.accepted = None
        self.time = None
        self.memory = None
        self.verdict = None
    def __repr__(self):
        return ("JudgeTask(problem_id=%r, submission_id=%r, testcase_id=%r" +
            ", submission=%r, testcase=%r" +
            ", accepted=%r, time=%r, memory=%r, verdict=%r)") % (
            self.problem_id, self.submission_id, self.testcase_id,
            self.submission, self.testcase,
            self.accepted, self.time, self.memory, self.verdict)
    def __str__(self):
        return ("JudgeTask(Problem #%d, Submission #%d, Testcase #%d)") \
                % (self.problem_id, self.submission_id, self.testcase_id)
Submission = namedtuple("Submission", ["language_name", "code"])
TestCase = namedtuple("TestCase", ["time_limit", "memory_limit",
        "checker_name", "input", "output"])

class TaskFetcher:
    def __init__(self, judge_name, pool, language_names):
        self.judge_name = judge_name
        self.pool = pool
        self.language_names = language_names

    async def fetch(self):
        async with self.pool.acquire() as conn:
            event = asyncio.Event()
            def listener(con_ref, pid, channel, payload):
                logger.debug("Received notification")
                event.set()
            await conn.add_listener("new_judge_task", listener)
            while True:
                event.clear()
                task = await self.fetch_nullable_with_conn(conn)
                if task is not None:
                    logger.debug("Fetched %s", task)
                    return task
                logger.debug("No task found; wait for notification...")
                await event.wait()

    async def fetch_nullable(self):
        async with self.pool.acquire() as conn:
            return await self.fetch_nullable_with_conn(conn)

    async def fetch_nullable_with_conn(self, conn):
        row = await conn.fetchrow("""
            INSERT INTO result_judges (problem_id, submission_id, testcase_id, judge_name)
            (SELECT problem_id, submission_id, testcase_id, $1 AS judge_name
                FROM results_view
                WHERE judge_name IS NULL AND accepted IS NULL AND language_name = ANY($2 :: varchar(32)[])
                ORDER BY submission_id ASC, testcase_id ASC
                LIMIT 1)
            RETURNING problem_id, submission_id, testcase_id
        """, self.judge_name, self.language_names)
        if row is None:
            return None
        problem_id = row["problem_id"]
        submission_id = row["submission_id"]
        testcase_id = row["testcase_id"]
        submission = Submission(**await conn.fetchrow("""
            SELECT language_name, code
            FROM submissions
            WHERE id = $1
        """, submission_id))
        testcase = TestCase(**await conn.fetchrow("""
            SELECT time_limit, memory_limit, checker_name, input, output
            FROM testcases
            WHERE id = $1
        """, testcase_id))
        return JudgeTask(problem_id, submission_id, testcase_id,
                         submission, testcase)

    async def cancel_unfinished(self):
        await self.pool.execute("""
            DELETE FROM result_judges AS x USING results_view AS y
            WHERE x.submission_id = y.submission_id
                AND x.testcase_id = y.testcase_id
                AND y.judge_name = $1
                AND y.accepted IS NULL;
        """, self.judge_name)
