#include "grandchild.h"

#include <errno.h>
#include <fcntl.h>
#include <grp.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/types.h>
#include <unistd.h>

static int saved_stdin_fd = 0;
static int saved_stderr_fd = 2;

static _Noreturn void die(const char *s) {
    dprintf(saved_stderr_fd, "%s: %m\n", s);
    exit(1);
}

static int dup_cloexec(int oldfd) {
    int fd = dup(oldfd);
    if (fd < 0)
        die("dup");

    int flags = fcntl(fd, F_GETFD);
    if (flags < 0)
        die("fcntl");
    if (fcntl(fd, F_SETFD, flags | FD_CLOEXEC) < 0)
        die("fcntl");

    return fd;
}

static void open_dup2(const char *filename, int flags, int newfd) {
    int fd = open(filename, flags, 0755);
    if (fd < 0)
        die("open");
    if (fd != newfd) {
        if (dup2(fd, newfd) < 0)
            die("dup2");
        if (close(fd) < 0)
            die("close");
    }
}

static void wait_for_parent_trigger(void) {
    char buf[1];
    ssize_t size = read(saved_stdin_fd, buf, 1);
    if (size < 0)
        die("read");
    if (size == 0) {
        errno = ECANCELED;
        die("wait_for_final_trigger");
    }
}

_Noreturn void grandchild_main(const struct grandchild_options *options) {
    saved_stdin_fd = dup_cloexec(0);
    saved_stderr_fd = dup_cloexec(2);

    wait_for_parent_trigger();

    if (setresgid(options->gid, options->gid, options->gid) < 0)
        die("setresgid");
    if (setgroups(0, NULL) < 0)
        die("setgroups");
    if (setresuid(options->uid, options->uid, options->uid) < 0)
        die("setresuid");

    if (chdir(options->chdir) < 0)
        die("chdir");

    open_dup2(options->stdin, O_RDONLY, 0);
    open_dup2(options->stdout, O_WRONLY | O_CREAT, 1);
    open_dup2(options->stderr, O_WRONLY | O_CREAT, 2);

    clearenv();

    execv(options->filename, (char *const *) options->argv);
    die("execv");
}
