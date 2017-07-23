import asyncio

class Sandbox:
    def __init__(self):
        self._process = None
    async def start(self):
        if self._process is not None:
            raise ValueError("The sandbox has started")
        self._process = await asyncio.create_subprocess_exec(
                "sandbox",
                stdin = asyncio.subprocess.PIPE,
                stdout = asyncio.subprocess.PIPE)
    async def close(self):
        if self._process is None:
            raise ValueError("The sandbox has not started")
        if self._process.returncode is not None:
            return
        self._process.stdin.close()
        await self._process.wait()

    async def __aenter__(self):
        await self.start()
        return self
    async def __aexit__(self, exc_type, exc, tb):
        await self.close()

    async def execute(self, *args, **kwargs):
        raise NotImplementedError()
    async def read(self, filename):
        if b"\0" in filename:
            raise ValueError("filename contains null charactor")
        if b"\n" in filename:
            raise ValueError("filename contains newline")

        self._process.stdin.write(b"read %b\n" % filename)

        result = b"";
        while True:
            line = await self._process.stdout.readuntil()
            if line == b"ok\n":
                return result
            if line == b"error\n":
                raise asyncio.IncompleteReadError(result, None)
            count = int(line)
            result += await self._process.stdout.readexactly(count)

    async def write(self, *args, **kwargs):
        raise NotImplementedError()
