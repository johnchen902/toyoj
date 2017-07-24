Overview
---

The judge system is supposed to fetch unjudged data from database,
(somehow) determine the result (accepted, time, memory, verdict),
and write back to database.

A judge task consists of a (submission, testcase) pair.
This pair in the database has three possibilities:
1. The pair is in the table "results".
2. The pair is not in the table "results" but in "result_judges".
3. The pair is in neither the table "results" nor the table "result_judges".
These three possibilities correspond to:
1. The judge task is complete.
2. The judge task is assigned to a judge, but not yet complete.
3. The judge task is unassigned.

So the first thing it does is fetching judge task in 3. and change it to 2.
Postgres NOTIFY is used when to task is found (instead of polling).

Then it "run" the judge task.
* If it does not compile at all, "CE".
* If it uses too much memory, "MLE".
* If it uses too much time, "TLE".
* If it exit abnormally otherwise, "RE".
* If the produced answer is incorrect (w.r.t. checker), "WA".
* Finally, "AC".
This part is complicated and will be described later.

Finally, it move the task from 2. to 1.
If the task is canceled (e.g. judge receive SIGTERM or SIGINT),
move the task from 2. to 3. instead.

Judging
---

Assuming the code is trusted and will not MLE or TLE,
we can do the following:
```
g++ main.cpp -o main || return CE
./main < input.txt > output.txt || return RE
cmp output.txt answer.txt && return AC || return WA
```
Unfortunately the assumption does not hold generally.

Let's see what modern linux provide us. Namespaces:

> Linux provides the following namespaces:
>
> Namespace   Constant          Isolates
> Cgroup      CLONE_NEWCGROUP   Cgroup root directory
> IPC         CLONE_NEWIPC      System V IPC, POSIX message queues
> Network     CLONE_NEWNET      Network devices, stacks, ports, etc.
> Mount       CLONE_NEWNS       Mount points
> PID         CLONE_NEWPID      Process IDs
> User        CLONE_NEWUSER     User and group IDs
> UTS         CLONE_NEWUTS      Hostname and NIS domain name

Cgroups:

> Cgroups version 1 controllers
>
> cpu (since Linux 2.6.24; CONFIG_CGROUP_SCHED)
> cpuacct (since Linux 2.6.24; CONFIG_CGROUP_CPUACCT)
> cpuset (since Linux 2.6.24; CONFIG_CPUSETS)
> memory (since Linux 2.6.25; CONFIG_MEMCG)
> devices (since Linux 2.6.26; CONFIG_CGROUP_DEVICE)
> freezer (since Linux 2.6.28; CONFIG_CGROUP_FREEZER)
> net_cls (since Linux 2.6.29; CONFIG_CGROUP_NET_CLASSID)
> blkio (since Linux 2.6.33; CONFIG_BLK_CGROUP)
> perf_event (since Linux 2.6.39; CONFIG_CGROUP_PERF)
> net_prio (since Linux 3.3; CONFIG_CGROUP_NET_PRIO)
> hugetlb (since Linux 3.5; CONFIG_CGROUP_HUGETLB)
> pids (since Linux 4.3; CONFIG_CGROUP_PIDS)

See https://github.com/ioi/isolate for an implementation.
However, shutting down network namespace is a severe bottleneck
on the production machine. What's worse, the bottleneck is not
parallelizable without patching the kernel.

So I have a workaround:
Create a "parent" process in distinct network namespace.
The parent will read task specification from stdin, executed it in a
newly-cloned "child", write the result to stdout and repeat.
