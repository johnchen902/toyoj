#include "child.h"

#include <errno.h>
#include <fcntl.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mount.h>
#include <sys/prctl.h>
#include <sys/stat.h>
#include <unistd.h>

#include "child-execute.h"

#define ON_ERROR(RET, MESSAGE, ACTION) \
    do { \
        const int ON_ERROR_ret = (RET); \
        if (ON_ERROR_ret < 0) { \
            const char *ON_ERROR_message = (MESSAGE); \
            if (ON_ERROR_message) { \
                fputs(ON_ERROR_message, stderr); \
                fprintf(stderr, ": %s\n", strerror(-ON_ERROR_ret)); \
            } \
            ACTION; \
        } \
    } while (0)

static int make_root(const char *root_opt) {
    if (root_opt) {
        int ret = mkdir(root_opt, 0755) < 0 ? -errno : 0;
        ON_ERROR(ret, "mkdir(root_opt)", return ret);
        return ret;
    }

    const char *tmp = getenv("TMPDIR");
    if (!tmp)
        tmp = "/tmp";

    int ret;
    char *template;
    ret = asprintf(&template, "%s/toyoj-sandbox-XXXXXX", tmp) < 0 ? -errno : 0;
    ON_ERROR(ret, "asprintf", return ret);

    ret = mkdtemp(template) ? 0 : -errno;
    ON_ERROR(ret, "mkdtemp", goto out);

    ret = chmod(template, 0755) < 0 ? -errno : 0;
    ON_ERROR(ret, "chmod", goto out_rmdir);

    ret = chdir(template) < 0 ? -errno : 0;
    ON_ERROR(ret, "chdir", goto out_rmdir);

out:
    free(template);
    return ret;

out_rmdir:
    if (rmdir(template) < 0)
        perror("rmdir");
    goto out;
}

static int touch(const char *target) {
    int fd = open(target, O_WRONLY | O_CREAT | O_EXCL, 0755);
    int ret = fd < 0 ? -errno : 0;
    ON_ERROR(ret, "open", return ret);

    ret = close(fd) < 0 ? -errno : 0;
    ON_ERROR(ret, "close", return ret);

    return ret;
}

static int bind_mount(const char *source, const char *target) {
    return mount(source, target, NULL, MS_BIND, NULL) < 0 ? -errno : 0;
}

