#include "child-execute.h"

#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <getopt.h>
#include <limits.h>
#include <signal.h>
#include <stdarg.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#include "grandchild.h"

#ifndef CPUCG_SANDBOX
#define CPUCG_SANDBOX "/sys/fs/cgroup/cpu,cpuacct/sandbox"
#endif // CPUCG_SANDBOX
#ifndef MEMCG_SANDBOX
#define MEMCG_SANDBOX "/sys/fs/cgroup/memory/sandbox"
#endif // MEMCG_SANDBOX
#ifndef PIDCG_SANDBOX
#define PIDCG_SANDBOX "/sys/fs/cgroup/pids/sandbox"
#endif // PIDCG_SANDBOX

static int split_execute_command(const char *command,
        char **pbuf, char ***pargv) {
    if (!command)
        return -EINVAL;

    // Split command by unescaped ' '.
    // Replace "\\ ", "\\n", "\\\\" by ' ', '\n', '\\', respectively.
    char *buf = malloc(8 + strlen(command) + 1);
    if (!buf)
        return -errno;
    char **argv = malloc((1 + (strlen(command) + 1) + 1) * sizeof(char*));
    if (!argv) {
        int err = errno;
        free(buf);
        return -err;
    }

    char **a = argv, *b = buf;
    *a++ = b;
    memcpy(b, "execute", 8);
    b += 8;
    *a++ = b;
    while (*command) {
        switch(*command) {
        case '\\':
            switch (command[1]) {
            case ' ':
                *b++ = ' ';
                break;
            case 'n':
                *b++ = '\n';
                break;
            case '\\':
                *b++ = '\\';
                break;
            case '\0':
            default:
                free(buf);
                free(argv);
                return -EINVAL;
            }
            command += 2;
            break;
        case ' ':
            *b++ = '\0';
            *a++ = b;
            command++;
            break;
        default:
            *b++ = *command++;
            break;
        }
    }
    int argc = a - argv;
    *a++ = NULL;
    *b++ = '\0';

    *pbuf = buf;
    *pargv = argv;
    return argc;
}

static long my_strtol(const char *str, char **endptr, int base, int *err) {
    char *end;
    errno = 0;
    long x = strtol(str, &end, base);
    if (endptr)
        *endptr = end;
    if (errno > 0)
        *err = errno;
    else if (str == end || isspace(*str) || (!endptr && *end))
        *err = EINVAL;
    else
        *err = 0;
    return x;
}

static unsigned my_strtou(const char *str, int base, int *err) {
    char *end;
    errno = 0;
    unsigned long x = strtoul(str, &end, base);
    if (errno > 0)
        *err = errno;
    else if (str == end || isspace(*str) || *end)
        *err = EINVAL;
    else if (x > UINT_MAX) {
        x = UINT_MAX;
        *err = ERANGE;
    } else
        *err = 0;
    return x;
}

static long strtotime(const char *str) {
    int err;
    char *end;
    long x = my_strtol(str, &end, 10, &err);
    if (err > 0)
        return -err;
    if (x <= 0)
        return -ERANGE;
    long multiplier;
    if (strcmp(end, "s") == 0)
        multiplier = 1000000000;
    else if (strcmp(end, "ms") == 0)
        multiplier = 1000000;
    else if (strcmp(end, "us") == 0)
        multiplier = 1000;
    else if (strcmp(end, "ns") == 0 || strcmp(end, "") == 0)
        multiplier = 1;
    else
        return -EINVAL;
    if (x > LONG_MAX / multiplier)
        return -ERANGE;
    return x * multiplier;
}

static long strtomemory(const char *str) {
    int err;
    char *end;
    long x = my_strtol(str, &end, 10, &err);
    if (err > 0)
        return -err;
    if (x <= 0)
        return -ERANGE;
    long multiplier;
    switch (*end) {
    case 't':
    case 'T':
        multiplier = 1L << 40;
        end++;
        break;
    case 'g':
    case 'G':
        multiplier = 1L << 30;
        end++;
        break;
    case 'm':
    case 'M':
        multiplier = 1L << 20;
        end++;
        break;
    case 'k':
    case 'K':
        multiplier = 1L << 10;
        end++;
        break;
    case '\0':
        multiplier = 1;
        break;
    default:
        return -EINVAL;
    }
    if (*end)
        return -EINVAL;
    if (x > LONG_MAX / multiplier)
        return -ERANGE;
    return x * multiplier;
}

