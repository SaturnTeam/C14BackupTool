# C14 Backup Tool
Backup tool for cheap C14 storage provided by online.net. Support encryption, diff backups and backup rotation. Description and prices here: https://www.online.net/en/c14.

Inspired by BackInTime
# Features
* Multi profile configuration
* Exclude folders and files
* Client-side encryption
* Backup rotations

# Requirements
PHP >= 5.6

rsync

encfs (+encfstools)

sshfs

ssh

ssh-keygen
### Optional dependence
cron

composer

fabiang/xmpp

# Installation
Without xmpp logs and composer
```
git clone https://github.com/TheSaturn/C14BackupTool.git
cd C14BackupTool
cp config.example.php config.php
```
If you want get logs by xmpp:
```
composer create-project thesaturn/c14-backup-tool
cp config.example.php config.php
```
# Terminology
*C14* — Storage type

*Safe* — Name of archives group in C14

*Archive* — Contains your files
# Configuring
All possible options described in `config.example.php`. Below some tips
* `config.php` must return array with profiles `return [...]`
* Every profile has its name
```
return [
    'default' => [...],
    'books2' => [...],
    'books3' => [...],
    'books4' => [...],
];
```
* All paths must be absolute to avoid possible errors

# Usage
Command line usage: `php /path/to/main.php profileName`
1. Register to [online.net](https://console.online.net/en/login)
2. Add your [debit/credit card](https://console.online.net/en/bill/list)
3. Generate ssh keys (if you don't have them) `ssh-keygen`
3. Edit config.php as you like
4. Test your configuration
5. Add backup tool to [cron](https://wiki.archlinux.org/index.php/cron)
6. Time to time look through archives in [safe lists](https://console.online.net/en/storage/c14/safe/list) to check size of archives
# Notices
* Diff backups created only in one archive. So you can always delete any archive without corrupting others
* Storage API is a quite slow and not stable. That is why code try several times to do operations and have sleep() function to wait, while operations will be applied.
* Archive creates for 7 days, but tool make backups only for 6 days since archive created.
* Encfs config file also copied to archive. So you don't need to save it somewhere else.
* If all rotation options is to false, all backups will be saved.
* If encryption enabled, exclude working with only absolute paths, regex is not available

