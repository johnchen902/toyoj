from . import Language

class Haskell(Language):
    async def _compile(self, sandbox, submission):
        await sandbox.write("/tmp/main.hs", submission.code)
        result = await sandbox.execute(
                "/usr/bin/env", "PATH=/usr/bin",
                "/usr/bin/ghc", "/tmp/main.hs",
                "-o", "/tmp/main", "-O1", "-dynamic",
                wall_time = "10s", memory = "1G", pids = 20)
        await sandbox.execute("/usr/bin/rm",
                "/tmp/main.hs", "/tmp/main.hi", "/tmp/main.o",
                uid = 0, gid = 0)
        return result["returncode"]

    async def _execute(self, sandbox, **kwargs):
        return await sandbox.execute("/tmp/main", **kwargs)