static long strtopids(const char *str) {
    int err;
    long x = my_strtol(str, NULL, 10, &err);
    if (err > 0)
        return -err;
    if (x <= 0)
        return -ERANGE;
    return x;
}

struct execute_options {
    struct grandchild_options gc_opts;
    long user_time;
    long wall_time;
    long memory;
    long pids;
};

static const struct execute_options default_execute_options = {
    .gc_opts = {
        .stdin = "/dev/null",
        .stdout = "/dev/null",
        .stderr = "/dev/null",
        .chdir = "/tmp",
        .uid = 1,
        .gid = 1,
    },
    .user_time = -1,
    .wall_time = -1,
    .memory = -1,
    .pids = -1,
};

static int parse_execute_options(struct execute_options *opts,
        int argc, char **argv) {
    const struct option longopts[] = {
        {"stdin",       required_argument, NULL, 'i'},
        {"stdout",      required_argument, NULL, 'o'},
        {"stderr",      required_argument, NULL, 'e'},
        {"chdir",       required_argument, NULL, 'd'},
        {"uid",         required_argument, NULL, 'u'},
        {"gid",         required_argument, NULL, 'g'},
        {"user-time",   required_argument, NULL, 't'},
        {"wall-time",   required_argument, NULL, 'w'},
        {"memory",      required_argument, NULL, 'm'},
        {"pids",        required_argument, NULL, 'p'},
        {NULL},
    };

    const char *const optstring = "+i:o:e:d:u:g:t:w:m:p:";

    optind = 0;
    int opt;
    while ((opt = getopt_long(argc, argv, optstring, longopts, NULL)) != -1) {
        switch (opt) {
        case 'i':
            opts->gc_opts.stdin = optarg;
            break;
        case 'o':
            opts->gc_opts.stdout = optarg;
            break;
        case 'e':
            opts->gc_opts.stderr = optarg;
            break;
        case 'd':
            opts->gc_opts.chdir = optarg;
            break;
        case 'u':
            {
                int err;
                opts->gc_opts.uid = my_strtou(optarg, 10, &err);
                if (err > 0)
                    return -err;
                break;
            }
        case 'g':
            {
                int err;
                opts->gc_opts.gid = my_strtou(optarg, 10, &err);
                if (err > 0)
                    return -err;
                break;
            }
        case 't':
            if ((opts->user_time = strtotime(optarg)) < 0)
                return opts->user_time;
            break;
        case 'w':
            if ((opts->wall_time = strtotime(optarg)) < 0)
                return opts->wall_time;
            break;
        case 'm':
            if ((opts->memory = strtomemory(optarg)) < 0)
                return opts->memory;
            break;
        case 'p':
            if ((opts->pids = strtopids(optarg)) < 0)
                return opts->pids;
            break;
        default:
            return -EINVAL;
        }
    }

    if (optind == argc)
        return -EINVAL;

    opts->gc_opts.filename = argv[optind];
    opts->gc_opts.argv = (const char * const *) (argv + optind);
    return 0;
}

struct execute_runtime {
    bool cpucg_made_dir;
    bool memcg_made_dir;
    bool pidcg_made_dir;

    struct timespec start_time;

    bool time_killed;
};

static int oprintf(const char *file, const char *format, ...) {
    va_list ap;
    int ret = 0;

    va_start(ap, format);

    int fd = open(file, O_WRONLY);
    if (fd < 0) {
        ret = -errno;
    } else {
        if (vdprintf(fd, format, ap) < 0)
            ret = -errno;
        if (close(fd) < 0 && !ret)
            ret = -errno;
    }

    va_end(ap);
    return ret;
}

