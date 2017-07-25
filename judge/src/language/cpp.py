from . import Language
from sandbox import SandboxError

class Cpp(Language):
    def __init__(self, std):
        self._std = std

    async def _compile(self, sandbox, code):
        await sandbox.write("/tmp/main.cpp", code)
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

    async def is_available(self, sandbox):
        return 0 == await self._compile(sandbox, "int main(){}\n")
