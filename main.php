<?php
include_once __DIR__ . '/src/EchoLogger.php';
include_once __DIR__ . '/src/HelperTrait.php';
include_once __DIR__ . '/src/RequestHandler.php';
include_once __DIR__ . '/src/C14API.php';
include_once __DIR__ . '/src/SSHInfo.php';
include_once __DIR__ . '/src/BackupHandler.php';

use thesaturn\C14BackupTool\BackupHandler;
use thesaturn\C14BackupTool\EchoLogger;

$configs = include __DIR__ . '/config.php';
$profileName = isset($argv[1]) ? $argv[1] : 'default';
$config = $configs[$profileName];

if (isset($config['xmpp']) && is_array($config['xmpp']))
{
    include_once __DIR__.'/vendor/autoload.php';

    extract($config['xmpp']);
    $options = new \Fabiang\Xmpp\Options($address);
    $options->setUsername($username)
        ->setPassword($password);

    //$options->setLogger(new EchoLogger($debugLevel));//you can also put the same text in stdout
    $client = new \Fabiang\Xmpp\Client($options);
    $log = new \thesaturn\xmpplogger\XMPPLogger($client, $config['xmpp']['to'], $debugLevel);
}
else
{
    $log = new EchoLogger($debugLevel);
}

try
{
    $backupManager = new BackupHandler($config, $log);
    $backupManager->doBackup();
    return 0;
}
catch (ErrorException $e)
{
    unset($backupManager);
    $log->error($e->getMessage() . "\n" . $e->getTraceAsString());
    $log->error('Fatal error. Repeat coming soon. Profile name: '. $profileName);
}
try //Let's try again! C14 is not stable.
{
    $backupManager = new BackupHandler($config, $log);
    $backupManager->doBackup();
    return 0;
}
catch (ErrorException $e)
{
    unset($backupManager);
    $log->error($e->getMessage() . "\n" . $e->getTraceAsString());
    $log->error('Fatal error. Profile name: '. $profileName);
    sleep(3);
    throw $e;
    return 1;
}