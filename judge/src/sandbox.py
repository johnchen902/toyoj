import asyncio

class SandboxError(Exception):
    pass

class RawSandbox:
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

    async def execute(self, command, *param,
            stdin = None, stdout = None, stderr = None, chdir = None,
            uid = None, gid = None,
            user_time = None, wall_time = None, memory = None, pids = None):
        terms = []
        if stdin is not None:
            terms.append(b"--stdin=%b" % stdin)
        if stdout is not None:
            terms.append(b"--stdout=%b" % stdout)
        if stderr is not None:
            terms.append(b"--stderr=%b" % stderr)
        if chdir is not None:
            terms.append(b"--chdir=%b" % chdir)
        if uid is not None:
            terms.append(b"--uid=%b" % uid)
        if gid is not None:
            terms.append(b"--gid=%b" % gid)
        if user_time is not None:
            terms.append(b"--user-time=%b" % user_time)
        if wall_time is not None:
            terms.append(b"--wall-time=%b" % wall_time)
        if memory is not None:
            terms.append(b"--memory=%b" % memory)
        if pids is not None:
            terms.append(b"--pids=%b" % pids)
        terms.append(b"--")
        terms.append(command)
        terms += param

        self._process.stdin.write(b"execute %b\n" % terms_to_command(terms))

        result = {}
        while True:
            line = await self._process.stdout.readuntil()
            if line == b"error\n":
                raise SandboxError()
            if line == b"ok\n":
                return result
            attr, value = line[:-1].split(b"=", 1)
            result[attr] = value

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

def terms_to_command(terms):
    byte_space = b" "[0]
    byte_lf    = b"\n"[0]
    byte_slash = b"\\"[0]
    byte_null  = b"\0"[0]

    command = bytes()
    for term in terms:
        for byte in term:
            if byte == byte_space:
                command += b"\\ "
            elif byte == byte_lf:
                command += b"\\n"
            elif byte == byte_slash:
                command += b"\\\\"
            elif byte == byte_null:
                raise ValueError("%s contains null charactor" % term)
            else:
                command += bytes([byte])
        command += b" "
    return command[:-1]

class Sandbox:
    def __init__(self):
        self._raw = RawSandbox()
    async def start(self):
        await self._raw.start()
    async def close(self):
        await self._raw.close()
    async def __aenter__(self):
        await self._raw.__aenter__()
        return self
    async def __aexit__(self, exc_type, exc, tb):
        await self._raw.__aexit__(exc_type, exc, tb)
    async def execute(self, command, *param,
            stdin = None, stdout = None, stderr = None, chdir = None,
            uid = None, gid = None,
            user_time = None, wall_time = None, memory = None, pids = None):
        command = command.encode()
        param = [p.encode() for p in param]
        stdin = stdin.encode() if stdin is not None else None
        stdout = stdout.encode() if stdout is not None else None
        stderr = stderr.encode() if stderr is not None else None
        chdir = chdir.encode() if chdir is not None else None
        uid = str(uid).encode() if uid is not None else None
        gid = str(gid).encode() if gid is not None else None
        user_time = str(user_time).encode() if user_time is not None else None
        wall_time = str(wall_time).encode() if wall_time is not None else None
        memory = str(memory).encode() if memory is not None else None
        pids = str(pids).encode() if pids is not None else None

        rawresult = await self._raw.execute(command, *param,
                stdin = stdin, stdout = stdout, stderr = stderr,
                chdir = chdir, uid = uid, gid = gid,
                user_time = user_time, wall_time = wall_time,
                memory = memory, pids = pids)

        result = {}
        for key, value in rawresult.items():
            try:
                result[key.decode()] = int(value)
            except ValueError:
                pass
        if "wstatus" in result:
            wstatus = result["wstatus"]
            if wstatus & 0x7f:
                result["returncode"] = -(wstatus & 0x7f)
            else:
                result["returncode"] = (wstatus & 0xff00) >> 8

        return result
    async def read(self, filename):
        return (await self._raw.read(filename.encode())).decode()
    async def write(self, filename, data, bufsiz = 8192):
        await self._raw.write(filename.encode(), data.encode())

class SandboxPool:
    def __init__(self, n):
        self._queue = asyncio.Queue()
        self._n = n

    async def _async__init__(self):
        for _ in range(self._n):
            newbox = Sandbox()
            await newbox.start()
            self._queue.put_nowait(newbox)

    def acquire(self):
        """
        Acquire a sandbox from the pool.

        Can be used in an `await` expression or with an `async with` block.
        """
        return SandboxPoolAcquireContext(self)

    async def _acquire(self):
        return await self._queue.get()

    async def release(self, box):
        """Release a sandbox back to the pool."""
        await box.execute(
                "/usr/bin/find", "/tmp", "-mindepth", "1", "-delete",
                uid = 0, gid = 0)
        self._queue.put_nowait(box)

    async def close(self):
        """Gracefully close all connections in the pool."""
        for _ in range(self._n):
            await (await self._acquire()).close()

    def __await__(self):
        return self._async__init__().__await__()

    async def __aenter__(self):
        await self._async__init__()
        return self

    async def __aexit__(self, *exc):
        await self.close()

class SandboxPoolAcquireContext:
    def __init__(self, pool):
        self.pool = pool
        self.box = None
        self.done = False

    async def __aenter__(self):
        if self.box is not None or self.done:
            raise ValueError('a sandbox is already acquired')
        self.box = await self.pool._acquire()
        return self.box

    async def __aexit__(self, *exc):
        self.done = True
        box, self.box = self.box, None
        await self.pool.release(box)

    def __await__(self):
        self.done = True
        return self.pool._acquire().__await__()
