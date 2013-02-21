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
    public $run_fuse=true; //will fuse_main() be called?
    
    public $run_ftpfs=true; //will the ftp fs actually be run?
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
    public $uid=0;
    public $gid=0;
    
    private $curl=NULL;
    private $handles=array(); //keep track of the handles returned by open() here
    private $next_handle_id=1; //do not let phpftpfs run too long, this can overflow!
    
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
            "KEY_DEBUG_RAW",
            "KEY_DEBUG_USER",
            "KEY_URL"
        ));
        $this->opts     = array(
            "--help" => $this->opt_keys["KEY_HELP"],
            "--version" => $this->opt_keys["KEY_VERSION"],
            "-h" => $this->opt_keys["KEY_HELP"],
            "-H" => $this->opt_keys["KEY_FUSE_HELP"],
            "-V" => $this->opt_keys["KEY_VERSION"],
            "-d" => $this->opt_keys["KEY_DEBUG"],
            "ftp_host " => $this->opt_keys["KEY_HOST"],
            "ftp_user " => $this->opt_keys["KEY_USER"],
            "ftp_password " => $this->opt_keys["KEY_PASSWORD"],
            "cache_maxage " => $this->opt_keys["KEY_CACHE_MAXAGE"],
            "controlport " => $this->opt_keys["KEY_CONTROLPORT"],
            "pasv" => $this->opt_keys["KEY_PASV"],
            "remotedir " => $this->opt_keys["KEY_REMOTEDIR"],
            "ipv6" => $this->opt_keys["KEY_ENABLE_IPV6"],
            "cachedir " => $this->opt_keys["KEY_CACHEDIR"],
            "debug_curl" => $this->opt_keys["KEY_DEBUG_CURL"],
            "debug_raw" => $this->opt_keys["KEY_DEBUG_RAW"],
            "debug_user" => $this->opt_keys["KEY_DEBUG_USER"],
            "ftp_url " => $this->opt_keys["KEY_URL"]
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
        
        if($this->run_ftpfs) {
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
            if(filter_var($this->host,FILTER_VALIDATE_IP)===FALSE) {
                if($this->ipv6) {
                    $d=dns_get_record($this->host,DNS_AAAA);
                } else {
                    $d=dns_get_record($this->host,DNS_A);
                }
                if($d===FALSE)
                    trigger_error(sprintf("Host %s not found",$this->host),E_USER_ERROR);
                $d=$d[0];

                $ip=($this->ipv6) ? "[".$d["ipv6"]."]" : $d["ip"];
            } else {
                $is_v6=filter_var($this->host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6);
                $is_v4=filter_var($this->host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4);
                
                if($is_v6 && !$this->ipv6) {
                    printf("Supplied IP '%s' is IPv6, but IPv6 was not enabled (use -o ipv6)\n",$this->host);
                    exit(1);
                } elseif($is_v4 && $this->ipv6) {
                    printf("Supplied IP '%s' is IPv4, but IPv6 was enabled\n",$this->host);
                    exit(1);
                }
                $ip=($this->ipv6) ? "[".$this->host."]" : $this->host;
            }
            
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
            
            $this->uid=posix_geteuid();
            $this->gid=posix_getegid();
            
            //Open (and test) cURL connection
            printf("Opening connection to %s\n",$this->base_url);
            $this->curl=curl_init($this->base_url);
            $this->curl_feat=$this->curl_feat();
            //Check if all required feats are supported
            if(!isset($this->curl_feat["mlst"])) //TODO: Actually fall back to LIST :D
                printf("WARNING: MLST not supported. Falling back to broken LIST instead, you can expect access problems!\n");
            else {
                if($this->debug)
                    printf("Server supports MLS(D/T)\n");
            }
        }            
        
        if($this->run_fuse) {
            //run FUSE
            array_push($argv,"-o");
            array_push($argv,sprintf("fsname='%s'@%s%s",$this->user,$this->host,$this->remotedir));
            array_push($argv,"-o");
            array_push($argv,sprintf("subtype=%s",$this->name));
            $argc+=4;
            if($this->debug)
                printf("Calling fuse_main with args: %s",print_r($argv,true));
            $this->fuse_main($argc, $argv);
        }
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
                $this->run_ftpfs=false;
                if($key!=$this->opt_keys["KEY_FUSE_HELP"])
                    $this->run_fuse=false; //-h doesn't invoke fuse_main, but -H does
                fprintf(STDERR, "%1\$s
Marco Schuster <marco@m-s-d.eu>

cURL FTP-backed FUSE virtual filesystem

Usage: %2\$s [options] mountpoint

Options:
    -o opt,[opt...]           mount options
    -h --help                 this help
    -H                        more help
    -V --version              print version info
    -d                        debug mode (debug %1\$s and libfuse)

Options specific to %1\$s:
    -o ftp_host=s             Hostname or IP of remote host, default 'localhost'
    -o ftp_user=s             Remote user name, default 'anonymous'
    -o ftp_password=s         Password of remote user, default 'user@example.com'
    -o ftp_url=s              Specify host/user/password with a ftp url; warning:
                              remotedir MUST be specified separately!
    -o cache_maxage=n         Maximum age of cached files in seconds, default 60
    -o pasv                   Use PASV FTP mode instead of active transfer mode
    -o controlport=n          Control port of FTP server, default 21
    -o remotedir=s            remote directory to use as base, default /
    -o ipv6                   use IPv6 if having an AAAA record for the server
    -o cachedir=s             directory for file cache, will be set readable only
                              to the user calling the script. Can be shared by multiple
                              %1\$s instances. If not specified, caching is disabled.
    -o debug_user             debug only %1\$s code, but not FUSE
    -o debug_curl             set CURLOPT_VERBOSE
    -o debug_raw              print the raw data recieved by the curl interfaces

", $this->name, $argv[0]);
                return 0;
                break;
            case $this->opt_keys["KEY_VERSION"]:
                $this->run_ftpfs=false;
                printf("%s %s\n", $this->name, $this->version);
                return 1;
                break;
            case $this->opt_keys["KEY_DEBUG"]:
                $this->debug=true;
                return 1;
                break;
            case $this->opt_keys["KEY_DEBUG_USER"]:
                $this->debug=true;
                return 0;
                break;
            case $this->opt_keys["KEY_DEBUG_CURL"]:
                $this->debug=true;
                $this->debug_curl=true;
                return 0;
                break;
            case $this->opt_keys["KEY_DEBUG_RAW"]:
                $this->debug_raw=true;
                return 0;
                break;
            case $this->opt_keys["KEY_USER"]:
                $this->user=substr($arg,strlen("ftp_user")+1);
                return 0;
                break;
            case $this->opt_keys["KEY_HOST"]:
                $this->host=substr($arg,strlen("ftp_host")+1);
                return 0;
                break;
            case $this->opt_keys["KEY_PASSWORD"]:
                $this->pass=substr($arg,strlen("ftp_password")+1);
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
            case $this->opt_keys["KEY_URL"]:
                $arg=substr($arg,8);
                printf("got url '%s'\n",$arg);
                //tokenize the url
                $url=parse_url($arg);
                if(strtolower($url["scheme"])!="ftp") {
                    printf("Error: not a FTP URL\n");
                    return -1;
                }
                if(isset($url["host"]))
                    $this->host=urldecode($url["host"]);
                if(isset($url["user"]))
                    $this->user=urldecode($url["user"]);
                if(isset($url["pass"]))
                    $this->pass=urldecode($url["pass"]);
                if(isset($url["path"])) {
                    $this->remotedir=urldecode($url["path"]);
                    if(substr($this->remotedir,0,1)!="/")
                        $this->remotedir="/".$this->remotedir;
                    if(substr($this->remotedir,-1,1)!="/")
                        $this->remotedir.="/";
                }
                return 0;
                break;
            default:
                return 1;
        }
    }
    
    //set the specifiec curl options, and reset the others to default
    public function curl_setopt($a) {
        $defaults=array(
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_BINARYTRANSFER=>true,
            CURLOPT_QUOTE=>array(),
            CURLOPT_NOBODY=>false,
            CURLOPT_CUSTOMREQUEST=>"",
            CURLOPT_HEADERFUNCTION=>NULL,
            CURLOPT_RESUME_FROM=>0,
            CURLOPT_INFILE=>NULL,
            CURLOPT_INFILESIZE=>0,
            CURLOPT_PUT=>false,
            CURLOPT_RANGE=>"",
            CURLOPT_VERBOSE=>$this->debug_curl
        );
        foreach($a as $opt=>$val)
            $defaults[$opt]=$val;
        return curl_setopt_array($this->curl,$defaults);
    }

    //callback for in-band communication
    //callback not anonymous because we need to transfer data out of this function using $this
    public function curl_inband_cb($res,$str) {
        if($this->debug_raw)
            printf("curl_inband_cb: state %d, got '%s', expecting %s as OK\n",$this->curl_inband_data["state"],$str,$this->curl_inband_data["expect_okcode"]);
        elseif($this->debug)
            printf("curl_inband_cb: state %d\n",$this->curl_inband_data["state"]);
        
        switch($this->curl_inband_data["state"]) {
            case 0: //didn't see a 215 from the SYST, wait for it
                if(substr($str,0,4)!="215 ") {
                    break;
                }
                $this->curl_inband_data["state"]=1;
            break;
            case 1: //have seen a 215 OK from SYST, waiting for 250 OK
                //250 OK but with continuation data (which we have to catch)
                if(substr($str,0,4)==$this->curl_inband_data["expect_okcode"]."-") {
                    $this->curl_inband_data["state"]=2;
                    break;
                } elseif(substr($str,0,4)==$this->curl_inband_data["expect_okcode"]." ") {
                    curl_setopt_array($res,array(CURLOPT_HEADERFUNCTION=>NULL));
                    $this->curl_inband_data["state"]=4;
                    break;
                } else {
                    //prevent further processing
                    curl_setopt_array($res,array(CURLOPT_HEADERFUNCTION=>NULL));
                    $this->curl_inband_data["state"]=FALSE;
                    $this->curl_inband_data["error"]=$str;
                    break;
                }
            break;
            case 2: //either the actual communication data or a "250 " saying "end of data"
                if(substr($str,0,4)==$this->curl_inband_data["expect_okcode"]." ") {
                    curl_setopt_array($res,array(CURLOPT_HEADERFUNCTION=>NULL));
                    $this->curl_inband_data["state"]=4;
                    break;
                }
                $this->curl_inband_data["data"][]=$str;
            break;
            
        }
        if($this->debug)
            printf("curl_inband_cb: leave, state now %d\n",$this->curl_inband_data["state"]);
        return strlen($str);
    }
    //run a in-band CURL command. On success, return 0; on failure, return -FUSE_EIO and the error in
    //$this->curl_inband_data["error"]
    //If the FTP error code does not match $expect_errorcode, then it will be printed out
    //Else -FUSE_EINVAL is returned
    //TODO: If the connection gets reset, the callback has no information about the state of the connection
    //      and so will see the "220 Ok login now" message as start message for the parsing. So, we hope
    //      that cURL doesn't run SYST on connects in the future and use its unique 215 to establish a clean
    //      state.
    public function curl_inband_cmd($cmd,$expect_errorcode="",$expect_okcode="250") {
        if($this->debug)
            printf("%s(cmd='%s', expect_ec=%s, expect_ok=%s) called\n",__FUNCTION__,$cmd,$expect_errorcode,$expect_okcode);

        $this->curl_inband_data=array("state"=>0,"data"=>array(),"expect_okcode"=>$expect_okcode);
        
        $ret=$this->curl_setopt(array(
            CURLOPT_URL=>$this->base_url,
            CURLOPT_QUOTE=>array("SYST",$cmd),
            CURLOPT_HEADERFUNCTION=>array($this,"curl_inband_cb"),
            CURLOPT_NOBODY=>true
        ));
        if($ret===FALSE) {
            printf("curl_inband_cmd('%s'): curl_setopt failed with '%s'\n",$cmd,curl_error($this->curl));
            return -FUSE_EIO;
        }
        
        $ret=curl_exec($this->curl);
        if($ret===FALSE && $this->curl_inband_data["state"]!==FALSE) {
            printf("curl_inband_cmd('%s'): unexpected error '%s'\n",$cmd,curl_error($this->curl));
            return -FUSE_EIO;
        } elseif($this->curl_inband_data["state"]===FALSE) {
            $ec=substr($this->curl_inband_data["error"],0,3);
            if($ec==$expect_errorcode) {
                if($this->debug)
                    printf("curl_inband_cmd('%s'): expected error '%s'\n",$cmd,$this->curl_inband_data["error"]);
                return -FUSE_EINVAL;
            } else {
                printf("curl_inband_cmd('%s'): unexpected error '%s'\n",$cmd,$this->curl_inband_data["error"]);
                return -FUSE_EIO;
            }
        }
        
        return 0;
    }

    //run a out-of-band CURL command. On success, return 0; on failure, return -FUSE_EIO
    public function curl_outband_cmd($url,$cmd) {
        if($this->debug)
            printf("%s(url='%s', cmd=%s) called\n",__FUNCTION__,$url,$cmd);

        $ret=$this->curl_setopt(array(
            CURLOPT_URL=>$url,
            CURLOPT_CUSTOMREQUEST=>$cmd
        ));
        if($ret===FALSE) {
            printf("curl_outband_cmd('%s'): curl_setopt failed with '%s'\n",$cmd,curl_error($this->curl));
            return -FUSE_EIO;
        }
        
        $ret=curl_exec($this->curl);
        if($ret===FALSE) {
            printf("curl_outband_cmd('%s'): curl_exec failed with '%s'\n",$cmd,curl_error($this->curl));
            return -FUSE_EIO;
        }
        
        return $ret;
    }

    //return an array with the features reported by the server
    public function curl_feat() {
        if($this->debug)
            printf("%s() called\n",__FUNCTION__);
        
        $ret=$this->curl_inband_cmd("FEAT","","211"); //FEAT returns 211 instead of 250
        if($ret<0)
            return -FUSE_EIO;
        
        $data=$this->curl_inband_data["data"];
        $ret=array();
        foreach($data as $f) {
            $f=explode(" ",strtolower(trim($f)),2);
            $feat=trim($f[0]);
            if(isset($f[1])) {
                $opt_a=explode(";",trim($f[1]));
                $opt=array();
                foreach($opt_a as $k=>$v) {
                    if(trim($v)=="") continue;
                    if(substr($v,-1,1)=="*") //currently activated
                        $opt[substr($v,0,-1)]=true;
                    else
                        $opt[$v]=false;
                }
            } else
                $opt="";
            $ret[$feat]=$opt;
        }
        return $ret;
    }

    //parse a line returned by MLS(D/T)
    public function curl_mls_parse($line) {
        $d = explode(";",$line);
        $fn=trim(array_pop($d)); //the last bit is the file name, which has no key
        
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
        
        $ret["filename"]=$fn;
        
        return $ret;
    }
    

    //MLST: get information about a specific file or directory (stat() equivalent)
    // See also: RFC 3659 @ http://www.ietf.org/rfc/rfc3659.txt
    public function curl_mlst($path) {
        if(substr($path,0,1)=="/")
            $path=substr($path,1);
        $abspath=$this->remotedir.$path;

        if($this->debug)
            printf("Requesting cURL MLST from base '%s' / path '%s' / abspath '%s'\n",$this->base_url,$path,$abspath);
        
        //Enable all MLST fields
        $estr="";
        foreach($this->curl_feat["mlst"] as $opt=>$active)
            $estr.=$opt.";";
        if($this->debug)
            printf("Enabling MLST options '%s'\n",$estr);
        $ret=$this->curl_inband_cmd("OPTS MLST $estr","",200);
        if($ret<0)
            return -FUSE_EIO;
        
        $ret=$this->curl_inband_cmd("MLST $abspath","550");
        
        if($ret<0) {
            if($ret==-FUSE_EINVAL) //EINVAL=matched expected ec, other=unexpected error
                return -FUSE_ENOENT; //MLST 550 = file not found / no access
            return $ret;
        } else {
            if($this->debug_raw)
                printf("Raw data: %s",print_r($this->curl_inband_data,true));
            $ret=$this->curl_mls_parse($this->curl_inband_data["data"][0]);
        }
        
        if($this->debug)
            printf("MLST result: '%s'\n",compact_pa($ret));
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

        if($this->debug)
            printf("Requesting cURL MLSD from base '%s' / path '%s' / abspath '%s'\n",$this->base_url,$path,$abspath);
        
        //Enable all MLST fields
        $estr="";
        foreach($this->curl_feat["mlst"] as $opt=>$active)
            $estr.=$opt.";";
        if($this->debug)
            printf("Enabling MLST options '%s'\n",$estr);
        $ret=$this->curl_inband_cmd("OPTS MLST $estr","",200);
        if($ret<0)
            return -FUSE_EIO;
        
        $ret=$this->curl_outband_cmd($abspath,"MLSD");
        if($ret<0)
            return $ret;
        
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

            $entry=$this->curl_mls_parse($v);
            
            $ret[$entry["filename"]]=$entry;
        }
        
        return $ret;
    }

    //get $len bytes of data from a file starting at $offset
    public function curl_get($path,$offset=0,$len=0) {
        if(substr($path,0,1)=="/")
            $path=substr($path,1);

        $abspath=$this->base_url.$path;
        
        if($this->debug)
            printf("Requesting cURL file from base '%s' / path '%s' / abspath '%s' / range %d-%d\n",$this->base_url,$path,$abspath,$begin,$end);

        $begin=$offset;
        $end=$begin+$len-1; //ranges are inclusive

        $ret=$this->curl_setopt(array(
            CURLOPT_URL=>$abspath,
            CURLOPT_RANGE=>"$begin-$end",
        ));
        if($ret===FALSE) {
            printf("curl_get('%s'): curl_setopt failed with '%s'\n",$path,curl_error($this->curl));
            return -FUSE_EIO;
        }
        
        $ret=curl_exec($this->curl);
        if($ret===FALSE) {
            printf("curl_get('%s'): curl_exec failed with '%s'\n",$path,curl_error($this->curl));
            return -FUSE_EIO;
        }        
        
        if(strlen($ret)!=$len) {
            printf("curl_get warning: for '%s': return length %d differs from specified length %d\n",$abspath,strlen($ret),$len);
        }
        
        return $ret;
    }

    //write $buf at $offset to $path
    public function curl_put($path,$offset=0,$buf="") {
        if(substr($path,0,1)=="/")
            $path=substr($path,1);
        
        $abspath=$this->base_url.$path;

        if($this->debug)
            printf("Requesting cURL PUT to base '%s' / path '%s' / abspath '%s' for %d bytes at offset %d\n",$this->base_url,$path,$abspath,strlen($buf),$offset);

        //write buffer to tempfile
        $tmp=tmpfile();
        if($tmp===false) {
            printf("tmpfile failed\n");
            return -FUSE_EINVAL;
        }
        fwrite($tmp,$buf);
        fseek($tmp,0);
        
        $ret=$this->curl_setopt(array(
            CURLOPT_URL=>$abspath,
            CURLOPT_RESUME_FROM=>$offset,
            CURLOPT_INFILE=>$tmp,
            CURLOPT_INFILESIZE=>$offset+strlen($buf),
            CURLOPT_PUT=>true
        ));

        if($ret===FALSE) {
            printf("curl_get('%s'): curl_setopt failed with '%s'\n",$path,curl_error($this->curl));
            return -FUSE_EIO;
        }

        $ret=curl_exec($this->curl);
        if($ret===FALSE) {
            printf("curl_get('%s'): curl_exec failed with '%s'\n",$path,curl_error($this->curl));
            return -FUSE_EIO;
        }        
        
        //this deletes the tmpfile, too
        fclose($tmp);
        
        return 0;
    }


    //delete a file
    public function curl_dele($path) {
        if($this->debug)
            printf("Requesting cURL DELE to base '%s' / path '%s'\n",$this->base_url,$path);
        
        if(substr($path,0,1)=="/")
            $path=substr($path,1);
        $abspath=$this->remotedir.$path;
        
        return $this->curl_inband_cmd("DELE $abspath");
    }
    
    //create a directory
    public function curl_mkdir($path) {
        if($this->debug)
            printf("Requesting cURL MKDIR to base '%s' / path '%s'\n",$this->base_url,$path);
        
        if(substr($path,0,1)=="/")
            $path=substr($path,1);
        $abspath=$this->remotedir.$path;
        
        return $this->curl_inband_cmd("MKD $abspath","257");
    }

    //delete a directory
    public function curl_rmdir($path) {
        if($this->debug)
            printf("Requesting cURL RMDIR to base '%s' / path '%s'\n",$this->base_url,$path);
        
        if(substr($path,0,1)=="/")
            $path=substr($path,1);
        $abspath=$this->remotedir.$path;
        
        return $this->curl_inband_cmd("RMD $abspath");
    }
    
    //FUSE: get attributes of a file
    public function getattr($path, &$st) {
        if($this->debug)
            printf("PHPFS: %s('%s') called\n", __FUNCTION__, $path);
        
        $data=$this->curl_mlst($path);
        if($data<0)
            return $data;
        
        $st['dev']     = 0;
        $st['ino']     = 0;
        $st['mode']    = 0;
        $st['nlink']   = 0;
        //These have been set at startup to avoid an extra syscall at each request
        $st['uid']     = $this->uid;
        $st['gid']     = $this->gid;
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
            //if the application honors blksize and needs to fetch the whole file,
            //it will use one read which is better than dozens of small reads
            $st['blksize']=$st['size'];
            $st['blocks']=(int)((int)($st['size']/512))+1;
        } elseif($data["type"]=="dir") {
            $st['mode']|=FUSE_S_IFDIR;
            if(isset($data["perm"]["e"])) //e in directories=cd works, which is mode +x
                $st['mode']|=0111;
            if(isset($data["perm"]["l"])) //l in directories=ls works, which is mode +r
                $st['mode']|=0444;
            if(isset($data["perm"]["p"])) //p in directories=can delete files, which is mode +w
                $st['mode']|=0222;
            $st['blksize']=1;
            $st['blocks']=1;
            $st['nlink']=1;
        } else {
            printf("getattr('%s'): neither file nor directory\n",$path);
            return -FUSE_EINVAL;
        }
        
        if($this->debug)
            printf("PHPFS: %s returning, st is %s\n",__FUNCTION__,compact_pa($st));
        
        return 0; 
    }
    
    public function readlink() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    
    //get the content of a directory
    public function getdir($path,&$ret) {
        if($this->debug)
            printf("PHPFS: %s('%s') called\n", __FUNCTION__,$path);
        
        //enforce directory as path
        if(substr($path,-1,1)!="/")
            $path.="/";
        
        //Check if the directory exists
        $dir=$this->curl_mlst($path);
        if($dir<0) {
            printf("getdir('%s'): target does not exist\n",$path);
            return $dir;
        }
        
        $files=$this->curl_mlsd($path);
        if($files<0) {
            printf("getdir('%s'): MLSD returned error %d\n",$path,$files);
            return $files;
        }
        
        if(sizeof($files)<2) { //must always be at least two elements big (parent+current dir)
            printf("getdir('%s'): MLSD returned less than 2 elements\n",$path);
            return -FUSE_EINVAL;
        }
        
        $ret=array();
        foreach($files as $fn=>$data) {
            if($this->debug)
                printf("getdir('%s'): Adding file '%s' to list\n",$path,$fn);
            if($data["type"]=="file")
                $ret[$fn]=array("type"=>FUSE_DT_REG);
            elseif($data["type"]=="dir" || $data["type"]=="cdir" || $data["type"]=="pdir")
                $ret[$fn]=array("type"=>FUSE_DT_DIR);
            else
                printf("Unknown type '%s' for file '%s' in path '%s'\n",$data["type"],$fn,$path);
        }
        
        if($this->debug)
            printf("getdir('%s'): returning %d elements\n",$path,sizeof($ret));
        
        return 0;
    }
    
    //create a file (other nodes not supported)
    public function mknod($path,$mode,$dev) {
        if($this->debug)
            printf("PHPFS: %s(path='%s', mode='%o', dev='%d') called\n", __FUNCTION__,$path,$mode,$dev);
        
        //check if the given endpoint already exists
        $stat=$this->curl_mlst($path);
        if($stat!=-FUSE_ENOENT) {
            printf("mknod('%s'): target exists\n",$path);
            return -FUSE_EEXISTS;
        }
        
        $ret=$this->curl_put($path,0,"");
        if($ret<0)
            return $ret;
        
        //TODO: chmod
        
        //check if the given endpoint exists now
        $stat=$this->curl_mlst($path);
        if($stat==-FUSE_ENOENT) {
            printf("mknod('%s'): could not create target\n",$path);
            return -FUSE_EFAULT;
        }
        
        if($this->debug)
            printf("mknod('%s'): return 0\n",$path);
        return 0;
    }
    
    public function mkdir($path,$mode) {
        printf("PHPFS: %s(path='%s', mode='%o') called\n", __FUNCTION__,$path,$mode);

        //check if the given endpoint already exists
        $stat=$this->curl_mlst($path);
        if($stat!=-FUSE_ENOENT) {
            printf("mkdir('%s'): target exists\n",$path);
            return -FUSE_EEXISTS;
        }
        
        $ret=$this->curl_mkdir($path);
        if($ret<0)
            return $ret;
        //TODO: chmod
        
        //check if the given endpoint exists now
        $stat=$this->curl_mlst($path);
        if($stat==-FUSE_ENOENT) {
            printf("mkdir('%s'): could not create target\n",$path);
            return -FUSE_EFAULT;
        }
        
        if($this->debug)
            printf("mkdir('%s'): return 0\n",$path);
        return 0;
    }

    //remove a file
    public function unlink($path) {
        if($this->debug)
            printf("PHPFS: %s(path='%s') called\n", __FUNCTION__,$path);
        
        //check if the file exists
        $stat=$this->curl_mlst($path);
        if($stat<0)
            return $stat;
        
        if(!isset($stat["perm"]["d"])) {
            printf("unlink('%s'): DELE permission not set\n",$path);
            return -FUSE_EACCES;
        }

        //delete the old file
        $ret=$this->curl_dele($path);
        if($ret<0)
            return $ret;

        //check if the file doesn't exist
        $stat=$this->curl_mlst($path);
        if($stat<0 && $stat!==-FUSE_ENOENT)
            return $stat;
        elseif($stat===-FUSE_ENOENT) {
            //Do nothing, all ok
        } else {
            printf("unlink('%s'): file still exists after DELE\n",$path);
            return -FUSE_EIO;
        }
        
        if($this->debug)
            printf("unlink('%s'): return 0\n",$path);
        return 0;
    }

    //remove a directory
    public function rmdir($path) {
        printf("PHPFS: %s(path='%s') called\n", __FUNCTION__);

        //check if the directory exists
        $stat=$this->curl_mlst($path);
        if($stat<0)
            return $stat;
        
        if(!isset($stat["perm"]["d"])) {
            printf("rmdir('%s'): DELE permission not set\n",$path);
            return -FUSE_EACCES;
        }
        
        if($stat["type"]!="dir") {
            printf("rmdir('%s'): not a directory\n",$path);
        }

        //delete the directory
        $ret=$this->curl_rmdir($path);
        if($ret<0)
            return $ret;

        //check if the directory doesn't exist
        $stat=$this->curl_mlst($path);
        if($stat<0 && $stat!==-FUSE_ENOENT)
            return $stat;
        elseif($stat===-FUSE_ENOENT) {
            //Do nothing, all ok
        } else {
            printf("rmdir('%s'): directory still exists after RMDIR\n",$path);
            return -FUSE_EIO;
        }
        
        if($this->debug)
            printf("rmdir('%s'): return 0\n",$path);
        return 0;
    }
    
    //create a symlink
    //Symlinks are not supported by FTP (maybe some extension, but we can't read symlink info in MLS(D/T) either)
    public function symlink($from,$to) {
        if($this->debug)
            printf("PHPFS: %s(from='%s', to='%s') called\n", __FUNCTION__,$from,$to);
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
    
    //truncate (or expand, when $length>filesize) a file
    public function truncate($path,$length) {
        if($this->debug)
            printf("PHPFS: %s(path='%s', length=%d) called\n", __FUNCTION__,$path,$length);
        
        //truncate to specific length is not supported
        //todo: implement this using get, substr
        if($length > 0) {
            printf("truncate('%s',%d): length adjustments not supported\n",$path,$length);
        }
        
        //check if the file exists
        $stat=$this->curl_mlst($path);
        if($stat<0)
            return $stat;
        
        //delete the old file
        $ret=$this->curl_dele($path);
        if($ret<0)
            return $ret;

        //check if the file doesn't exist
        $stat=$this->curl_mlst($path);
        if($stat<0 && $stat!==-FUSE_ENOENT)
            return $stat;
        elseif($stat===-FUSE_ENOENT) {
            //Do nothing, all ok
        } else {
            printf("truncate('%s',%d): file still exists after DELE\n",$path,$length);
            return -FUSE_EIO;
        }
        
        //put an empty file
        $ret=$this->curl_put($path,0,"");
        if($ret<0)
            return $ret;
        
        //check if the file exists
        $stat=$this->curl_mlst($path);
        if($stat<0)
            return $stat;
        
        if($this->debug)
            printf("truncate('%s',%d): return 0\n",$path,$length);
        return 0;
    }
    public function utime() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    
    //open a file
    public function open($path, $mode) {
        if($this->debug)
            printf("PHPFS: %s(path='%s', mode=0%o) called\n", __FUNCTION__,$path,$mode);
        
        //First, filter out all the access modes we don't support
        if(($mode & FUSE_O_CREAT)==FUSE_O_CREAT) {
            printf("open('%s'): invalid mode CREAT\n",$path);
            return -FUSE_EINVAL;
        }
        if(($mode & FUSE_O_EXCL)==FUSE_O_EXCL) {
            printf("open('%s'): invalid mode EXCL\n",$path);
            return -FUSE_EINVAL;
        }
        if(($mode & FUSE_O_NOCTTY)==FUSE_O_NOCTTY) {
            printf("open('%s'): invalid mode NOCTTY\n",$path);
            return -FUSE_EINVAL;
        }
        if(($mode & FUSE_O_TRUNC)==FUSE_O_TRUNC) {
            printf("open('%s'): invalid mode TRUNC\n",$path);
            return -FUSE_EINVAL;
        }
        if(($mode & FUSE_O_APPEND)==FUSE_O_APPEND) {
            //Do nothing. The OS will set $offset in the write calls, we do not have to track it
        }
        if(($mode & FUSE_O_NONBLOCK)==FUSE_O_NONBLOCK) {
            //Do nothing. The OS will (or, at least it should) take care of this for us...
//            printf("open('%s'): invalid mode NONBLOCK\n",$path);
//            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_DSYNC") && ($mode & FUSE_O_DSYNC)==FUSE_O_DSYNC) {
            printf("open('%s'): invalid mode DSYNC\n",$path);
            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_FASYNC") && ($mode & FUSE_O_FASYNC)==FUSE_O_FASYNC) {
            printf("open('%s'): invalid mode FASYNC\n",$path);
            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_DIRECT") && ($mode & FUSE_O_DIRECT)==FUSE_O_DIRECT) {
            printf("open('%s'): invalid mode DIRECT\n",$path);
            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_LARGEFILE") && ($mode & FUSE_O_LARGEFILE)==FUSE_O_LARGEFILE) {
            //Do nothing, O_LARGEFILE is not supported but the OS supplies it anyway
//            printf("open('%s'): invalid mode LARGEFILE\n",$path);
//            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_DIRECTORY") && ($mode & FUSE_O_DIRECTORY)==FUSE_O_DIRECTORY) {
            printf("open('%s'): invalid mode DIRECTORY\n",$path);
            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_NOFOLLOW") && ($mode & FUSE_O_NOFOLLOW)==FUSE_O_NOFOLLOW) {
            printf("open('%s'): invalid mode NOFOLLOW\n",$path);
            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_NOATIME") && ($mode & FUSE_O_NOATIME)==FUSE_O_NOATIME) {
            printf("open('%s'): invalid mode NOATIME\n",$path);
            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_CLOEXEC") && ($mode & FUSE_O_CLOEXEC)==FUSE_O_CLOEXEC) {
            printf("open('%s'): invalid mode CLOEXEC\n",$path);
            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_SYNC") && ($mode & FUSE_O_SYNC)==FUSE_O_SYNC) {
            printf("open('%s'): invalid mode SYNC\n",$path);
            return -FUSE_EINVAL;
        }
        if(defined("FUSE_O_PATH") && ($mode & FUSE_O_PATH)==FUSE_O_PATH) {
            printf("open('%s'): invalid mode PATH\n",$path);
            return -FUSE_EINVAL;
        }

        //Check if the file actually exists
        $stat=$this->curl_mlst($path);
        if($stat<0 && $stat!==FUSE_ENOENT)
            return $stat;

        if($stat===FUSE_ENOENT) {
            //separate this case: it may be that one will try open with E_CREAT, which is not passed to us by FUSE (for now)
            //todo: check what fuse actually does
            printf("open('%s'): file does not exist",$path);
            return $stat;
        }
        
        $want_read=false;
        $want_write=false;
        
        //see man 2 open, section "Notes" for an explanation of this code...
        $fm=($mode & FUSE_O_ACCMODE);
        
        switch($fm) {
            case FUSE_O_RDONLY:
                $want_read=true;
                break;
            case FUSE_O_WRONLY:
                $want_write=true;
                break;
            case FUSE_O_RDWR:
                $want_read=true;
                $want_write=true;
                break;
            default:
                printf("open('%s'): invalid file access mode %d\n",$path,$fm);
                return -FUSE_EINVAL;
                break;
        }
        
        if($this->debug)
            printf("open('%s'): read '%d', write '%d'\n",$path,$want_read,$want_write);
        
        if($want_read && !isset($stat["perm"]["r"])) {
            printf("open('%s'): READ requested, but not allowed\n",$path);
            return -FUSE_EACCES;
        }
        if($want_write && !isset($stat["perm"]["w"])) {
            printf("open('%s'): WRITE requested, but not allowed\n",$path);
            return -FUSE_EACCES;
        }
        
        $id=$this->next_handle_id++;
        $handle=array("read"=>$want_read,"write"=>$want_write,"path"=>$path,"state"=>"open","id"=>$id,"stat"=>$stat);
        $this->handles[$id]=$handle;
        
        if($this->debug)
            printf("open('%s'): returning handle %d\n",$path,$id);
        return $id;
    }
    
    //read up to $buf_len bytes from $path opened with $handle
    public function read($path,$handle,$offset,$buf_len,&$buf) {
        if($this->debug)
            printf("PHPFS: %s(path='%s', handle=%d, offset=%d, buf_len=%d) called\n", __FUNCTION__,$path,$handle,$offset,$buf_len);
        
        //check if the handle is valid
        if(!isset($this->handles[$handle])) {
            printf("read('%s',%d): invalid handle\n",$path,$handle);
            return -FUSE_EBADF;
        }
        
        //check if the handle is a read-handle
        $handle_data=$this->handles[$handle];
        if($handle_data["read"]===false) {
            printf("read('%s',%d): no-read handle\n",$path,$handle);
            return -FUSE_EBADF;
        }

        //check if $path is the same as in the handle
        if($path!=$handle_data["path"]) {
            printf("read('%s',%d): path not equal to handle path '%s', restoring original\n",$path,$handle,$handle_data["path"]);
            $path=$handle_data["path"];
        }
        
        $begin=$offset;
        $end=$begin+$buf_len;
        $ask_len=$buf_len;
        if($end>$handle_data["stat"]["size"]) {
            if($this->debug)
                printf("read('%s',%d): truncating end from %d to %d for offset %d, buflen %d\n",$path,$handle,$end,$handle_data["stat"]["size"],$begin,$buf_len);
            $end=$handle_data["stat"]["size"];
            $ask_len=$end-$begin;
        }
        
        $ret=$this->curl_get($path,$begin,$ask_len);
        
        if($ret===-FUSE_EINVAL) {
            printf("read('%s',%d): curl_get reported error\n",$path,$handle);
            return $ret;
        }
        
        if(strlen($ret)!=$ask_len) {
            printf("read('%s',%d): curl_get returned %d bytes while asked for %d bytes\n",$path,$handle,strlen($ret),$ask_len);
        }
        $buf=$ret;
        
        if($this->debug_raw)
            printf("read('%s',%d): returning '%s' (%d bytes)\n",$path,$handle,$buf,strlen($buf));
        elseif($this->debug)
            printf("read('%s',%d): returning %d bytes\n",$path,$handle,strlen($buf));
        
        return strlen($buf);
    }

    //write $buf to $path (opened with handle $handle) at $offset
    public function write($path,$handle,$offset,$buf) {
        if($this->debug)
            printf("PHPFS: %s(path='%s', handle=%d, offset=%d, len(buf)=%d) called\n", __FUNCTION__,$path,$handle,$offset,strlen($buf));
        if($this->debug_raw)
            printf("write('%s',%d): raw input buffer: '%s'\n",$buf);

        //check if the handle is valid
        if(!isset($this->handles[$handle])) {
            printf("write('%s',%d): invalid handle\n",$path,$handle);
            return -FUSE_EBADF;
        }
        
        //check if the handle is a read-handle
        $handle_data=$this->handles[$handle];
        if($handle_data["write"]===false) {
            printf("write('%s',%d): no-write handle\n",$path,$handle);
            return -FUSE_EBADF;
        }

        //check if $path is the same as in the handle
        if($path!=$handle_data["path"]) {
            printf("write('%s',%d): path not equal to handle path '%s', restoring original\n",$path,$handle,$handle_data["path"]);
            $path=$handle_data["path"];
        }
        
        //check if we're having an offset that places us in the middle of the file
        $stat=$this->curl_mlst($path);
        if($stat<0)
            return $stat;
        if($offset<$stat["size"]) {
            printf("write('%s',%d): requested offset %d is smaller than file size %d\n",$path,$handle,$offset,$stat["size"]);
            
            //backup the old data
            $old=$this->curl_get($path,0,$stat["size"]);
            if($old<0)
                return $old;
            $pre=substr($old,0,$offset);
            $post=substr($old,$offset+strlen($buf));
            $new=$pre.$buf.$post;
            //do separate curl_put, as changing $buf would mess up the return strlen($buf) below!
            $ret=$this->curl_put($path,0,$new);
        } else {
            $ret=$this->curl_put($path,$offset,$buf);
        }
        if($ret<0)
            return $ret;
        
        if($this->debug)
            printf("write('%s',%d): return %d\n",$path,$handle,strlen($buf));
        return strlen($buf);
    }
    public function statfs() {
        printf("PHPFS: %s called\n", __FUNCTION__);
        return -FUSE_ENOSYS;
    }
    
    //flush is not needed, we PUT directly on write calls
    public function flush($path,$handle) {
        if($this->debug)
            printf("PHPFS: %s(path='%s', handle=%d) called\n", __FUNCTION__,$path,$handle);

        //check if the handle is valid
        if(!isset($this->handles[$handle])) {
            printf("flush('%s',%d): invalid handle\n",$path,$handle);
            return -FUSE_EBADF;
        }
        
        if($this->debug)
            printf("flush('%s',%d): return 0\n",$path,$handle);
        return 0;
    }
    
    //release $handle for $path
    public function release($path,$handle) {
        if($this->debug)
            printf("PHPFS: %s(path='%s', handle=%d) called\n", __FUNCTION__,$path,$handle);
        
        //check if the handle is valid
        if(!isset($this->handles[$handle])) {
            printf("release('%s',%d): tried to release invalid handle\n",$path,$handle);
            return -FUSE_EBADF;
        }
        
        unset($this->handles[$handle]);
        
        if($this->debug)
            printf("release('%s',%d): return 0\n",$path,$handle);
        return 0;
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
    if(!is_array($a))
        return $a;
    foreach($a as $k=>$v) {
        if(is_array($v))
            $v=compact_pa($v);
        $buf.="'$k'=>'$v',";
    }
    return substr($buf,0,-1);
}
$fuse = new PHPFTPFS();
$fuse->main($argc, $argv);
