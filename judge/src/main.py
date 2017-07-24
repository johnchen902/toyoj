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

def get_languages():
    from language.cpp import Cpp
    from language.haskell import Haskell
    return {
        "C++14" : Cpp("c++14"),
        "Haskell" : Haskell(),
    }

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
        task_fetcher = TaskFetcher(args.name, pool)
        task_runner = TaskRunner(sandbox_pool, get_languages(), get_checkers())
        task_writer = TaskWriter(pool)

        async def run_and_write(task):
            try:
                await task_runner.run(task)
            except asyncio.CancelledError:
                raise
            except:
                logging.exception("When running %s", task)
                task.verdict = "XX"
                task.accepted = False
                if task.time is None:
                    task.time = 0
                if task.memory is None:
                    task.memory = 0
            try:
                await task_writer.write(task)
            except asyncio.CancelledError:
                raise
            except:
                logging.exception("When writing back %s", task)

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
                await asyncio.wait(pending)
            await task_fetcher.cancel_unfinished()

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
args = parser.parse_args()

loop = asyncio.get_event_loop()
task = asyncio.ensure_future(run(args))

def terminate():
    task.cancel()
def signal_handler(a, b):
    loop.call_soon_threadsafe(terminate)

signal.signal(signal.SIGINT, signal_handler)

try:
    loop.run_until_complete(task)
except asyncio.CancelledError:
    pass
