#ifndef TOYOJ_SANDBOX_CHILD_H
#define TOYOJ_SANDBOX_CHILD_H
struct child_options {
    const char *root;
};
int child_main(const struct child_options *child_options);
#endif // TOYOJ_SANDBOX_CHILD_H
