<?php
//Load FUSE, if it isn't already loaded by php.ini
if (!extension_loaded("fuse"))
    dl("fuse.so");
error_reporting(E_ALL);

class PHPFS extends Fuse {
    //basic needed stuff
    public $name = "phpfs";
    public $version = "1.3.37";
    public function __construct() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        $this->opt_keys = array_flip(array(
            "KEY_HELP",
            "KEY_FUSE_HELP",
            "KEY_VERSION",
            "KEY_DEBUG"
        ));
        $this->opts     = array(
            "--help" => $this->opt_keys["KEY_HELP"],
            "--version" => $this->opt_keys["KEY_VERSION"],
            "-h" => $this->opt_keys["KEY_HELP"],
            "-H" => $this->opt_keys["KEY_FUSE_HELP"],
            "-V" => $this->opt_keys["KEY_VERSION"],
            "-d" => $this->opt_keys["KEY_DEBUG"]
        );
        $this->userdata = array();
    }
    public function __destruct() {
        printf("PHPFS: %s called\n", __FUNCTION__);
    }
    public function main($argc, $argv) {
        printf("PHPFS: %s called\n", __FUNCTION__);
        $res = $this->opt_parse($argc, $argv, $this->userdata, $this->opts, array(
            $this,
            "opt_proc"
        ));
        if ($res === false) {
            printf("Error in opt_parse\n");
            exit;
        }
        $this->fuse_main($argc, $argv);
    }
    public function opt_proc(&$data, $arg, $key, &$argc, &$argv) {
        // return -1 to indicate error, 0 to accept parameter,1 to retain parameter and pase to FUSE
        switch ($key) {
            case FUSE_OPT_KEY_NONOPT:
                return 1;
                break;
            case $this->opt_keys["KEY_FUSE_HELP"]:
                //Add a parameter to tell fuse to show its extended help
                array_push($argv, "-ho");
                $argc++;
            //No break, because we display our own help, and fuse adds its help then
            case $this->opt_keys["KEY_HELP"]:
                fprintf(STDERR, "%1\$s
Marco Schuster <marco@m-s-d.eu>

PHP-FUSE template

Usage: %2\$s [options] mountpoint

Options:
    -o opt,[opt...]           mount options
    -h --help                 this help
    -H                        more help
    -V --version              print version info
    -d                        debug mode

Options specific to %1\$s:
[none]

", $this->name, $argv[0]);
                return 0;
                break;
            case $this->opt_keys["KEY_VERSION"]:
                printf("%s %s\n", $this->name, $this->version);
                return 1;
                break;
            case $this->opt_keys["KEY_DEBUG"]:
                printf("debug mode enabled\n");
                return 1;
                break;
            default:
                return 1;
        }
    }
    public function getattr() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function readlink() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function getdir() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function mknod() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function mkdir() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function unlink() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function rmdir() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function symlink() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function rename() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function link() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function chmod() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function chown() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function truncate() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function utime() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function open() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function read() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function write() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function statfs() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function flush() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function release() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function fsync() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function setxattr() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function getxattr() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function listxattr() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function removexattr() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
}
$fuse = new PHPFS();
$fuse->main($argc, $argv);
