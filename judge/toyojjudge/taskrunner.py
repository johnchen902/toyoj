import asyncio
import logging

logger = logging.getLogger(__name__)

class TaskRunner:
    def __init__(self, sandbox_pool, languages, checkers):
        self.sandbox_pool = sandbox_pool
        self.languages = languages
        self.checkers = checkers

    async def run(self, task):
        async with self.sandbox_pool.acquire() as box:
            language_name = task.submission.language_name
            checker_name = task.testcase.checker_name
            logger.info("Running %s, language %s, checker %s",
                    task, language_name, checker_name)
            lang = self.languages[language_name]
            check = self.checkers[checker_name]
            await lang.run_task(box, task)
            if task.verdict is not None:
                task.accepted = False
            else:
                await check.check(box, task)
