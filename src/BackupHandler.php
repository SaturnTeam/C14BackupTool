<?php
/**
 *
 * This file is licensed under the MIT License. See the LICENSE file.
 *
 * @author Dmitry Volynkin <thesaturn@thesaturn.me>
 */

namespace thesaturn\C14BackupTool;

use DateTime;
use ErrorException;

/**
 * The major class of this tool
 * Class BackupHandler
 * @package TheSaturn\C14BackupTool
 */
class BackupHandler
{
    use HelperTrait;
    /**
     * All backups inside that folder
     */
    const BACKUP_DIR = 'C14ABT';

    /**
     * Folder for creating backup. After successful backup files will be moved to its place
     */
    public $backupTempDir = '';

    /**
     * Folder name template
     */
    const BACKUP_FOLDER_FORMAT = 'Y-m-d H:i:s';

    /**
     * Possible rotation options
     */
    const ROTATION_OPTIONS = ['onePerYear', 'onePer6Months', 'onePer3months', 'wholeMonth',];

    /**
     * Sometimes something wrong with C14
     * @var int
     */
    public static $attempts = 20;

    /**
     * Folder with all mountpoints
     * @var string
     */
    public $allMountPointsDir;

    /**
     * Folder with all mountpoints for safe
     * @var string
     */
    public $mountPointsDir;

    /**
     * @var bool
     */
    public $encryption = false;

    /**
     * Password for encfs
     * @var string
     */
    public $encryptionPassword = '';

    /**
     * Path to private ssh key
     * @var string
     */
    public $privateKey;

    /**
     * Files and folders to backup
     * @var array
     */
    public $include = [];

    /**
     * Files and folder to exclude from backup. Support rsync regex WITHOUT encryption
     * @var array
     */
    public $exclude = [];

    /**
     * Name of safe, where to store all backups. Must be different for different profiles
     * @var string
     */
    public $safeName = '';

    /**
     * Mountpoint for c14
     * @var string
     */
    public $c14mountDir = '';

    /**
     * If there are many files in folders, it is better to turn off continuous backups
     * @var bool
     */
    public $incremental = true;

    /**
     * Mountpoint of encrypted view
     * @var string
     */
    public $encryptedDir = '';

    /**
     * Options passed to rsync command
     * @var string
     */
    public $rsyncOptions = '';

    /**
     * Options for deleting old archives
     * @var array
     */
    public $backupRotationOptions = [];

    /**
     * @var C14API API instance
     */
    public $c14;

    /**
     * Local path to encfs config
     * @var string
     */
    public $encfsConfigLocalFile = '';

    /**
     * Path to encfs config inside  $c14mountDir
     * @var string
     */
    public $encfsConfigRemoteFile = '';


    /**
     * BackupHandler constructor.
     * I know it is not the best way to pass config here. You can change it and commit
     * @param $config array
     * @param \Psr\Log\LoggerInterface $logger
     * @throws ErrorException
     */
    public function __construct($config, $logger)
    {
        $this->logger = $logger;

        $this->backupTempDir = 'backup_temp_' . mt_rand();

        $this->incremental = @$config['incremental'] === true;
        $this->safeName = $config['safeName'];
        $this->allMountPointsDir = dirname(__DIR__) . '/mountpoints/';
        $this->mountPointsDir = $this->allMountPointsDir . $this->safeName . '/';
        $this->c14mountDir = $this->mountPointsDir . 'c14';
        $this->encryptedDir = $this->mountPointsDir . 'encrypted';

        $this->include = $config['include'];
        $this->exclude = $config['exclude'];

        $this->encryption = $config['encrypt'];
        $this->encryptionPassword = @$config['password'];
        if ($this->encryption && empty($this->encryptionPassword))
        {
            throw new ErrorException('"password" is not set but encryption is enabled');
        }

        $this->privateKey = $config['privateKey'];

        $this->rsyncOptions = $config['rsyncOptions'];

        $this->backupRotationOptions = $config['rotationOptions'];
        foreach ($config['rotationOptions'] as $key => $value)
        {
            if (!in_array($key, static::ROTATION_OPTIONS))
            {
                throw new ErrorException('Unknown time interval: ' . $key);
            }
            if ($value === true)
            {
                $this->backupRotationOptions[] = $key;
            }
        }

        $this->createDirsForMountPoints();

        $this->c14 = new C14API($config['apiKey'], $config['publicKey'], $logger);
    }

