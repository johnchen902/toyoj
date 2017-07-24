#!/usr/bin/env python3
import asyncio
import asyncpg
from collections import deque
import logging
import signal
import sys

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

async def run(dsn):
    async with asyncpg.create_pool(dsn, min_size = 1, max_size = 2) as pool, \
               sandbox.SandboxPool(n = 4) as sandbox_pool:
        task_fetcher = TaskFetcher("test-2", pool)
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

if len(sys.argv) != 2:
    print("Usage: ./main.py DSN", file = sys.stderr)
    print("See asyncpg document for DSN format", file = sys.stderr)
    sys.exit(1)
dsn = sys.argv[1]

loop = asyncio.get_event_loop()
task = asyncio.ensure_future(run(dsn))

def terminate():
    task.cancel()
def signal_handler(a, b):
    loop.call_soon_threadsafe(terminate)

signal.signal(signal.SIGINT, signal_handler)

try:
    loop.run_until_complete(task)
except asyncio.CancelledError:
    pass
