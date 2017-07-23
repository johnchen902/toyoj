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
        raise NotImplementedError()
    async def write(self, *args, **kwargs):
        raise NotImplementedError()
