#ifndef TOYOJ_SANDBOX_GRANDCHILD_H
#define TOYOJ_SANDBOX_GRANDCHILD_H
#include <sys/types.h>
struct grandchild_options {
    const char *filename;
    const char *const *argv;
    const char *stdin;
    const char *stdout;
    const char *stderr;
    const char *chdir;
    uid_t uid;
    gid_t gid;
};

_Noreturn void grandchild_main(const struct grandchild_options *options);
#endif // TOYOJ_SANDBOX_GRANDCHILD_H
