class TaskWriter:
    def __init__(self, pool):
        self.pool = pool

    async def write(self, task):
        return await self.pool.execute("""
            INSERT INTO results (problem_id, submission_id, testcase_id,
                accepted, time, memory, verdict)
            VALUES ($1, $2, $3, $4, $5, $6, $7)
        """, task.problem_id, task.submission_id, task.testcase_id,
                task.accepted, task.time, task.memory, task.verdict)