    public function createDirsForMountPoints()
    {
        $this->mkdirOrDie($this->allMountPointsDir);
        $this->mkdirOrDie($this->mountPointsDir);
        $this->mkdirOrDie($this->c14mountDir);
        if ($this->encryption)
        {
            $this->mkdirOrDie($this->encryptedDir);
        }
    }

    public function mkdirOrDie($dir)
    {
        $this->logger->debug('Creating folder" ' . $dir);
        if (@mkdir($dir) && !is_dir($dir))
        {
            throw new ErrorException('Cannot create folder ' . $this->c14mountDir);
        }
    }

    /**
     * Initiates backuping
     */
    public function doBackup()
    {
        $this->logger->info('Backup started for safe ' . $this->safeName);
        $this->logger->debug('Get archive for backup');
        $archive = $this->c14->getArchiveForBackupBySafeName($this->safeName);
        $this->logger->debug('Archive for backup is ' . static::objToStr($archive));
        $sshInfo = new SSHInfo($archive['bucket']['credentials'][0]['uri']);
        $this->mountC14Storage($sshInfo);
        $this->createBackupFolders();
        if ($this->encryption)
        {
            $this->enableEncryption();
        }
        if ($this->incremental)
        {
            $this->createHardLinksFromLastBackup();
        }
        $this->makeBackup($sshInfo);
        $this->mountC14Storage($sshInfo);// sometimes it is helpful
        $this->renameTempFolder();
        $this->writeBackupInfoToC14($archive, (new DateTime('now'))->format(static::BACKUP_FOLDER_FORMAT));
        sleep(1);//wait a little while description will be updated
        $archive = $this->c14->getArchiveForBackupBySafeName($this->safeName);
        $this->logger->info('Backup success. Backups in last archive '
            . static::objToStr($archive['description']));
    }

    /**
     * @param SSHInfo $ssh
     * @return bool
     * @throws ErrorException
     */
    public function mountC14Storage(SSHInfo $ssh)
    {
        $cmd = 'sshfs ' . $ssh->user . '@' . $ssh->host
            . ':/buffer -p ' . escapeshellarg($ssh->port) . ' '
            . escapeshellarg($this->c14mountDir)
            . ' -o StrictHostKeyChecking=no -o IdentityFile='
            . escapeshellarg($this->privateKey)
            . ' 2>&1';
        $this->logger->debug("Mount cloud storage command\n$cmd");
        for ($i = 0; $i < static::$attempts; $i++)
        {
            exec($cmd, $output, $returnCode);
            sleep(1);//yep, i's necessary
            if ($returnCode === 0 || in_array('fuse: mountpoint is not empty', $output) || substr($output[0], strlen('fusermount3: failed to access mountpoint') === 'fusermount3: failed to access mountpoint'))
            {
                return true;
            }
        }
        throw new ErrorException('Error in mounting c14 folder. cmd: ' . $cmd . 'sshfs output' . static::objToStr($output));
    }

    /**
     * Creates folders inside C14 mounted folder
     */
    public function createBackupFolders()
    {
        $backupDir = $this->c14mountDir . '/' . static::BACKUP_DIR;
        $this->mkdirOrDie($backupDir);
        $backupTempDir = $this->c14mountDir . '/' . $this->backupTempDir;
        $this->mkdirOrDie($backupTempDir);
    }


