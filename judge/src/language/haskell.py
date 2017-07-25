from . import Language
from sandbox import SandboxError

class Haskell(Language):
    async def _compile(self, sandbox, code):
        await sandbox.write("/tmp/main.hs", code)
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

    async def is_available(self, sandbox):
        return 0 == await self._compile(sandbox, "main = return ()\n")
