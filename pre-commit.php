#!/usr/bin/env php
<?php

//pre-commit checks

//execute a command and check for return values
function setup_exec($cmd, $return_out = false) {
    $ret = 0;
    $out = array();
    $cmd .= " 2>&1";
    printf("Launching %s\n", $cmd);
    exec($cmd, $out, $ret);
    if ($ret != 0) {
        echo "-----\n" . implode("\n", $out) . "\n-----\n";
        printf("'%s' failed. Aborting hook.\n", $cmd);
        throw new Exception("");
    }
    if ($return_out)
        return $out;
}

//always use the same basedir
$scriptloc = realpath(dirname(__FILE__)) . "/";

//backup uncommitted stuff
setup_exec("cd " . escapeshellarg($scriptloc) . " && git stash --keep-index -u");

//format and check code
$exit = 0;
try {
    $files = array();
    $ret   = setup_exec("find " . escapeshellarg($scriptloc) . " -maxdepth 1 -type f -name \"*.php\"", true);
    $files = array_merge($files, $ret);
    $ret   = setup_exec("find " . escapeshellarg($scriptloc . "ftpfs/") . " -maxdepth 1 -type f -name \"*.php\"", true);
    $files = array_merge($files, $ret);
    foreach ($files as $file)
        setup_exec("cd " . escapeshellarg($scriptloc) . " && /usr/bin/env php -l " . escapeshellarg($file));
}
catch (Exception $e) { //make sure that git stash pop is run!
    $exit = 1;
}

//restore the worktree
setup_exec("cd " . escapeshellarg($scriptloc) . " && git stash pop -u");

exit($exit);