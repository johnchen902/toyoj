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
            terms.append(b"--uid=%d" % uid)
        if gid is not None:
            terms.append(b"--gid=%d" % gid)
        if user_time is not None:
            terms.append(b"--user-time=%d" % user_time)
        if wall_time is not None:
            terms.append(b"--wall-time=%d" % wall_time)
        if memory is not None:
            terms.append(b"--memory=%d" % memory)
        if pids is not None:
            terms.append(b"--pids=%d" % pids)
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
