<?php
namespace thesaturn\C14BackupTool;

use ErrorException;
/**
 * Dummy class to store ssh credentials
 * Class SSHInfo
 * @package TheSaturn\C14BackupTool
 */
class SSHInfo
{
    public $user;
    public $host;
    public $port;

    public function __construct($uri)
    {
        $parseUrl = parse_url($uri);
        if (!isset($parseUrl['user'], $parseUrl['host'], $parseUrl['port']))
        {
            throw new ErrorException('URI is wrong or does not contain necessary fields');
        }
        list($this->user, $this->host, $this->port) = [$parseUrl['user'], $parseUrl['host'], $parseUrl['port']];
    }
}