static int setup_cpu_cgroup(int pid, struct execute_runtime *run) {
    if (mkdir(CPUCG_SANDBOX, 0755) < 0)
        return -errno;
    run->cpucg_made_dir = true;
    int ret;

    // XXX probably we should left period unchanged and set quote=period
    // but I'm lazy

    ret = oprintf(CPUCG_SANDBOX "/cpu.cfs_period_us", "100000");
    if (ret < 0)
        return ret;

    ret = oprintf(CPUCG_SANDBOX "/cpu.cfs_quota_us", "100000");
    if (ret < 0)
        return ret;

    ret = oprintf(CPUCG_SANDBOX "/cgroup.procs", "%d", pid);
    if (ret < 0)
        return ret;

    return 0;
}

static int setup_memory_cgroup(long memory, int pid,
        struct execute_runtime *run) {
    if (mkdir(MEMCG_SANDBOX, 0755) < 0)
        return -errno;
    run->memcg_made_dir = true;
    int ret;

    ret = oprintf(MEMCG_SANDBOX "/memory.limit_in_bytes", "%ld", memory);
    if (ret < 0)
        return ret;

    ret = oprintf(MEMCG_SANDBOX "/memory.memsw.limit_in_bytes", "%ld", memory);
    if (ret < 0)
        return ret;

    ret = oprintf(MEMCG_SANDBOX "/cgroup.procs", "%d", pid);
    if (ret < 0)
        return ret;

    return 0;
}

static int setup_pids_cgroup(long pids, int pid, struct execute_runtime *run) {
    if (mkdir(PIDCG_SANDBOX, 0755) < 0)
        return -errno;
    run->pidcg_made_dir = true;
    int ret;

    ret = oprintf(PIDCG_SANDBOX "/pids.max", "%ld", pids);
    if (ret < 0)
        return ret;

    ret = oprintf(PIDCG_SANDBOX "/cgroup.procs", "%d", pid);
    if (ret < 0)
        return ret;

    return 0;
}

static int setup_limits(const struct execute_options *opts, int pid,
        struct execute_runtime *run) {
    if (opts->user_time > 0) {
        int ret = setup_cpu_cgroup(pid, run);
        if (ret < 0)
            return ret;
    }
    if (opts->wall_time > 0) {
        if (clock_gettime(CLOCK_MONOTONIC_RAW, &run->start_time) < 0)
            return -errno;
    }
    if (opts->memory > 0) {
        int ret = setup_memory_cgroup(opts->memory, pid, run);
        if (ret < 0)
            return ret;
    }
    if (opts->pids > 0) {
        int ret = setup_pids_cgroup(opts->pids, pid, run);
        if (ret < 0)
            return ret;
    }

    return 0;
}

static void print_attribute(const char *path, const char *attr_name) {
    int fd = open(path, O_RDONLY);
    if (fd >= 0) {
        char buf[BUFSIZ];
        ssize_t sz = read(fd, buf, sizeof(buf) - 1);
        if (sz >= 0) {
            buf[sz] = '\0';
            for (char *c = buf; *c; c++)
                if (*c == '\n')
                    *c = ' ';
            printf("%s=%s\n", attr_name, buf);
        } else {
            perror("read");
        }

        close(fd);
    } else {
        perror("open");
    }
}

static void print_limits_info(const struct execute_runtime *run) {
    printf("time_killed=%d\n", run->time_killed);
#define PRINT_ATTR(D, A) print_attribute(D "/" A, A)
    if (run->cpucg_made_dir) {
        PRINT_ATTR(CPUCG_SANDBOX, "cpuacct.usage");
        PRINT_ATTR(CPUCG_SANDBOX, "cpuacct.usage_sys");
        PRINT_ATTR(CPUCG_SANDBOX, "cpuacct.usage_user");
    }
    if (run->memcg_made_dir) {
        // Along with child exit status, there should be enough
        // infomation to guess if memory limit was exceeded.
        // See kernel documenatiton of memory cgroup for the accurate way.
        PRINT_ATTR(MEMCG_SANDBOX, "memory.failcnt");
        PRINT_ATTR(MEMCG_SANDBOX, "memory.max_usage_in_bytes");
        PRINT_ATTR(MEMCG_SANDBOX, "memory.limit_in_bytes");
        PRINT_ATTR(MEMCG_SANDBOX, "memory.memsw.failcnt");
        PRINT_ATTR(MEMCG_SANDBOX, "memory.memsw.max_usage_in_bytes");
        PRINT_ATTR(MEMCG_SANDBOX, "memory.memsw.limit_in_bytes");
    }
#undef PRINT_ATTR
}