static int populate_root(void) {
    // XXX Better option than hard-coding?
#define DO_ACTION(ACTION) \
    ON_ERROR(ret = ACTION, #ACTION, return ret)
#define DO_ACTION_ERRNO(ACTION) \
    ON_ERROR(ret = (ACTION) < 0 ? -errno : 0, #ACTION, return ret)
    int ret;

    DO_ACTION_ERRNO(symlink("usr/bin", "bin"));
    DO_ACTION_ERRNO(symlink("usr/bin", "sbin"));
    DO_ACTION_ERRNO(symlink("usr/lib", "lib"));
    DO_ACTION_ERRNO(symlink("usr/lib", "lib64"));

    DO_ACTION_ERRNO(mkdir("dev", 0755));
    DO_ACTION_ERRNO(symlink("/proc/self/fd", "dev/fd"));
    DO_ACTION_ERRNO(symlink("/proc/self/fd/0", "dev/stdin"));
    DO_ACTION_ERRNO(symlink("/proc/self/fd/1", "dev/stdout"));
    DO_ACTION_ERRNO(symlink("/proc/self/fd/2", "dev/stderr"));
#define DO_MOUNT_DEVICE(DEVICE) \
    do { \
        DO_ACTION(touch(DEVICE)); \
        DO_ACTION(bind_mount("/" DEVICE, DEVICE)); \
    } while (0)
    DO_MOUNT_DEVICE("dev/null");
    DO_MOUNT_DEVICE("dev/zero");
    DO_MOUNT_DEVICE("dev/full");
    DO_MOUNT_DEVICE("dev/random");
    DO_MOUNT_DEVICE("dev/urandom");
#undef DO_MOUNT_DEVICE

    DO_ACTION_ERRNO(mkdir("etc", 0755));
    DO_ACTION_ERRNO(symlink("../proc/self/mounts", "etc/mtab"));

    DO_ACTION_ERRNO(mkdir("proc", 0755));
    DO_ACTION_ERRNO(mount("proc", "proc", "proc", MS_NOSUID | MS_NODEV | MS_NOEXEC, NULL));

    DO_ACTION_ERRNO(mkdir("sys", 0755));
    // XXX we don't need sysfs but maybe we should add an option
    DO_ACTION_ERRNO(mkdir("sys/fs", 0755));
    DO_ACTION_ERRNO(mkdir("sys/fs/cgroup", 0755));
    DO_ACTION_ERRNO(mount("tmpfs", "sys/fs/cgroup", "tmpfs", MS_NOSUID | MS_NODEV | MS_NOEXEC, "mode=755"));
    DO_ACTION_ERRNO(mkdir("sys/fs/cgroup/unified", 0755));
    DO_ACTION_ERRNO(mount("cgroup", "sys/fs/cgroup/unified", "cgroup2", MS_NOSUID | MS_NODEV | MS_NOEXEC, NULL));
#define DO_MOUNT_CGROUPV1(CONTROLLERS) \
    do { \
        DO_ACTION_ERRNO(mkdir("sys/fs/cgroup/" CONTROLLERS, 0755)); \
        DO_ACTION_ERRNO(mount("cgroup", "sys/fs/cgroup/" CONTROLLERS, "cgroup", MS_NOSUID | MS_NODEV | MS_NOEXEC, CONTROLLERS)); \
    } while (0)
    DO_MOUNT_CGROUPV1("freezer");
    DO_MOUNT_CGROUPV1("cpu,cpuacct");
    DO_MOUNT_CGROUPV1("cpuset");
    DO_MOUNT_CGROUPV1("net_cls,net_prio");
    DO_MOUNT_CGROUPV1("pids");
    DO_MOUNT_CGROUPV1("blkio");
    DO_MOUNT_CGROUPV1("devices");
    DO_MOUNT_CGROUPV1("memory");
#undef DO_MOUNT_CGROUPV1
    DO_ACTION_ERRNO(symlink("cpu,cpuacct", "sys/fs/cgroup/cpu"));
    DO_ACTION_ERRNO(symlink("cpu,cpuacct", "sys/fs/cgroup/cpuacct"));
    DO_ACTION_ERRNO(symlink("net_cls,net_prio", "sys/fs/cgroup/net_cls"));
    DO_ACTION_ERRNO(symlink("net_cls,net_prio", "sys/fs/cgroup/net_prio"));

    DO_ACTION_ERRNO(mkdir("usr", 0755));
    DO_ACTION_ERRNO(mount("/usr", "usr", NULL, MS_BIND, NULL));

    DO_ACTION_ERRNO(mkdir("tmp", 01777));
    DO_ACTION_ERRNO(mount("tmpfs", "tmp", "tmpfs", MS_NOSUID | MS_NODEV, "size=1g"));

    return ret;
#undef DO_ACTION_ERRNO
#undef DO_ACTION
}

static int setup_fs(const struct child_options *child_options) {
    int ret;

    ret = mount(NULL, "/", NULL, MS_PRIVATE | MS_REC, NULL) < 0 ? -errno : 0;
    ON_ERROR(ret, "Setting propagation type to private", return ret);

    ret = make_root(child_options->root);
    ON_ERROR(ret, "Creating new root", return ret);

    ret = mount(".", ".", NULL, MS_BIND, NULL) < 0 ? -errno : 0;
    ON_ERROR(ret, "Bind mounting new root to itself", return ret);

    ret = populate_root();
    ON_ERROR(ret, "Populating new root", return ret);

    ret = chroot(".") < 0 ? -errno : 0;
    ON_ERROR(ret, "chroot", return ret);

    return ret;
}

static int do_read_command(char *filename) {
    // command: read FILENAME
    // stdout: [BYTES\nDATA]*ok|error\n

    if (!filename)
        return -EINVAL;

    int fd = open(filename, O_RDONLY);
    if (fd < 0)
        return -errno;

    char buf[BUFSIZ];
    ssize_t size;
    while ((size = read(fd, buf, sizeof(buf))) > 0) {
        printf("%zd\n", size);
        fwrite(buf, size, 1, stdout);
    }

    int ret;
    if (size == 0) {
        puts("ok");
        ret = 0;
    } else {
        ret = -errno;
    }

    close(fd);
    return ret;
}

static int full_write(int fd, const void *buf, size_t count) {
    while (count) {
        ssize_t sz = write(fd, buf, count);
        if (sz < 0)
            return -errno;
        count -= sz;
        buf = (const char*) buf + sz;
    }
    return 0;
}

static int do_write_command(char *filename) {
    // command: write FILENAME
    // stdin: [BYTES DATA]*0  (There is a space after 0)
    // stdout: error\n|ready\n(ok|error)\n

    int fd = open(filename, O_WRONLY | O_CREAT | O_TRUNC, 0755);
    if (fd < 0)
        return -errno;

    puts("ready");
    fflush(stdout);

    int ret = 0;

    char *buf = NULL;
    while (1) {
        size_t bytes;
        if (scanf("%zu", &bytes) != 1) {
            perror("scanf");
            exit(2);
        }

        char *newbuf = realloc(buf, bytes);
        if (bytes && !newbuf) {
            perror("malloc");
            exit(2);
        }
        buf = newbuf;

        (void) getchar(); // any separator will do

        if (bytes == 0)
            break;

        if (fread(buf, bytes, 1, stdin) != 1) {
            perror("fread");
            exit(2);
        }

        if (full_write(fd, buf, bytes) < 0) {
            ret = -errno;
            perror("write");
            // move on
        }
    }
    free(buf);

    if (close(fd) < 0 && ret == 0)
        ret = -errno;

    if (ret == 0)
        puts("ok");

    return ret;
}

static int dispatch_command(char *command) {
    char *verb = strsep(&command, " ");
    if (strcmp(verb, "true") == 0) {
        puts("ok");
        return 0;
    } else if (strcmp(verb, "execute") == 0) {
        return do_execute_command(command);
    } else if (strcmp(verb, "read") == 0) {
        return do_read_command(command);
    } else if (strcmp(verb, "write") == 0) {
        return do_write_command(command);
    } else {
        return -EINVAL;
    }
}

int child_main(const struct child_options *child_options) {
    // XXX there is a race condition
    if (prctl(PR_SET_PDEATHSIG, SIGKILL, 0, 0, 0) < 0) {
        perror("prctl");
        fputs("Child won't die even if parent does.\n", stderr);
    }

    int ret = setup_fs(child_options);
    ON_ERROR(ret, "Setting up root filesystem", return 1);

    ssize_t size;
    size_t bufsize;
    char *str = NULL;
    while ((size = getline(&str, &bufsize, stdin)) > 0) {
        char *newline = strchr(str, '\n');
        if (newline)
            *newline = '\0';
        ret = dispatch_command(str);
        if (ret < 0) {
            fprintf(stderr, "%s\n", strerror(-ret));
            puts("error");
        }
        fflush(stdout);
    }
    free(str);

    return ret < 0 ? 1 : 0;
}
