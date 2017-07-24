class Language:
    async def run_task(self, sandbox, task):
        # Compile the source code
        if await self._compile(sandbox, task.submission):
            task.verdict = "CE"
            return
        # Put input file in the sandbox
        await sandbox.write("/tmp/input.txt", task.testcase.input)
        # Claim the output file
        if await self._claim_file(sandbox, "/tmp/output.txt"):
            task.verdict = "XX"
            return
        # Run the executable
        result = await self._execute(sandbox,
                stdin = "/tmp/input.txt",
                stdout = "/tmp/output.txt",
                user_time = "%dms" % task.testcase.time_limit,
                wall_time = "%dms" % (2 * task.testcase.time_limit),
                memory = "%dk" % task.testcase.memory_limit,
                pids = 20)
        task.time = result["cpuacct.usage_user"] // 10 ** 6
        task.memory = result["memory.memsw.max_usage_in_bytes"] // 1024
        task.verdict = self._get_verdict(result, task.testcase)
        if task.verdict is not None:
            return
        # Retain only the output file
        if await self._rm_except(sandbox, "/tmp/output.txt"):
            task.verdict = "XX"
            return

    async def _compile(self, sandbox, submission):
        raise NotImplementedError()

    async def _execute(self, sandbox, **kwargs):
        raise NotImplementedError()

    async def _rm_except(self, sandbox, filename):
        return (await sandbox.execute(
                "/usr/bin/find", "/tmp", "-mindepth", "1",
                "!", "-path", filename, "-delete",
                uid = 0, gid = 0))["returncode"]

    async def _claim_file(self, sandbox, filename):
        return (await sandbox.execute(
                "/usr/bin/install", "-m", "666", "/dev/null", filename,
                uid = 0, gid = 0))["returncode"]

    def _get_verdict(self, result, testcase):
        time = result["cpuacct.usage_user"]
        memory = result["memory.memsw.max_usage_in_bytes"]
        if time > testcase.time_limit * 10 ** 6:
            return "TLE"
        if result["returncode"]:
            if result["time_killed"]:
                return "TLE"
            if memory >= testcase.memory_limit * 1024:
                return "MLE"
            return "RE"
        return None
