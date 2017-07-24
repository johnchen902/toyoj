from . import Checker

class ExactChecker(Checker):
    async def check(self, sandbox, task):
        output = await sandbox.read("/tmp/output.txt")
        task.accepted = output == task.testcase.output
        task.verdict = "AC" if task.accepted else "WA"