static void teardown_limits(const struct execute_runtime *run) {
    if (run->cpucg_made_dir)
        if (rmdir(CPUCG_SANDBOX) < 0)
            perror("rmdir");
    if (run->memcg_made_dir)
        if (rmdir(MEMCG_SANDBOX) < 0)
            perror("rmdir");
    if (run->pidcg_made_dir)
        if (rmdir(PIDCG_SANDBOX) < 0)
            perror("rmdir");
}

static int check_child_stderr(int fd) {
    char buf[BUFSIZ];
    ssize_t size = read(fd, buf, sizeof(buf) - 1);
    if (size > 0) {
        buf[size] = '\0';
        fprintf(stderr, "execute: %s", buf);
        return -1;
    }
    return 0;
}

static int close_once(int *fdptr) {
    int fd = *fdptr;
    if (fd >= 0) {
        *fdptr = -1;
        return close(fd);
    }
    return 0;
}

static long get_user_ns(void) {
    int fd = open(CPUCG_SANDBOX "/cpuacct.usage_user", O_RDONLY);
    if (fd >= 0) {
        char buf[BUFSIZ];
        ssize_t sz = read(fd, buf, sizeof(buf) - 1);
        if (sz >= 0) {
            buf[sz] = '\0';
            return strtol(buf, NULL, 10);
        }
    }
    return -errno;
}

static long get_sleep_time(const struct execute_options *opt,
        const struct execute_runtime *run) {
    // return LONG_MAX if should sleep indefinitely
    // return -1 if already timed out
    long sleep_ns = LONG_MAX;

    if (opt->user_time > 0) {
        long user_ns = get_user_ns();
        if (user_ns < 0) {
            fprintf(stderr, "get_user_ns: %s\n", strerror((int) -user_ns));
        } else {
            long remain_ns = opt->user_time - user_ns;
            if (remain_ns < 0)
                return -1;
            remain_ns += 1000 * 1000; // 1ms
            if (remain_ns < 100 * 1000 * 1000) // 100ms
                remain_ns = 100 * 1000 * 1000;
            if (sleep_ns > remain_ns)
                sleep_ns = remain_ns;
        }
    }

    if (opt->wall_time > 0) {
        struct timespec now;
        clock_gettime(CLOCK_MONOTONIC_RAW, &now); // XXX can it fail here?
        long wall_ns =
            (now.tv_sec - run->start_time.tv_sec) * (1000 * 1000 * 1000) +
            (now.tv_nsec - run->start_time.tv_nsec);
        long remain_ns = opt->wall_time - wall_ns;
        if (remain_ns < 0)
            return -1;
        remain_ns += 1000 * 1000; // 1ms
        if (sleep_ns > remain_ns)
            sleep_ns = remain_ns;
    }

    return sleep_ns;
}

static void wait_for(int pid, int *wstatus,
        const struct execute_options *opt,
        struct execute_runtime *run) {
    // XXX no error checks
    sigset_t oldset, waitset;
    sigemptyset(&waitset);
    sigaddset(&waitset, SIGCHLD);
    sigprocmask(SIG_BLOCK, &waitset, &oldset);

    while (1) {
        pid_t wpid = waitpid(-1, wstatus, WNOHANG);
        if (wpid == pid)
            break;
        if (wpid == 0) {
            long sleep_ns = get_sleep_time(opt, run);

            struct timespec buf, *sleep_time;
            if (sleep_ns < 0) {
                kill(-1, SIGKILL);
                run->time_killed = true;
                sleep_time = NULL;
            } else if (sleep_ns == LONG_MAX) {
                sleep_time = NULL;
            } else {
                buf.tv_sec  = sleep_ns / (1000 * 1000 * 1000);
                buf.tv_nsec = sleep_ns % (1000 * 1000 * 1000);
                sleep_time = &buf;
            }

            sigtimedwait(&waitset, NULL, sleep_time);
        }
    }

    sigprocmask(SIG_SETMASK, &oldset, NULL);
}

