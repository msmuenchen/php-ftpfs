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
    public $debug_curl=false;
    public $base_url="";
    public $debug_raw=false;
    
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
            "KEY_CACHEDIR",
            "KEY_DEBUG_CURL",
            "KEY_DEBUG_RAW"
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
            "cachedir " => $this->opt_keys["KEY_CACHEDIR"],
            "debug_curl" => $this->opt_keys["KEY_DEBUG_CURL"],
            "debug_raw" => $this->opt_keys["KEY_DEBUG_RAW"]
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
        
        $this->base_url=sprintf("ftp://%s:%s@%s:%d%s",urlencode($this->user),urlencode($this->pass),$ip,$this->controlport,$this->remotedir);
        
        if($this->debug) {
            printf("cURL base URL: '%s'\n",$this->base_url);
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
                $this->cachedir.=sprintf("/%s_%s/",$this->host,md5($this->base_url));
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
        $this->curl=curl_init($this->base_url."wp-content/");
        if($this->debug_curl) {
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

/*        print_r($this->curl_mlsd("/"));
        print_r($this->curl_mlsd("/wp-content/themes/Aqua/"));
        print_r($this->curl_mlsd("/wp-config.php"));
        
        exit;
*/        //run FUSE
        if($this->debug)
            printf("Calling fuse_main with args: %s",print_r($argv,true));
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
    -o debug_curl             set CURLOPT_VERBOSE
    -o debug_raw              print the raw data recieved by the curl interfaces

", $this->name, $argv[0]);
                return 0;
                break;
            case $this->opt_keys["KEY_VERSION"]:
                printf("%s %s\n", $this->name, $this->version);
                return 1;
                break;
            case $this->opt_keys["KEY_DEBUG"]:
                $this->debug=true;
                return 1;
                break;
            case $this->opt_keys["KEY_DEBUG_CURL"]:
                $this->debug_curl=true;
                return 0;
                break;
            case $this->opt_keys["KEY_DEBUG_RAW"]:
                $this->debug_raw=true;
                return 0;
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
    
    //callback not anonymous because we need to transfer data out of this function
    public function curl_mlst_cb($res,$str) {
        if($this->debug_raw)
            printf("curl_mlst_cb: got '%s'\n",$str);
        switch($this->curl_mlst_data["state"]) {
            case 0: //didn't see a 215 from the SYST
                if(substr($str,0,4)!="215 ") {
                    //prevent further processing
//                    curl_setopt_array($res,array(CURLOPT_HEADERFUNCTION=>NULL));
//                    $this->curl_mlst_data["state"]=FALSE;
//                    $this->curl_mlst_data["error"]=$str;
                    break;
                }
                $this->curl_mlst_data["state"]=1;
            break;
            case 1: //have seen a 250 OK from CWD, waiting for 250-Begin from MLST
                if(substr($str,0,4)!="250-") {
                    //prevent further processing
                    curl_setopt_array($res,array(CURLOPT_HEADERFUNCTION=>NULL));
                    $this->curl_mlst_data["state"]=FALSE;
                    $this->curl_mlst_data["error"]=$str;
                    break;
                }
                $this->curl_mlst_data["state"]=2;
            break;
            case 2: //have seen the 250- from MLST, now everything that does NOT begin with 250 is data from MLST
                if(substr($str,0,3)=="250") {
                    curl_setopt_array($res,array(CURLOPT_HEADERFUNCTION=>NULL));
                    $this->curl_mlst_data["state"]="OK";
                    break;
                }
                $this->curl_mlst_data["data"][]=$str;
            break;
        }
        return strlen($str);
    }
    
    //MLST: get information about a specific file
    //TODO: If the connection gets reset, the callback has no information about the state of the connection
    //      and so will see the "220 Ok login now" message as start message for the parsing. So, we hope
    //      that cURL doesn't run SYST on connects in the future and use its unique 215 to establish a clean
    //      state.
    // See also: RFC 3659 @ http://www.ietf.org/rfc/rfc3659.txt
    public function curl_mlst($path) {
        //we'll prepend the path with remotedir, which already has a slash
        if(substr($path,0,1)=="/")
            $path=substr($path,1);
        $abspath=$this->remotedir.$path;

        $this->curl_mlst_data=array("state"=>0,"data"=>array());
        $ret=curl_setopt_array($this->curl,array(
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_BINARYTRANSFER=>true,
            CURLOPT_URL=>$this->base_url,
            CURLOPT_QUOTE=>array("SYST","MLST $abspath"),//"CWD $abspath","MLST"),
            CURLOPT_NOBODY=>true,
            CURLOPT_CUSTOMREQUEST=>"",
            CURLOPT_HEADERFUNCTION=>array($this,"curl_mlst_cb")
            
        ));
        if($ret===FALSE)
            trigger_error(sprintf("cURL error: '%s'",curl_error($this->curl)),E_USER_ERROR);
        
        if($this->debug)
            printf("Requesting cURL MLST from base '%s' / path '%s' / abspath '%s'\n",$this->base_url,$path,$abspath);
        
        $ret=curl_exec($this->curl);
        
        //if curl_mlst_data["state"] is FALSE, then no need to print also the curl error
        if($ret===FALSE && $this->curl_mlst_data["state"]!==FALSE)
            printf("cURL error: '%s'\n",curl_error($this->curl));
        
        if($this->curl_mlst_data["state"]===FALSE) {
            $ec=substr($this->curl_mlst_data["error"],0,3);
            printf("ec is %s\n",$ec);
            switch($ec) {
                case "550": //Requested action not taken. File unavailable (e.g., file not found, no access).
                    $ret = -FUSE_ENOENT;
                break;
                case "530": //Not logged in.
                case "350": //Requested file action pending further information
                case "332": //Need account for login.
                    $ret = -FUSE_EACCES;
                break;
                default: //most likely a link error...
                    $ret = -FUSE_EBADF;
            }
        } else {
            if($this->debug_raw)
                printf("Raw data: %s",print_r($this->curl_mlst_data,true));
            $d = explode(";",$this->curl_mlst_data["data"][0]);
            array_pop($d); //the last bit is the file name, which we already know
            $ret=array();
            foreach($d as $v) {
                list($k,$v)=explode("=",$v);
                $ret[trim(strtolower($k))]=trim(strtolower($v));
            }
            //split up perms, if supplied
            if(isset($ret["perm"]))
                $ret["perm"]=array_flip(str_split($ret["perm"]));
            else
                $ret["perm"]=array();
            //convert timestamps to unix timestamps
            if(isset($ret["modify"]))
                //Timestamp is UTC and may contain .sss to give sub-second precision, filter this out
                $ret["modify"]=DateTime::createFromFormat("YmdHis",substr($ret["modify"],0,14),new DateTimeZone("UTC"))->getTimestamp();
            else
                $ret["modify"]=0;
            if(isset($ret["create"]))
                $ret["create"]=DateTime::createFromFormat("YmdHis",substr($ret["create"],0,14),new DateTimeZone("UTC"))->getTimestamp();
            else
                $ret["create"]=$ret["modify"];
            
            return $ret;
        }
        
        curl_setopt_array($this->curl,array(CURLOPT_HEADERFUNCTION=>NULL));
        return $ret;
    }
    
    //MLSD: get information about the files in a directory
    public function curl_mlsd($path) {
        //MLSD must be a path!
        if(substr($path,-1,1)!="/")
            return -FUSE_EINVAL;
        
        if(substr($path,0,1)=="/")
            $path=substr($path,1);
        $abspath=$this->base_url.$path;

        $ret=curl_setopt_array($this->curl,array(
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_BINARYTRANSFER=>true,
            CURLOPT_URL=>$abspath,
            CURLOPT_QUOTE=>array(),
            CURLOPT_NOBODY=>false,
            CURLOPT_CUSTOMREQUEST=>"MLSD",
            CURLOPT_HEADERFUNCTION=>NULL           
        ));
        if($ret===FALSE)
            trigger_error(sprintf("cURL error: '%s'",curl_error($this->curl)),E_USER_ERROR);
        
        if($this->debug)
            printf("Requesting cURL MLSD from base '%s' / path '%s' / abspath '%s'\n",$this->base_url,$path,$abspath);
        
        $ret=curl_exec($this->curl);
        
        if($ret===FALSE) {
            printf("cURL error: '%s'\n",curl_error($this->curl));
            return -FUSE_EINVAL;
        }
        
        //normalize linebreaks
        $ret=str_replace("\r","\n",$ret);
        $data=explode("\n",$ret);
        $ret=array();
        foreach($data as $v) {
            $v=trim($v);
            if(trim($v)=="")
                continue;
            
            if($this->debug_raw)
                printf("Raw data: '%s'\n",$v);

            $d = explode(";",$v);
            $fn=trim(array_pop($d)); //the last bit is the file name
            
            $entry=array();
            foreach($d as $v) {
                list($k,$v)=explode("=",$v);
                $entry[trim(strtolower($k))]=trim(strtolower($v));
            }
            //split up perms, if supplied
            if(isset($entry["perm"]))
                $entry["perm"]=array_flip(str_split($entry["perm"]));
            else
                $entry["perm"]=array();
            //convert timestamps to unix timestamps
            if(isset($entry["modify"]))
                //Timestamp is UTC and may contain .sss to give sub-second precision, filter this out
                $entry["modify"]=DateTime::createFromFormat("YmdHis",substr($entry["modify"],0,14),new DateTimeZone("UTC"))->getTimestamp();
            else
                $entry["modify"]=0;
            if(isset($entry["create"]))
                $entry["create"]=DateTime::createFromFormat("YmdHis",substr($entry["create"],0,14),new DateTimeZone("UTC"))->getTimestamp();
            else
                $entry["create"]=$entry["modify"];
            
            $ret[$fn]=$entry;
        }
        
        curl_setopt_array($this->curl,array(CURLOPT_HEADERFUNCTION=>NULL));
        return $ret;
    }
    
    public function getattr($path, &$st) {
        if($this->debug)
            printf("PHPFS: %s called, path: '%s'\n", __FUNCTION__, $path);
        
        if(substr($path,0,1)!="/") {
            if($this->debug)
                printf("getattr('%s'): path doesn't contain a /");
            return -FUSE_EINVAL;
        }
        $relpath=substr($path,1);
        $data=$this->curl_mlst($relpath);
        if($data<0)
            return $data;
        
        $st['dev']     = 0;
        $st['ino']     = 0;
        $st['mode']    = 0;
        $st['nlink']   = 0;
        //TODO: assign effective uid/gid...
        $st['uid']     = 0;
        $st['gid']     = 0;
        $st['rdev']    = 0;
        $st['size']    = 0;
        $st['atime']   = $data["modify"];
        $st['mtime']   = $data["modify"];
        $st['ctime']   = $data["create"];
        $st['blksize'] = 0;
        $st['blocks']  = 0;
        
        //TODO: Check allow_other for the permissions
        // See http://www.perlfect.com/articles/chmod.shtml for an explanation of Unix modes
        if($data["type"]=="file") {
            $st['mode']|=FUSE_S_IFREG;
            $st['nlink']=1;
            $st['size']=$data["size"];
            if(isset($data["perm"]["r"]))
                $st['mode']|=0444;
            if(isset($data["perm"]["w"]))
                $st['mode']|=0222;
        }
        if($data["type"]=="dir") {
            $st['mode']|=FUSE_S_IFDIR;
            if(isset($data["perm"]["e"])) //e in directories=cd works, which is mode +x
                $st['mode']|=0111;
            if(isset($data["perm"]["l"])) //l in directories=ls works, which is mode +r
                $st['mode']|=0444;
            if(isset($data["perm"]["p"])) //p in directories=can delete files, which is mode +w
                $st['mode']|=0222;
            $st['nlink']=1;
            printf("dir %s, modes %s\n",$path,compact_pa($data["perm"]));
        }
        
        if($this->debug)
            printf("PHPFS: %s returning, st is %s\n",__FUNCTION__,compact_pa($st));
        return 0; 
    }
    public function readlink() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    public function getdir($path,&$ret) {
        if($this->debug)
            printf("PHPFS: %s called, path '%s'\n", __FUNCTION__,$path);
        
        if(substr($path,-1,1)!="/")
            $path.="/";
        
        //Check if the directory exists
        $dir=$this->curl_mlst($path);
        if($dir<0) {
            printf("getdir: '%s' does not exist\n",$path);
            return $dir;
        }
        
        $files=$this->curl_mlsd($path);
        if($files<0) {
            printf("getdir: '%s' returned error %d\n",$files);
            return $files;
        }
        
        if(sizeof($files)<2) { //must always be at least two elements big (parent+current dir)
            printf("getdir: '%s' has less than 2 elements\n");
            return -FUSE_EINVAL;
        }
        
        $ret=array();
        foreach($files as $fn=>$data) {
            if($this->debug)
                printf("Adding file '%s' in '%s' to list\n",$fn,$path);
            if($data["type"]=="file")
                $ret[$fn]=array("type"=>FUSE_DT_REG);
            elseif($data["type"]=="dir" || $data["type"]=="cdir" || $data["type"]=="pdir")
                $ret[$fn]=array("type"=>FUSE_DT_DIR);
            else
                printf("Unknown type '%s' for file '%s' in path '%s'\n",$data["type"],$fn,$path);
        }
        return 0;
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

//"compact" print_r($a,true)
function compact_pa($a) {
    $buf="";
    foreach($a as $k=>$v)
        $buf.="'$k'=>'$v',";
    return substr($buf,0,-1);
}
$fuse = new PHPFTPFS();
$fuse->main($argc, $argv);
