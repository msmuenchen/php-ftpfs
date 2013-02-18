<?php
//Load FUSE, if it isn't already loaded by php.ini
if (!extension_loaded("fuse"))
    dl("fuse.so");
error_reporting(E_ALL);

class PHPFTPFS extends Fuse {
    //basic needed stuff
    public $name = "phpftpfs";
    public $version = "0.1a";
    public $debug=false;
    
    public $host="localhost";
    public $user="anonymous";
    public $pass="user@example.com";
    public $pasv=false;
    public $dataport=20;
    public $controlport=21;
    public $remotedir="/";
    public $ipv6=false;
    public $cache_maxage=60;
    public $cachedir="";
    public $use_cache=false;
    
    private $curl=NULL;
    
    public function __construct() {
        $this->opt_keys = array_flip(array(
            "KEY_HELP",
            "KEY_FUSE_HELP",
            "KEY_VERSION",
            "KEY_DEBUG",
            "KEY_HOST",
            "KEY_USER",
            "KEY_PASSWORD",
            "KEY_CACHE_MAXAGE",
            "KEY_CONTROLPORT",
            "KEY_PASV",
            "KEY_REMOTEDIR",
            "KEY_ENABLE_IPV6",
            "KEY_CACHEDIR"
        ));
        $this->opts     = array(
            "--help" => $this->opt_keys["KEY_HELP"],
            "--version" => $this->opt_keys["KEY_VERSION"],
            "-h" => $this->opt_keys["KEY_HELP"],
            "-H" => $this->opt_keys["KEY_FUSE_HELP"],
            "-V" => $this->opt_keys["KEY_VERSION"],
            "-d" => $this->opt_keys["KEY_DEBUG"],
            "host " => $this->opt_keys["KEY_HOST"],
            "user " => $this->opt_keys["KEY_USER"],
            "password " => $this->opt_keys["KEY_PASSWORD"],
            "cache_maxage " => $this->opt_keys["KEY_CACHE_MAXAGE"],
            "controlport " => $this->opt_keys["KEY_CONTROLPORT"],
            "pasv" => $this->opt_keys["KEY_PASV"],
            "remotedir " => $this->opt_keys["KEY_REMOTEDIR"],
            "ipv6" => $this->opt_keys["KEY_ENABLE_IPV6"],
            "cachedir " => $this->opt_keys["KEY_CACHEDIR"]
        );
        $this->userdata = array();
    }
    public function __destruct() {
        if($this->curl)
            curl_close($this->curl);
        $this->curl=NULL;
    }
    public function main($argc, $argv) {
        $res = $this->opt_parse($argc, $argv, $this->userdata, $this->opts, array(
            $this,
            "opt_proc"
        ));
        if ($res === false) {
            printf("Error in opt_parse\n");
            exit;
        }
        if($this->debug) {
            printf("Opening connection to ftp://%s:%s@%s:%d%s\n",$this->user,$this->pass,$this->host,$this->controlport,$this->remotedir);
            if($this->pasv)
                printf("Using passive transfer\n");
            if($this->ipv6)
                printf("Using IPv6 where available\n");
            if($this->cachedir!="")
                printf("Using '%s' as cache directory, maximum age=%d seconds\n",$this->cachedir,$this->cache_maxage);
        }

        //Assemble the URL
        if($this->ipv6) {
            $d=dns_get_record($this->host,DNS_AAAA);
        } else {
            $d=dns_get_record($this->host,DNS_A);
        }
        if($d===FALSE)
            trigger_error(sprintf("Host %s not found",$this->host),E_USER_ERROR);
        $d=$d[0];

        $ip=($this->ipv6) ? "[".$d["ipv6"]."]" : $d["ip"];
        
        $base_url=sprintf("ftp://%s:%s@%s:%d%s",urlencode($this->user),urlencode($this->pass),$ip,$this->controlport,$this->remotedir);
        
        if($this->debug) {
            printf("cURL base URL: '%s'\n",$base_url);
        }

        //Do we have a cache directory? If yes, test if it is usable.
        if($this->use_cache) {
            //Check if the directory exists
            $p=realpath($this->cachedir);
            if($p===false) {
                trigger_error(sprintf("Cache directory '%s' is not a valid directory, disabling cache",$this->cachedir),E_USER_WARNING);
                $this->use_cache=false;
            } else {
                $this->cachedir=$p;
                if($this->debug)
                    printf("Root cachedir: '%s'\n",$this->cachedir);
                
                //Try to create the cachedir for this connection (hash of base_url)
                $this->cachedir.=sprintf("/%s_%s/",$this->host,md5($base_url));
                if($this->debug)
                    printf("Connection cachedir: '%s'\n",$this->cachedir);
                if(!is_dir($this->cachedir)) {
                    $ret=mkdir($this->cachedir,0700);
                    if($ret===false)
                        trigger_error(sprintf("Could not create cache directory '%s'",$this->cachedir),E_USER_ERROR);
                }
            }
        }
        
        //Open (and test) cURL connection
        $this->curl=curl_init($base_url);
        if($this->debug) {
            $ret=curl_setopt($this->curl,CURLOPT_VERBOSE,true);
            if($ret===FALSE)
                trigger_error(sprintf("cURL error: '%s'",curl_error($this->curl)),E_USER_ERROR);
        }
        $ret=curl_setopt_array($this->curl,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_BINARYTRANSFER=>true));
        if($ret===FALSE)
            trigger_error(sprintf("cURL error: '%s'",curl_error($this->curl)),E_USER_ERROR);
        
