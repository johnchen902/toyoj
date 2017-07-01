import tempfile
import shutil
import os

# This is the content of my root:
# bin@ boot/ dev/ etc/ home/ lib@ lib64@ lost+found/
# mnt/ opt/ proc/ root/ run/ sbin@ srv/ sys/ tmp/ usr/ var/

# Only these are to be kept:
# bin@    symbolic link to usr/bin
# sbin@   symbolic link to usr/bin
# lib@    symbolic link to usr/lib
# lib64@  symbolic link to usr/lib
# etc/    contains file such as /etc/php.ini
# tmp/    1777 directory, initially empty
# dev/    should contains /dev/null, /dev/zero, etc. (TODO how?)
# usr/    bind mount to the real one (TODO how?)
class FakeFileSystem:
    def __init__(self):
        self.root = tempfile.mkdtemp()
        os.symlink('usr/bin', '%s/bin' % self.root)
        os.symlink('usr/bin', '%s/sbin' % self.root)
        os.symlink('usr/lib', '%s/lib' % self.root)
        os.symlink('usr/lib', '%s/lib64' % self.root)
        os.mkdir('%s/etc' % self.root)
        os.mkdir('%s/tmp' % self.root)
        os.chmod('%s/tmp' % self.root, 0o1777)
        os.mkdir('%s/dev' % self.root)
        # TODO add /dev/null, /dev/zero
        os.mkdir('%s/usr' % self.root)
        # TODO bind mount
    def clean_up(self):
        shutil.rmtree(self.root)

    def __enter__(self):
        return self
    def __exit__(self, extype, exvalue, extraceback):
        self.clean_up()

if __name__ == "__main__":
    with FakeFileSystem() as fs:
        input("Press ENTER to continue")
