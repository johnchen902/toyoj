from . import Language

class Cpp(Language):
    def __init__(self, std):
        self._std = std

    async def _compile(self, sandbox, submission):
        await sandbox.write("/tmp/main.cpp", submission.code)
        result = await sandbox.execute(
                "/usr/bin/env", "PATH=/usr/bin",
                "/usr/bin/g++", "/tmp/main.cpp",
                "-o", "/tmp/main", "-O2", "-std=%s" % self._std,
                wall_time = "10s", memory = "1G", pids = 20)
        await sandbox.execute("/usr/bin/rm", "/tmp/main.cpp",
                uid = 0, gid = 0)
        return result["returncode"]

    async def _execute(self, sandbox, **kwargs):
        return await sandbox.execute("/tmp/main", **kwargs)