        $ret=curl_exec($this->curl);
        if($ret===FALSE)
            trigger_error(sprintf("cURL error: '%s'",curl_error($this->curl)),E_USER_ERROR);
        var_dump($ret);
        //run FUSE
//        $this->fuse_main($argc, $argv);
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
    -o host=s                 Hostname or IP of remote host, default 'localhost'
    -o user=s                 Remote user name, default 'anonymous'
    -o password=s             Password of remote user, default 'user@example.com'
    -o cache_maxage=n         Maximum age of cached files in seconds, default 60
    -o pasv                   Use PASV FTP mode instead of active transfer mode
    -o dataport=n             Data port of FTP server (for ACT transfer), default 20
    -o controlport=n          Control port of FTP server, default 21
    -o remotedir=s            remote directory to use as base, default /
    -o ipv6                   use IPv6 if having an AAAA record for the server
    -o cachedir=s             directory for file cache, will be set readable only
                              to the user calling the script. Can be shared by multiple
                              %1\$s instances. If not specified, caching is disabled.

", $this->name, $argv[0]);
                return 0;
                break;
            case $this->opt_keys["KEY_VERSION"]:
                printf("%s %s\n", $this->name, $this->version);
                return 1;
                break;
            case $this->opt_keys["KEY_DEBUG"]:
                printf("debug mode enabled\n");
                $this->debug=true;
                return 1;
                break;
            case $this->opt_keys["KEY_USER"]:
                $this->user=substr($arg,5);
                return 0;
                break;
            case $this->opt_keys["KEY_HOST"]:
                $this->host=substr($arg,5);
                return 0;
                break;
            case $this->opt_keys["KEY_PASSWORD"]:
                $this->pass=substr($arg,9);
                return 0;
                break;
            case $this->opt_keys["KEY_PASV"]:
                $this->pasv=true;
                return 0;
                break;
            case $this->opt_keys["KEY_CACHEDIR"]:
                $this->cachedir=substr($arg,9);
                $this->use_cache=true;
                return 0;
                break;
            case $this->opt_keys["KEY_CONTROLPORT"]:
                $this->controlport=(int)substr($arg,12);
                return 0;
                break;
            case $this->opt_keys["KEY_REMOTEDIR"]:
                $this->remotedir=substr($arg,10);
                if(substr($this->remotedir,0,1)!="/")
                    $this->remotedir="/".$this->remotedir;
                if(substr($this->remotedir,-1,1)!="/")
                    $this->remotedir.="/";
                return 0;
                break;
            case $this->opt_keys["KEY_ENABLE_IPV6"]:
                $this->ipv6=true;
                return 0;
                break;
            case $this->opt_keys["KEY_CACHE_MAXAGE"]:
                $this->cache_maxage=(int)substr($arg,13);
                return 0;
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
$fuse = new PHPFTPFS();
$fuse->main($argc, $argv);
