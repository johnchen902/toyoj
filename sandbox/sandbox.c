#include <errno.h>
#include <getopt.h>
#include <pwd.h>
#include <sched.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/prctl.h>
#include <sys/queue.h>
#include <sys/stat.h>
#include <sys/syscall.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <unistd.h>

#include "child.h"

struct cgroup_entry {
    SLIST_ENTRY(cgroup_entry) entries;
    char filename[];
};

static SLIST_HEAD(cgroup_entry_head, cgroup_entry) cgroup_entry_head =
        SLIST_HEAD_INITIALIZER(cgroup_entry_head);

static int init_cgroups_controller(const char *controller, const char *path) {
    if (!*controller) {
        controller = "unified";
    } else if (strncmp(controller, "name=", 5) == 0) {
        controller += 5;
    }
    char *newdir;
    if (asprintf(&newdir, "/sys/fs/cgroup/%s/%s/toyoj-sandbox-XXXXXX",
                controller, path) < 0)
        return -1;

    int err;
    if (!mkdtemp(newdir)) {
        perror("mkdtemp");
        fprintf(stderr, "cgroup \"%s\" ignored\n", controller);
        err = errno = 0;
        goto out_newdir;
    }
    struct cgroup_entry *cgroup_entry =
            malloc(sizeof(struct cgroup_entry) + strlen(newdir) + 1);
    if (!cgroup_entry) {
        err = errno;
        if (rmdir(newdir) < 0)
            perror("rmdir");
        goto out_newdir;
    }
    strcpy(cgroup_entry->filename, newdir);
    SLIST_INSERT_HEAD(&cgroup_entry_head, cgroup_entry, entries);

    char *procs;
    if (asprintf(&procs, "%s/cgroup.procs", newdir) < 0) {
        err = errno;
        goto out_newdir;
    }
    FILE *f = fopen(procs, "w");
    if (!f) {
        err = errno;
        goto out_procs;
    }
    if (fprintf(f, "%d\n", (int) getpid()) < 0) {
        err = errno;
        goto out_f;
    }

    err = 0;
out_f:
    if (fclose(f) < 0 && !err)
        err = errno;
out_procs:
    free(procs);
out_newdir:
    free(newdir);
    return err ? -1 : 0;
}

static int init_cgroups(void) {
    FILE *f = fopen("/proc/self/cgroup", "r");
    if (!f)
        return -1;

    ssize_t size;
    size_t bufsize = 0;
    char *str = NULL;
    while ((size = getline(&str, &bufsize, f)) > 0) {
        char *run = str;
        char *id = strsep(&run, ":");
        char *controller = strsep(&run, ":");
        char *path = strsep(&run, "\n");
        if (!id || !controller || !path) {
            errno = EINVAL;
            break;
        }
        if (init_cgroups_controller(controller, path) < 0)
            break;
    }

    int err = errno;
    free(str);
    if (fclose(f) < 0 && !err)
        err = errno;
    return err ? -1 : 0;
}

static void cleanup_cgroups(void) {
    while (!SLIST_EMPTY(&cgroup_entry_head)) {
        struct cgroup_entry *cgroup_entry = SLIST_FIRST(&cgroup_entry_head);
        SLIST_REMOVE_HEAD(&cgroup_entry_head, entries);
        char *filename = cgroup_entry->filename;

        char *procs;
        if (asprintf(&procs, "%s/../cgroup.procs", filename) < 0) {
            perror("asprintf");
            goto cont_rmdir;
        }
        FILE *f = fopen(procs, "w");
        if (!f) {
            perror("fopen");
            goto cont_proc;
        }
        if (fprintf(f, "%lu\n", (unsigned long) getpid()) < 0)
            perror("fprintf");
        if (fclose(f) < 0)
            perror("fclose");
cont_proc:
        free(procs);
cont_rmdir:
        if (rmdir(filename) < 0)
            perror("rmdir");
        free(cgroup_entry);
    }
}

static void cancel_cleanup_cgroups(void) {
    while (!SLIST_EMPTY(&cgroup_entry_head)) {
        struct cgroup_entry *cgroup_entry = SLIST_FIRST(&cgroup_entry_head);
        SLIST_REMOVE_HEAD(&cgroup_entry_head, entries);
        free(cgroup_entry);
    }
}

static uid_t user_to_uid(const char *user) {
    char *endptr;
    errno = 0;
    uid_t uid = strtoull(user, &endptr, 10);
    if (!errno && !*endptr)
        return uid;

    errno = 0;
    struct passwd *passwd = getpwnam(user);
    if (!passwd)
        return -1;
    return passwd->pw_uid;
}

static uid_t find_one_subxid(char x) {
                    // 012345678
    char filename[] = "/etc/subxid";
    filename[8] = x;
    FILE *f = fopen(filename, "r");
    if (!f)
        return -1;

    uid_t result = -1;

    ssize_t size;
    size_t bufsize = 0;
    char *str = NULL;
    while ((size = getline(&str, &bufsize, f)) > 0) {
        char *run = str;
        char *user = strsep(&run, ":");
        char *id_start = strsep(&run, ":");
        char *id_length = strsep(&run, "\n");
        if (!str || !id_start || !id_length) {
            fprintf(stderr, "Invalid %s entry ignored", filename);
            continue;
        }

        uid_t uid = user_to_uid(user);
        if (uid == (uid_t) -1) {
            fprintf(stderr, "Unknown user %s in %s ignored", user, filename);
            errno = 0;
            continue;
        }

        if (uid != getuid())
            continue;

        errno = 0;
        char *endptr;
        uid_t val = strtoull(id_start, &endptr, 10);
        if (errno || *endptr || val == (uid_t) -1) {
            fprintf(stderr, "Invalid subuid %s ignored", id_start);
            errno = 0;
            continue;
        }

        result = val;
        break;
    }

    if (result == (uid_t) -1)
        errno = EPERM;

    int err = errno;
    free(str);
    fclose(f);
    errno = err;
    return result;
}