static int do_execute_command_with_opts(const struct execute_options *opts) {
    int ret;
    int pipe_stdin[2];
    int pipe_stderr[2];
    if (pipe(pipe_stdin) < 0)
        return -errno;
    if (pipe(pipe_stderr) < 0) {
        ret = -errno;
        goto out_pipe_stdin;
    }

    pid_t pid = fork();
    switch (pid) {
    case -1:
        ret = -errno;
        goto out_pipe_stderr;
    case 0:
        // child
        close(pipe_stdin[1]);
        dup2(pipe_stdin[0], 0);
        close(pipe_stdin[0]);
        close(pipe_stderr[0]);
        dup2(pipe_stderr[1], 2);
        close(pipe_stderr[1]);
        grandchild_main(&opts->gc_opts);
        /* unreachable */
    }
    // parent
    close_once(&pipe_stderr[1]);

    struct execute_runtime run = {0};
    if ((ret = setup_limits(opts, pid, &run)) < 0) {
        close_once(&pipe_stdin[1]);
        wait(NULL);
        goto out_teardown_limits;
    }

    write(pipe_stdin[1], "", 1);
    close_once(&pipe_stdin[1]);
    close_once(&pipe_stdin[0]);

    if (check_child_stderr(pipe_stderr[0]) < 0) {
        puts("error");
        ret = 0;
        wait(NULL);
        goto out_teardown_limits;
    }

    close_once(&pipe_stderr[0]);

    int wstatus;
    wait_for(pid, &wstatus, opts, &run);

    kill(-1, SIGKILL);

    while (wait(NULL) >= 0)
        ;

    printf("wstatus=%d\n", wstatus);
    print_limits_info(&run);
    puts("ok");

    teardown_limits(&run);
    return 0;

out_teardown_limits:
    teardown_limits(&run);
out_pipe_stderr:
    close_once(&pipe_stderr[0]);
    close_once(&pipe_stderr[1]);
out_pipe_stdin:
    close_once(&pipe_stdin[0]);
    close_once(&pipe_stdin[1]);
    return ret;
}

static int do_execute_command_with_argv(int argc, char **argv) {
    int ret;
    struct execute_options opts = default_execute_options;

    if ((ret = parse_execute_options(&opts, argc, argv)) < 0)
        return ret;

    return do_execute_command_with_opts(&opts);
}

int do_execute_command(const char *command) {
    // command: execute [OPTION]... COMMAND [ARG]...
    // ' ', '\n' and '\\' must be escaped by '\\'
    // Options:
    // -i, --stdin=FILENAME     Standard input. Default to /dev/null.
    // -o, --stdout=FILENAME    Standard output. Default to /dev/null.
    // -e, --stderr=FILENAME    Standard error. Default to /dev/null.
    // -d, --chdir=DIRECTORY    Change current working directory.
    //                          Default to /tmp
    // -u, --uid=UID            See setresuid(2). Default to 1.
    // -g, --gid=GID            See setresgid(2). Default to 1.
    // -t, --user-time=DURATION Kill if user time exceed DURATION.
    // -w, --wall-time=DURATION Kill if still running after DURATION.
    // -m, --memory=SIZE        Limit memory usage to SIZE.
    // -p, --pids=NUMBER        Limit number of pids to NUMBER.
    char *buf, **argv;
    int argc = split_execute_command(command, &buf, &argv);
    if (argc < 0)
        return argc;
    int ret = do_execute_command_with_argv(argc, argv);
    free(argv);
    free(buf);
    return ret;
}
