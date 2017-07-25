#!/usr/bin/env python3
import argparse
import asyncio
import asyncpg
import logging
import platform
import signal

from taskfetcher import TaskFetcher
from taskrunner import TaskRunner
from taskwriter import TaskWriter
import sandbox

logger = logging.getLogger(__name__)

def get_languages():
    from language.cpp import Cpp
    from language.haskell import Haskell
    return {
        "C++14" : Cpp("c++14"),
        "Haskell" : Haskell(),
    }

async def get_available_languages(pool):
    async def add_if_available(result, name, lang, pool):
        try:
            async with pool.acquire() as box:
                logger.debug("Checking availability of %s", name)
                if await lang.is_available(box):
                    result[name] = lang
        except asyncio.CancelledError:
            raise
        except sandbox.SandboxError:
            pass
        except Exception:
            logger.exception("Error checking availability of %s", name)

    result = {}
    await asyncio.wait({
        asyncio.ensure_future(add_if_available(result, name, lang, pool))
        for name, lang in get_languages().items()
    })
    return result

def get_checkers():
    from checker.exact import ExactChecker
    return {
        "exact" : ExactChecker(),
    }

async def run(args):
    async with asyncpg.create_pool(args.dsn,
                    min_size = args.min_conn,
                    max_size = args.max_conn) as pool, \
               sandbox.SandboxPool(n = args.max_sandbox) as sandbox_pool:
        languages = await get_available_languages(sandbox_pool)
        checkers = get_checkers()
        task_fetcher = TaskFetcher(args.name, pool, languages.keys())
        task_runner = TaskRunner(sandbox_pool, languages, checkers)
        task_writer = TaskWriter(pool)

        logger.info("Available languages: %s", languages.keys())
        logger.info("Available checkers: %s", checkers.keys())

        async def run_and_write(task):
            try:
                await task_runner.run(task)
            except asyncio.CancelledError:
                raise
            except Exception:
                logger.exception("Error running %s", task)
                task.verdict = "XX"
                task.accepted = False
            try:
                await task_writer.write(task)
            except asyncio.CancelledError:
                raise
            except Exception:
                logger.exception("Error writing %s", task)

        pending = set()
        try:
            while True:
                task = await task_fetcher.fetch()
                future = asyncio.ensure_future(run_and_write(task))
                pending.add(future)
                _, pending = await asyncio.wait(pending, timeout = 0)
        finally:
            for p in pending:
                p.cancel()
            if(pending):
                logger.info("Waiting for pending tasks...")
                await asyncio.wait(pending)
            await task_fetcher.cancel_unfinished()

class LogLevelParser:
    KNOWN_LEVEL = ["CRITICAL", "ERROR", "WARNING", "INFO", "DEBUG", "NOTSET"]
    def __call__(self, level):
        try:
            return int(level)
        except ValueError:
            pass
        level = level.upper()
        if level not in self.KNOWN_LEVEL:
            raise ValueError()
        return level
    def __repr__(self):
        return "log level"

parser = argparse.ArgumentParser()
parser.add_argument("--dsn",
        default = "postgres://@/toyoj",
        help = "The data source name as defined by asyncpg (default: %(default)s)")
parser.add_argument("--name",
        default = platform.node()[:32] or "unnamed-judge",
        help = "Name of the judge (default: %(default)s)")
parser.add_argument("--min-conn", metavar = "N",
        default = 2, type = int,
        help = "Number of database connection to initialize with (default: %(default)d)")
parser.add_argument("--max-conn", metavar = "N",
        default = 2, type = int,
        help = "Max number of database connection (default: %(default)d)")
parser.add_argument("--max-sandbox", metavar = "N",
        default = 1, type = int,
        help = "Max number of sandbox (default: %(default)d)")
parser.add_argument("--log-file", metavar = "FILE",
        default = None,
        help = "Log file (default: %(default)s)")
parser.add_argument("--log-level", metavar = "LEVEL",
        default = "WARNING", type = LogLevelParser(),
        help = "Log level (default: %(default)s)")
args = parser.parse_args()

logging.basicConfig(filename = args.log_file, level = args.log_level)

loop = asyncio.get_event_loop()
task = asyncio.ensure_future(run(args))

def terminate():
    task.cancel()
def signal_handler(signum, stackframe):
    logger.info("Received signal %d", signum)
    loop.call_soon_threadsafe(terminate)

signal.signal(signal.SIGINT, signal_handler)

try:
    loop.run_until_complete(task)
except asyncio.CancelledError:
    pass
