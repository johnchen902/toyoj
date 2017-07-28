#!/usr/bin/env python3
import ast
import asyncio
import asyncpg
import configargparse
import logging
import logging.config
import platform
import signal

from toyojjudge.taskfetcher import TaskFetcher
from toyojjudge.taskrunner import TaskRunner
from toyojjudge.taskwriter import TaskWriter
import toyojjudge.sandbox as sandbox

logger = logging.getLogger(__name__)

def get_languages():
    from toyojjudge.language.cpp import Cpp
    from toyojjudge.language.haskell import Haskell
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
    from toyojjudge.checker.exact import ExactChecker
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
        pending_event = asyncio.Event()

        def remove_from_pending(future):
            pending.remove(future)
            pending_event.set()
            if not pending:
                logger.debug("No pending tasks")
        async def pending_control():
            while len(pending) >= args.max_pending_task:
                logger.debug("Max number of pending tasks reached, wait...")
                pending_event.clear()
                await pending_event.wait()

        try:
            while True:
                await pending_control()
                task = await task_fetcher.fetch()
                future = asyncio.ensure_future(run_and_write(task))
                pending.add(future)
                future.add_done_callback(remove_from_pending)
        finally:
            for p in pending:
                p.cancel()
            if(pending):
                logger.info("Waiting for pending tasks...")
                await asyncio.wait(pending)
            await task_fetcher.cancel_unfinished()

parser = configargparse.ArgumentParser()
parser.add_argument("--config", is_config_file = True,
        help = "Config file path (default: %(default)s)")
parser.add_argument("--dsn",
        default = "postgres:///toyoj",
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
parser.add_argument("--max-pending-task", metavar = "N",
        default = None, type = int,
        help = "Max number of pending tasks (default: twice of --max-sandbox)")
group = parser.add_mutually_exclusive_group()
group.add_argument("--log-basic", metavar = "DICT",
        default = None, type = ast.literal_eval,
        help = "Basic log config; see logging.basicConfig (default: %(default)s)")
group.add_argument("--log-dict", metavar = "DICT",
        default = None, type = ast.literal_eval,
        help = "Advanced log config; see logging.config.dictConfig (default: %(default)s)")

def main():
    args = parser.parse_args()
    if args.max_pending_task is None:
        args.max_pending_task = 2 * args.max_sandbox

    if args.log_dict is not None:
        if args.log_dict['version'] == 1:
            args.log_dict['disable_existing_loggers'] = False
        logging.config.dictConfig(args.log_dict)
    elif args.log_basic is not None:
        logging.basicConfig(**args.log_basic)

    loop = asyncio.get_event_loop()
    task = asyncio.ensure_future(run(args))

    def terminate():
        task.cancel()
    def signal_handler(signum, stackframe):
        logger.info("Received signal %d", signum)
        loop.call_soon_threadsafe(terminate)

    signal.signal(signal.SIGTERM, signal_handler)

    try:
        loop.run_until_complete(task)
    except asyncio.CancelledError:
        pass

if __name__ == "__main__":
    main()