static char *deduce_xid_helper(int pid, char x) {
    uid_t xid;
    switch (x) {
    case 'u':
        xid = getuid();
        break;
    case 'g':
        xid = getgid();
        break;
    default:
        errno = EINVAL;
        return NULL;
    }

    uid_t subxid = find_one_subxid(x);
    if (subxid == (uid_t) -1)
        return NULL;

    char *helper;
    if (asprintf(&helper, "new%cidmap %d 0 %u 1 1 %u 1", x,
                pid, xid, subxid) < 0)
        return NULL;

    return helper;
}

static int new_xid_map(int pid, char x, const char *id_map) {
    char *helper;
    if (id_map) {
        if (asprintf(&helper, "new%cidmap %d %s", x, pid, id_map) < 0)
            return -1;
    } else {
        if (!(helper = deduce_xid_helper(pid, x)))
            return -1;
    }
    int status = system(helper);
    int err = errno;
    free(helper);
    if (status == -1) {
        errno = err;
        return -1;
    }
    if (!WIFEXITED(status) || WEXITSTATUS(status)) {
        // XXX What is the best errno?
        errno = EPERM;
        return -1;
    }
    return 0;
}

static int new_ugid_map(int pid, const char *uid_map, const char *gid_map) {
    if (new_xid_map(pid, 'u', uid_map) < 0)
        return -1;
    if (new_xid_map(pid, 'g', gid_map) < 0)
        return -1;
    return 0;
}

int main(int argc, char **argv) {
    enum {
        OPT_BEGIN_LONG = 1000,
        OPT_UID_MAP,
        OPT_GID_MAP,
        OPT_ROOT,
    };
    static const struct option options[] = {
        {"uid_map", required_argument, NULL, OPT_UID_MAP},
        {"gid_map", required_argument, NULL, OPT_GID_MAP},
        {"root", required_argument, NULL, OPT_ROOT},
        {0}
    };

    const char *uid_map = NULL;
    const char *gid_map = NULL;
    struct child_options child_options = {0};

    for (int opt; (opt = getopt_long(argc, argv, "", options, NULL)) != -1; ) {
        switch (opt) {
        case OPT_UID_MAP:
            uid_map = optarg;
            break;
        case OPT_GID_MAP:
            gid_map = optarg;
            break;
        case OPT_ROOT:
            child_options.root = optarg;
            break;
        default:
            return 1;
        }
    }
    if (optind < argc) {
        fprintf(stderr, "%s: extra argument '%s'", argv[0], argv[optind]);
        return 1;
    }

    atexit(cleanup_cgroups);
    if (init_cgroups() < 0) {
        int err = errno;
        perror("init_cgroups");
        if (err == ENOSPC) {
            fputs("This may be caused by cgroup.clone_children being 0 "
                  "for cpuset namespace.\n", stderr);
        }
        return 1;
    }

    int pipefd[2];
    if (pipe(pipefd) < 0) {
        perror("pipe");
        return 1;
    }
    // XXX This doesn't works for all architectures,
    // XXX getpid() will not work for children
    int pid = syscall(SYS_clone,
            SIGCHLD | CLONE_NEWCGROUP | CLONE_NEWIPC |
            CLONE_NEWNET | CLONE_NEWNS | CLONE_NEWPID |
            CLONE_NEWUSER | CLONE_NEWUTS,
            0, NULL, 0);
    switch (pid) {
    case -1:
        perror("syscall(SYS_clone)");
        return 1;
    case 0:
        // child
        close(pipefd[1]);

        cancel_cleanup_cgroups();

        char buf[1];
        switch (read(pipefd[0], buf, 1)) {
        case -1:
            perror("read");
            return 1;
        case 0:
            fputs("parent closed pipe without writing\n", stderr);
            return 1;
        }
        close(pipefd[0]);
        return child_main(&child_options);
    }
    // parent
    close(pipefd[0]);

    bool post_clone_ok = true;
    if (new_ugid_map(pid, uid_map, gid_map) < 0) {
        perror("new_ugid_map");
        post_clone_ok = false;
    }

    if (post_clone_ok)
        write(pipefd[1], "", 1);
    close(pipefd[1]);
    int wstatus;
    if (waitpid(pid, &wstatus, 0) < 0) {
        perror("waitpid");
        return 1;
    }
    if (!post_clone_ok)
        return 1;

    if (WIFEXITED(wstatus)) {
        return WEXITSTATUS(wstatus);
    }
    if (WIFSIGNALED(wstatus)) {
        raise(WTERMSIG(wstatus));
        return 128 + WTERMSIG(wstatus);
    }
    fprintf(stderr, "unknown wstatus %d", wstatus);
    return 1;
}
