import asyncio

class SandboxError(Exception):
    pass

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
                raise SandboxError()
            count = int(line)
            result += await self._process.stdout.readexactly(count)

    async def write(self, filename, data, bufsiz = 8192):
        if b"\0" in filename:
            raise ValueError("filename contains null charactor")
        if b"\n" in filename:
            raise ValueError("filename contains newline")

        self._process.stdin.write(b"write %b\n" % filename)

        line = await self._process.stdout.readuntil()
        if line == b"error\n":
            raise SandboxError()
        assert line == b"ready\n"

        for i in range(0, len(data), bufsiz):
            part = data[i : i + bufsiz]
            self._process.stdin.write(b"%d\n%b" % (len(part), part))
            await self._process.stdin.drain()
        self._process.stdin.write(b"0\n")

        line = await self._process.stdout.readuntil()
        if line == b"error\n":
            raise SandboxError()
        assert line == b"ok\n"
