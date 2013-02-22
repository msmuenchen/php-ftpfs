#!/usr/bin/env php
<?php

//execute a command and check for return values
function setup_exec($cmd) {
  $ret=0;
  $out=array();
  $cmd.=" 2>&1";
  printf("Launching %s\n",$cmd);
  exec($cmd,$out,$ret);
  if($ret!=0) {
    echo implode("\n",$out);
    printf("'%s' failed. Aborting install.\n",$cmd);
    exit(1);
  }
}

//default config
$conf=array("inst-dir"=>"/usr/local/","bin-dir"=>"/opt/phpftpfs/","make-cores"=>5);

//init getopt
$shortopts="hj::";
$longopts=array("inst-dir::","bin-dir::","help");
$options=getopt($shortopts,$longopts);

//help
if(isset($options["h"]) || isset($options["help"])) {
  fprintf(STDERR, "%1\$s
Marco Schuster <marco@m-s-d.eu>

cURL FTP-backed FUSE virtual filesystem - Installer

Usage: %2\$s [options]

Options:
    -h --help                 this help
    --inst-dir=s              the directory under whose bin/ subdir the ftpfs binary
                              will be installed. Defaults to %3\$s
    --bin-dir=s               the directory where the dependencies of ftpfs will
                              be installed. Defaults to %4\$s
    -j=n                      number of cores to use for make. Defaults to %5\$s

","php-ftpfs setup",$argv[0],$conf["inst-dir"],$conf["bin-dir"],$conf["make-cores"]);
  exit(1);
}

//update config with options
if(isset($options["inst-dir"]) && !is_array($options["inst-dir"]))
  $conf["inst-dir"]=$options["inst-dir"];
if(isset($options["bin-dir"]) && !is_array($options["bin-dir"]))
  $conf["bin-dir"]=$options["bin-dir"];
if(isset($options["j"]) && is_numeric($options["j"]))
  $conf["make-cores"]=$options["j"];

//check if these are paths, convert them to paths if not
$scriptloc=realpath(dirname(__FILE__))."/";
if(substr($conf["inst-dir"],0,1)!="/") //relative path
  $conf["inst-dir"]=$scriptloc.$conf["inst-dir"];
if(substr($conf["bin-dir"],0,1)!="/") //relative path
  $conf["bin-dir"]=$scriptloc.$conf["bin-dir"];
if(substr($conf["inst-dir"],-1,1)!="/")
  $conf["inst-dir"].="/";
if(substr($conf["bin-dir"],-1,1)!="/")
  $conf["bin-dir"].="/";

printf("Installing the binary to %s and the dependencies under %s. Continue ([y]/n)? ",$conf["inst-dir"]."bin/ftpfs",$conf["bin-dir"]);
$in=strtolower(fgetc(STDIN));
if($in!="y" && $in!="")
  exit(1);

//check if we have a full snapshot or if we have to pull stuff with git
if(!is_file($scriptloc."php-fuse/README") || !is_file($scriptloc."php-src/README.md")) {
  printf("Needing to fetch stuff from git, this may take a while.\n");
  setup_exec("cd $scriptloc && git submodule init");
  setup_exec("cd $scriptloc && git submodule update");
}

//Remove old installation
if(is_file($conf["inst-dir"]."bin/ftpfs")) {
  printf("Removing old binary\n");
  setup_exec("rm ".escapeshellarg($conf["inst-dir"]."bin/ftpfs"));
}
if(is_dir($conf["bin-dir"])) {
  printf("Removing old dependency dir\n");
  setup_exec("rm -rf ".escapeshellarg($conf["bin-dir"]));
}

printf("Compiling PHP\n");
if(is_file($scriptloc."php-src/Makefile"))
  setup_exec("cd ${scriptloc}php-src && make distclean");
setup_exec("cd ${scriptloc}php-src && ./buildconf --force");
setup_exec("cd ${scriptloc}php-src && ./configure --disable-all --enable-cli --disable-cgi --with-curl --enable-debug --enable-posix --enable-filter --prefix=".escapeshellarg($conf["bin-dir"]));
setup_exec("cd ${scriptloc}php-src && make clean");
setup_exec("cd ${scriptloc}php-src && make -j ".$conf["make-cores"]);
setup_exec("cd ${scriptloc}php-src && make install");

printf("Compiling php-fuse\n");
if(is_file($scriptloc."php-fuse/Makefile"))
  setup_exec("cd ${scriptloc}php-fuse && make distclean");
setup_exec("cd ${scriptloc}php-fuse && ".str_replace(" ","\\ ",$conf["bin-dir"])."bin/phpize --clean");
setup_exec("cd ${scriptloc}php-fuse && ".str_replace(" ","\\ ",$conf["bin-dir"])."bin/phpize");
setup_exec("cd ${scriptloc}php-fuse && ./configure --with-php-config=".escapeshellarg($conf["bin-dir"]."bin/php-config"));
setup_exec("cd ${scriptloc}php-fuse && make clean");
setup_exec("cd ${scriptloc}php-fuse && make -j ".$conf["make-cores"]);
setup_exec("cd ${scriptloc}php-fuse && make install");

printf("Installing ftpfs\n");
setup_exec("cp ${scriptloc}ftpfs/ftpfs.php ".escapeshellarg($conf["bin-dir"]."bin/ftpfs.php"));
setup_exec("chmod a+x ".escapeshellarg($conf["bin-dir"]."bin/ftpfs.php"));
setup_exec("ln -s ".escapeshellarg($conf["bin-dir"]."bin/ftpfs.php")." ".escapeshellarg($conf["inst-dir"]."bin/ftpfs"));

$buf=file($conf["bin-dir"]."bin/ftpfs.php");
if(substr($buf[0],0,2)!="#!") {
  printf("Setting shebang line of ftpfs binary\n");
  $fp=fopen($conf["bin-dir"]."bin/ftpfs.php","w");
  fwrite($fp,"#!".$conf["bin-dir"]."bin/php\n");
  foreach($buf as $line)
    fwrite($fp,$line);
  fclose($fp);
}
printf("Installation done.\n");
