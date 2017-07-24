import asyncio
import sandbox

class TaskRunner:
    def __init__(self, sandbox_pool, languages, checkers):
        self.sandbox_pool = sandbox_pool
        self.languages = languages
        self.checkers = checkers

    async def run(self, task):
        async with self.sandbox_pool.acquire() as box:
            lang = self.languages[task.submission.language_name]
            check = self.checkers[task.testcase.checker_name]
            await lang.run_task(box, task)
            if task.verdict is not None:
                task.accepted = False
                if task.time is None:
                    task.time = 0
                if task.memory is None:
                    task.memory = 0
            else:
                await check.check(box, task)
