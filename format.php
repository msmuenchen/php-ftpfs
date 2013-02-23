#!/usr/bin/env php
<?php
//Code formatter using phpformatter.com

//execute a command and check for return values
function setup_exec($cmd, $return_out = false) {
    $ret = 0;
    $out = array();
    $cmd .= " 2>&1";
    printf("Launching %s\n", $cmd);
    exec($cmd, $out, $ret);
    if ($ret != 0) {
        echo implode("\n", $out);
        printf("'%s' failed. Aborting install.\n", $cmd);
        exit(1);
    }
    if ($return_out)
        return $out;
}

//sanity check
if (!isset($argv[1])) {
    printf("No file given\n");
    exit(1);
} elseif (!is_file($argv[1])) {
    printf("'%s' is not a valid file\n", $argv[1]);
    exit(1);
}

printf("Formatting and validating %s\n", $argv[1]);

//get the code
$content = file_get_contents($argv[1]);
if ($content === false) {
    printf("Could not access '%s'\n", $argv[1]);
    exit(1);
}

//assemble the request
$url     = "http://beta.phpformatter.com/Output/";
$fields  = array(
    "align_assignments" => "on",
    "indent_number" => 4,
    "first_indent_number" => 0,
    "indent_char" => " ",
    "indent_style" => "K&R",
    "code" => $content,
    "rewrite_short_tag" => "on"
);
$poststr = "";
foreach ($fields as $k => $v) {
    $v          = urlencode($v);
    $fields[$k] = $v;
    $poststr .= sprintf("%s=%s&", $k, $v);
}
$poststr = substr($poststr, 0, -1);

//init curl
$curl = curl_init($url);
if ($curl === false) {
    printf("Could not init cURL: %s\n", curl_error($curl));
    exit(1);
}

//set curl opts
$ret = curl_setopt_array($curl, array(
    CURLOPT_POST => sizeof($fields),
    CURLOPT_POSTFIELDS => $poststr,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_REFERER => "http://beta.phpformatter.com/"
));
if ($ret === false) {
    printf("Could not set cURL options: %s\n", curl_error($curl));
    exit(1);
}

//do the request
$ret = curl_exec($curl);
curl_close($curl);
if ($ret === false) {
    printf("Could not run cURL request: %s\n", curl_error($curl));
    exit(1);
}

//Fix broken escape of phpformatter.com API
$ret = preg_replace("@([^\\\\])'@isU", '\\1"', $ret);
$ret = str_replace("\\'", "'", $ret);

//try to decode the response
$response = json_decode($ret);
if ($response === NULL || $response === FALSE) {
    printf("json_decode failed on the response\n");
    exit(1);
}
if ($response->error != 0) {
    printf("phpformatter.com reported an error in line %d: %s\n", $response->line, $response->text);
    exit(1);
}

//write back to file
$fp = fopen($argv[1], "w");
fwrite($fp, $response->plainoutput);
fclose($fp);
