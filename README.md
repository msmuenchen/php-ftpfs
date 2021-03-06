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
* all build dependencies of bare PHP, additionally needed are libfuse-dev and
  libcurl-dev
* Non-snapshot or non-release builds will require git.
* Around 100MB of free disk space for the build, 20-30MB for the install

## Install
Either clone the repository from `https://github.com/msmuenchen/php-ftpfs.git`
or grab a snapshot and unpack it. Now change into the unpacked directory.

Ensure you have every needed package. In Debian (maybe also Ubuntu), these will be:
* git
* build-essential
* autoconf and automake
* bison
* curl libcurl4-gnutls-dev
* fuse libfuse-dev

Your distribution might use other or at least similar names. Don't worry if you
accidentally miss a dependency, the setup script will first make sure everything
can build before actually installing php-ftpfs.

Then, run `./setup.php`. This will install php-ftpfs binaries in `/opt/phpftpfs/`
and the binary itself in `/usr/local/bin/ftpfs`. If you for some reason need to
use a different location, use `--bin-dir` to provide a directory with a `bin/`
subdirectory, where the ftpfs binary will be installed, and `--inst-dir` to
provide a directory for the binaries.

## Uninstall
Remove `$bindir/bin/ftpfs` and `$instdir`.

## Usage
Basic: `ftpfs /path/to/mount -o ftp_url="ftp://ftp.example.com/"`

Help: `ftpfs -h` (or `-H` to also include libfuse help)

## Runtime options
The basic requirement is a mountpoint and FTP server information. You can
supply either a full-featured FTP url with `-o
ftp_url="ftp://user:pass@host:port/remotedir"` or supply the bits separately
with `-o ftp_user`, `-o ftp_pass`, `-o ftp_host`, `-o ftp_port` and `-o remotedir`. Values
separately passed do not need any encoding, but the FTP url must use
urlencoded values. If they're not, you'll notice.

Values can be omitted, in which case defaults are used, so the only real
requirement is ftp_host. 

## Caching
### Metadata
Metadata is cached for `getattr()` / `getdir()` by default for 60 seconds. To
disable, use `-o cache_maxage=0`; I recommend setting it at least to 5 seconds
even on high-variable sites and something around 1800-3600 if you're the only
user on the site (like when you're on a web server).

### File contents
If you specified `-o cache_dir=/path/to/dir` (relative paths UNTESTED), then
php-ftpfs will create a subdirectory for each connection using this cache dir.
It is suggested that you only use one cache_dir for all your ftpfs mounts.

The files are named by their pathname, followed by the mtime and the file size,
separated with the underscore symbol.

Files ending in `-dirty` are files for which a handle has been opened and stuff
has been written to it, but flush() or release haven't been called yet.

** !!! NEVER EVER DELETE ANYTHING IN THE CACHE DIRECTORY WHEN PHP-FTPFS IS RUNNING !!! **

## Caveats
On 32-bit systems, you WILL encounter problems with files larger than 2GB,
because PHPs int is 31-bit plus sign.

php-ftpfs is not thread safe. Even if php was built with
`--enable-maintainer-zts`, php-ftpfs will force libfuse to run in
single-thread mode. Do not supply `-m` to php-ftpfs. The most likely result
you get by doing this is data loss.

Do not include the FUSE extension in any other SAPI than CLI or maybe embed,
if that is still maintained.

Always do backups using a regular ftp software. I recommend lftp for the
job, as it can do mirroring. While there is quite robust error handling
present in php-ftpfs, FTP itself is not concurrency-aware and can not do
locking!

Even with `-o big_writes` and `-o max_read` and `-o max_write` set to 10000000,
the maximum block size is something around 130K. The reasons for this
erratic behaviour of libfuse are unknown. If you want better performance,
activate the cache.

## Debug
Some errors can be spotted by lauching php-ftpfs with `-f`, as they are
force-written to the screen even without the debug options. If nothing can
be seen there:

php-ftpfs supplies you with a lot of debug options. When tracing down a bug,
generate a logfile using `-o debug -o debug_curl` and if the bug doesn't have
anything to do with big file read/write, also include `-o debug_raw`. Note
that debug messages are mixed to STDOUT and STDERR, catch them all with
appending `> logfile 2>&1`.

Please replace your password and your username, as they WILL appear in the
logs! There is NO way for php-ftpfs to filter them out of the logs!

## ToDo
1. Write tests, if such tests are possible at all with an automated test system
2. Support servers using only LIST instead of MLS(D/T)
3. Get a filesystem test suite and run it to verify php-ftpfs.
4. Use a metadata cache (done)
5. Use a file cache (done)

## Contribute
Just fork and file a pull request, or file an issue and upload a patch, or just
file the issue. I'm happy with everything.

Please run `ln -s ../../pre-commit.php .git/hooks/pre-commit` after initial
checkout so that you'll get notified in case your commit has syntax errors.

Also, before committing, please run `./format-all.php` to format the code nicely.

## License
This software is licensed under the PHP license, see LICENSE file for details.

I am not sure about the legality of the names "php-fuse" and "php-ftpfs".

## Donations
Liked php-ftpfs? Did it save your day? Tip me in BTC at
`1C7aCo3V8yc4V53bm5q29pn5MJiRznFZCw`

## Contact
Mail to marco@m-s-d.eu for questions.
