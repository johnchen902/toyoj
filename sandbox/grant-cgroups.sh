#!/usr/bin/env bash
if [ "$#" -lt 1 ] ; then
    echo "Usage: $0 PID" >&2
    exit 1
fi
pid="$1"

cat "/proc/$1/cgroup" | while IFS=: read id controller path; do
    case $controller in
        "")
            controller="unified";;
        "name=systemd")
            controller="systemd";;
    esac
    dir="/sys/fs/cgroup/$controller/$path/$pid"
    if [ "$controller" = "cpuset" ]; then
        echo 1 > "/sys/fs/cgroup/$controller/$path/cgroup.clone_children"
    fi
    mkdir "$dir" && \
        chown -R johnchen902:johnchen902 "$dir" && \
        echo "$pid" > "$dir/cgroup.procs"
done
