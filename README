# php-ftpfs
php-ftpfs is a wrapper bringing together php, libfuse and curl to provide
you with a FTP-served filesystem.

## Requirements
* 64-bit system - a 32-bit system WILL have issues with files larger than
  0x7FFFFFFF or 2.1 GB.
* FTP server MUST support the MLST/MLSD commands according to RFC3659 and
  the underlying file system MUST supply at least the file mtime; atime and
  ctime are optional but recommended. FEAT MUST show MLST!
* halfway current Linux kernel, libfuse and libcurl. In theory, any system
  supported by libfuse can be used, but it has not been tested.
* working build system including autoconf, automake and friends

## Install
### From source
Get the bleeding-edge version of php from
https://github.com/php/php-src/ and integrate the patchset from
https://github.com/msmuenchen/php-src/, branch msm_changes (or use that repo
by git clone, then git checkout msm_changes). In the future this won't be
needed, but the workarounds WILL massively degrade performance. You'll be
warned if this is the case.

Then, install libfuse-dev and the libcurl-dev packages using your package
manager, as well as the PHP build deps (I won't get into detail here, see
the PHP wiki).

You'll at least need -enable-cli  --with-curl --enable-posix
--enable-filter. It might not hurt to --enable-debug, and if you have an
existing PHP setup, it is recommended to use --program-suffix=56 to create
unique files (however, this may still mess up your include directory!).

Then, after you ran make install, check out and install the php-fuse source from
https://github.com/msmuenchen/php-fuse. You'll most likely need to specify
--with-php-config=php-config56 (php-fuse itself should support php5.3, but
php-ftpfs requires patching of the curl interface!).

### From binary (only amd64)
This will be published when it's ready.

## Usage
Basic: php ftpfs.php /path/to/mount -o ftp_url="ftp://ftp.example.com/"

Help: php ftpfs.php -h (or -H to also include libfuse help)

## Runtime options
The basic requirement is a mountpoint and FTP server information. You can
supply either a full-featured FTP url with -o
ftp_url="ftp://user:pass@host:port/remotedir" or supply the bits separately
with -o ftp_user, -o ftp_pass, -o ftp_host, -o ftp_port and -o remotedir. Values
separately passed do not need any encoding, but the FTP url must use
urlencoded values. If they're not, you'll notice.

Values can be omitted, in which case defaults are used, so the only real
requirement is ftp_host. 

-o big_writes is DEFINITELY recommended. Without it, libfuse will limit
writes to 4K. You can imagine the performance hit.

## Caveats
On 32-bit systems, you WILL encounter problems with files larger than 2GB,
because PHPs int is 31-bit plus sign.

php-ftpfs is not thread safe. Even if php was built with
--enable-maintainer-zts, php-ftpfs will force libfuse to run in
single-thread mode. Do not supply -m to php-ftpfs. The most likely result
you get by doing this is data loss.

Do not include the FUSE extension in any other SAPI than CLI or maybe embed,
if that is still maintained.

Always do backups using a regular ftp software. I recommend lftp for the
job, as it can do mirroring. While there is quite robust error handling
present in php-ftpfs, FTP itself is not concurrency-aware and can not do
locking!

Even with -o big_writes and -o max_read and -o max_write set to 10000000,
the maximum block size is something around 130K. The reasons for this
erratic behaviour of libfuse are unknown. If you want better performance,
activate the cache (which isn't done yet :O).

## Debug
Some errors can be spotted by lauching php-ftpfs with -f, as they are
force-written to the screen even without the debug options. If nothing can
be seen there:

php-ftpfs supplies you with a lot of debug options. When tracing down a bug,
generate a logfile using -o debug -o debug_curl and if the bug doesn't have
anything to do with big file read/write, also include -o debug_raw. Note
that debug messages are mixed to STDOUT and STDERR, catch them all with
appending > logfile 2>&1.

Please replace your password and your username, as they WILL appear in the
logs! There is NO way for php-ftpfs to filter them out of the logs!

## Contribute
File a pull request against the "msmuenchen" forks on GitHub. The original
php-fuse from gree is no longer maintained!

## License
This software is licensed under the PHP license, see LICENSE file for details.

I am not sure about the legality of the names "php-fuse" and "php-ftpfs".

## Donations
Liked php-ftpfs? Did it save your day? Tip me in BTC at
<1C7aCo3V8yc4V53bm5q29pn5MJiRznFZCw>.

## Contact
Mail to marco@m-s-d.eu for questions.