    /**
     * Mount encrypted view. If there isn't encfs config file, trying to copy it from storage.
     * Otherwise, creates new
     * @throws ErrorException
     */
    public function enableEncryption()
    {
        $this->logger->debug('Activating encryption');
        $descriptorspec = array(
            0 => array('pipe', 'r'),//stdin
            1 => array('pipe', 'w'),//stdout
            2 => array('pipe', 'w')//stderr
        );
        $encfsConfigFileName = $this->safeName . '.encfs6.xml';
        $this->encfsConfigLocalFile = $this->mountPointsDir . '/' . $encfsConfigFileName;
        $this->encfsConfigRemoteFile = $this->c14mountDir . '/' . $encfsConfigFileName;
        if (!file_exists($this->encfsConfigLocalFile))
        {
            if (file_exists($this->encfsConfigRemoteFile))
            {
                $this->logger->info('Encfs config file was not found locally, but found in remote storage');
                if (!copy($this->encfsConfigRemoteFile, $this->encfsConfigLocalFile))
                {
                    throw new ErrorException('Cannot copy encfs config to local device ' . $this->encfsConfigLocalFile);
                }
            }
            else
            {
                $this->logger->info('Encfs config was not found on remote and local storage. New config will be created');
                $deleteDir = $this->mountPointsDir . mt_rand();//temporary directory
                $this->mkdirOrDie($deleteDir);
                $cmd = 'encfs --reverse  ' . escapeshellarg(__DIR__) . ' ' . escapeshellarg($deleteDir) . ' --extpass "echo ' . escapeshellarg($this->encryptionPassword) . '"';
                $process = proc_open(
                    $cmd,
                    $descriptorspec,
                    $pipes
                );
                $this->logger->debug('Command: ' . 'encfs --reverse  ' . escapeshellarg(__DIR__) . ' ' . escapeshellarg($deleteDir));// hide password from logs
                fwrite($pipes[0], "x\n1\n256\n4096\n1\ny\n");
                $stdout = fgetss($pipes[1]);
                $stderr = fgetss($pipes[2]);
                fwrite($pipes[0], "{$this->encryptionPassword}\n{$this->encryptionPassword}\n");
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $returnCode = proc_close($process);
                if ($returnCode !== 0)
                {
                    if (!is_array($stdout) || !isset($stdout[0]) || $stdout[0] !== 'fuse: mountpoint is not empty')
                    {
                        throw new ErrorException('Cannot create encrypted view of root '
                            . static::objToStr(compact('stdout', 'stderr')));
                    }
                }
                if (!copy(__DIR__ . '/.encfs6.xml', $this->encfsConfigLocalFile))
                {
                    throw new ErrorException('Cannot copy encfs config to local device ' . $this->encfsConfigLocalFile);
                }
                unlink(__DIR__ . '/.encfs6.xml');
                $cmd = 'fusermount -u ' . escapeshellarg($deleteDir) . ' 2>&1';
                $this->logger->debug('Unmount temp new encfs encrypted folder: ' . $cmd);
                exec($cmd, $out, $return_code);
                if ($return_code !== 0)
                {
                    $this->logger->info('Cannot unmount new temp folder');
                }
                $this->rmdir($deleteDir);
            }
        }
        $cmd = 'ENCFS6_CONFIG=' . escapeshellarg($this->encfsConfigLocalFile)
            . ' encfs --reverse / ' . escapeshellarg($this->encryptedDir);
        exec($cmd . ' --extpass "echo ' . escapeshellarg($this->encryptionPassword) . '" 2>&1',
            $stdout,
            $returnCode
        );
        if ($returnCode !== 0 && $returnCode !== 139)
        {
            if (!is_array($stdout) || !isset($stdout[0]) || $stdout[0] !== 'fuse: mountpoint is not empty')
            {
                throw new ErrorException("Cannot make encrypted view of root. 
            Command: $cmd Exit code $returnCode" . static::objToStr($stdout));
            }
        }
        if (!copy($this->encfsConfigLocalFile, $this->encfsConfigRemoteFile))
        {
            $this->logger->info('Copy local encfs config to remote folder failed');
        }
        $this->encryptDirNames();
    }

    /**
     * Replace include and exclude files with their encrypted representation
     */
    public function encryptDirNames()
    {
        $this->exclude[] = $this->encryptedDir;
        foreach ($this->include as &$item)
        {
            $item = $this->convertPlainDirNameToEncryptedDirName($item);
        }
        unset($item);
        foreach ($this->exclude as &$item)
        {
            $item = $this->convertPlainDirNameToEncryptedDirName($item);
        }
        unset($item);
    }

    /**
     * Encode file names via encfsctl
     * @param $dirName string
     * @return string
     * @throws ErrorException
     */
    public function convertPlainDirNameToEncryptedDirName($dirName)
    {
        exec('ENCFS6_CONFIG=' . escapeshellarg($this->encfsConfigLocalFile) . ' encfsctl encode ' . escapeshellarg($this->encryptedDir) . ' ' . escapeshellarg($dirName) . ' --extpass "echo ' . escapeshellarg($this->encryptionPassword) . '"', $out, $returnCode);
        if ($returnCode !== 0)
        {
            throw new ErrorException('Cannot encode path ' . $dirName . ' ' . $returnCode
                . static::objToStr($out));
        }
        return $this->encryptedDir . '/' . $out[0];
    }

    /**
     * Reduce network operations
     * @return bool|string
     * @throws ErrorException
     */
    public function createHardLinksFromLastBackup()
    {
        $this->logger->debug('Creating hard links from last backup folder');
        $dirs = scandir($this->c14mountDir . '/' . static::BACKUP_DIR);
        $lastSuccessfulBackupDir = new DateTime('@0');
        foreach ($dirs as $dir)
        {
            if (($date = DateTime::createFromFormat(static::BACKUP_FOLDER_FORMAT, $dir)) && $date > $lastSuccessfulBackupDir)
            {
                $lastSuccessfulBackupDir = $date;
            }
        }
        if ($lastSuccessfulBackupDir->format(static::BACKUP_FOLDER_FORMAT) !== (new DateTime('@0'))->format(static::BACKUP_FOLDER_FORMAT))
        {
            $cmd = 'cp -al ' . escapeshellarg($this->c14mountDir . '/' . static::BACKUP_DIR . '/' . $lastSuccessfulBackupDir->format(static::BACKUP_FOLDER_FORMAT) . '/.') . ' ' . escapeshellarg($this->c14mountDir . '/' . $this->backupTempDir . '/') . ' 2>&1';
            exec($cmd, $out, $return);
            return $lastSuccessfulBackupDir->format(static::BACKUP_FOLDER_FORMAT);
        }
        return false;
    }

    /**
     * Real rsync backup function
     * @param SSHInfo $ssh
     * @throws ErrorException
     */
    public function makeBackup(SSHInfo $ssh)
    {
        $this->logger->info('YAY! The real backuping is starting!');
        $cmd = "rsync -e 'ssh -p {$ssh->port}' " . $this->rsyncOptions;
        foreach ($this->exclude as $item)
        {
            $cmd .= ' --exclude=' . escapeshellarg($item);
        }
        foreach ($this->include as $item)
        {
            $cmd .= ' ' . escapeshellarg($item);
        }
        $cmd .= ' ' . $ssh->user . '@' . $ssh->host . ':/buffer/' . $this->backupTempDir . ' 2>&1';
        for ($i = 0; $i < static::$attempts; $i++)
        {
            exec($cmd, $out, $returnCode);
            if ($returnCode === 0 || $returnCode === 23)
            {
                return;
            }
            sleep(5);
        }
        throw new  ErrorException('Backup failed. Command: ' . $cmd . static::objToStr($out));
    }

    /**
     * Move successfully backuped files to specific folder with current date
     * @throws ErrorException
     */
    public function renameTempFolder()
    {
        $backupDir = $this->c14mountDir . '/' . static::BACKUP_DIR;
        $backupTempDir = $this->c14mountDir . '/' . $this->backupTempDir;
        $newDirName = (new DateTime('now'))->format(static::BACKUP_FOLDER_FORMAT);
        $cmd = 'mv ' . escapeshellarg($backupTempDir) . ' ' . escapeshellarg($backupDir . '/' . $newDirName) . ' 2>&1';
        exec($cmd, $out, $returnCode);
        if ($returnCode !== 0)
        {
            throw new ErrorException('Cannot rename temp folder ' . $cmd . ' ' . $returnCode
                . static::objToStr($out));
        }
    }

    /**
     * Archive description must contain information about successful backups
     * @param $archive
     * @param $newDate
     */
    protected function writeBackupInfoToC14($archive, $newDate)
    {
        $this->c14->modifyArchive($this->c14->getOrCreateSafeUUID($this->safeName), $archive['uuid_ref'], [
            'description' => strlen($archive['description']) < 5 ? $newDate : ($archive['description'] . "\n" . $newDate)
        ]);
    }

    /**
     * Delete old archives
     */
    public function backupRotate()
    {
        if (count($this->backupRotationOptions) === 0)
        {
            return;//keep all backups
        }
        $this->logger->debug('Backup rotate started');
        $archiveList = $this->c14->getArchive($this->safeName);
        $safeUUID = $this->c14->getOrCreateSafeUUID($this->safeName);
        $backupsForSave = [];
        foreach ($this->backupRotationOptions as $backupRotationOption)
        {
            $backupsForSave[$backupRotationOption] = [];
        }
        $currentDate = new DateTime('now');
        $dontRemove = [];
        foreach ($archiveList as $archiveName)
        {
            $archive = $this->c14->getArchiveDetails($safeUUID, $archiveName['uuid_ref']);
            if (strlen($archiveName['description']) > 5)
            {//There is one successful backup required
                if (isset($archive['creation_date']))
                {//In some archive status there isn't creation date
                    $date = new DateTime($archive['creation_date']);
                    $date->setTimezone((new DateTime())->getTimezone());
                    $diffInDays = $currentDate->diff($date)->days;
                    if (isset($backupsForSave['wholeMonth']) && $diffInDays < 31 + 7)
                    {//если нужно сохранять все бекапы за месяц
                        $dontRemove[] = $archive['uuid_ref'];
                    }
                    else if (isset($backupsForSave['onePer3months']) && $diffInDays < 92 + 7)
                    {
                        $backupsForSave['onePer3months'][$archive['uuid_ref']] = $date;
                    }
                    else if (isset($backupsForSave['onePer6Months']) && $diffInDays < 185 + 7)
                    {
                        $backupsForSave['onePer6Months'][$archive['uuid_ref']] = $date;
                    }
                    else if (isset($backupsForSave['onePerYear']) && $diffInDays < 365 + 7)
                    {
                        $backupsForSave['onePerYear'][$archive['uuid_ref']] = $date;
                    }
                }
                else
                {
                    $dontRemove[] = $archive['uuid_ref'];
                }
            }
        }
        foreach ($backupsForSave as $interval => $items)
        {//save only one  archive per group
            asort($backupsForSave[$interval]);
            reset($backupsForSave[$interval]);
            $dontRemove[] = key($backupsForSave[$interval]);
        }
        foreach ($archiveList as $archive)
        {
            if (!in_array($archive['uuid_ref'], $dontRemove))
            {
                $this->logger->info('Backups for delete: ' . static::objToStr($archive));
                $this->c14->deleteArchive($safeUUID, $archive['uuid_ref']);
            }
        }
    }


    /**
     * Delete or notify
     * @param $dir string
     */
    public function rmdir($dir)
    {
        if (!rmdir($dir))
        {
            $this->logger->info('Delete folder failed. ' . $dir);
        }
    }

    /**
     * Unmount encfs and sshfs
     */
    public function __destruct()
    {
        $this->unmount();
    }

    /**
     * Unmount folders. No messages if errors.
     */
    public function unmount()
    {
        $this->logger->debug('Unmount folders');
        exec('fusermount -u ' . escapeshellarg($this->c14mountDir) . ' 2>&1');
        if ($this->encryption)
        {
            exec('fusermount -u ' . escapeshellarg($this->encryptedDir) . ' 2>&1');
        }
    }
}
