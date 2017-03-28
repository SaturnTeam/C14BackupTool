<?php
$debugLevel = 'info'; // 'error', 'info', 'debug'
$apiKey = 'token from';// https://console.online.net/en/api/access
$xmpp = [
    'address' => 'tcp://example.com:5222',
    'username' => 'saturn',
    'password' => 'your password from jabber',
    'to' => 'some@example.com'
];
$excludeList = [
    '.gvfs',
    '.cache* ',
    '[Cc]ache* ',
    '.thumbnails* ',
    '[Tt]rash* ',
    '*.backup* ',
    '*~ ',
    '.dropbox* ',
    '/proc/*',
    '/sys/*',
    '/dev/*',
    '/run/*',
    '/srv/ftp',
    '/etc/sudoers',
    '/etc/shadow',
    '/etc/shadow-',
    '/etc/shadow.pacnew',
    '/etc/passwd-',
    '/home/your-username/.steam',
    '/home/your-username/.local/share/Steam',
    '/home/your-username/Ubuntu One',
    '/home/your-username/VirtualBox VMs',
];
$rsyncOptions = '-rtDH \
    --checksum \
    --no-i-r \
    --links -A -X -pEgo \
    --delete \
    --partial \
    --ignore-errors  \
    --chmod=Du+wx \
    --delete-excluded -i';//  --out-format="BACKINTIME: %i %n%L"
return [
    'default' => [//profile name
        //example with encryption and xmpp
        'xmpp' => $xmpp,
        'encrypt' => true,
        'password' => 'password for encfs. Must be very strong',
        'privateKey' => 'full path to key', //usually /home/user/.ssh/id_rsa
        'publicKey' => 'full path to key.pub', //usually /home/user/.ssh/id_rsa.pub
        'safeName' => 'data',
        'rotationOptions' => [
            'onePerYear' => true,//1 backup for 1-12 months
            'onePer6Months' => true,//1 backup for 1-6 months
            'onePer3months' => true,//1 backup for 1-3 months
            'wholeMonth' => true,//all backups for 37 days
        ],
        'include' => [
            '/etc',
            '/srv',
            '/home',
        ],
        'exclude' => $excludeList,
        'apiKey' => $apiKey,
        'rsyncOptions' => $rsyncOptions,
    ],
    'books' => [
        //'xmpp' => $xmpp, no xmpp
        'encrypt' => false,
        'privateKey' => 'full path to key', //usually /home/user/.ssh/id_rsa
        'publicKey' => 'full path to key.pub', //usually /home/user/.ssh/id_rsa.pub
        'safeName' => 'books',
        'rotationOptions' => [],//keep all backups
        'include' => [
            '/etc',
            '/srv',
            '/home',
        ],
        'exclude' => $excludeList,
        'apiKey' => $apiKey,
        'rsyncOptions' => $rsyncOptions,
    ],
];

