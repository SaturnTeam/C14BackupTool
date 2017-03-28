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
 * Class C14API
 * @package TheSaturn\C14BackupTool
 */
class C14API
{
    use HelperTrait;
    /**
     * Max wait time while archive change status to active
     */
    const MAX_WAIT_TIME = 600;

    public $archiveNamePrefix = 'C14ABT';

    /**
     * @var string
     */
    public $apiKey;

    /**
     * @var RequestHandler
     */
    public $requestHandler;

    /**
     * @var string
     */
    public $sshPublicKeyPath;

    /**
     * C14API constructor.
     * @param $apiKey string
     * @param $publicKeyPath string
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct($apiKey, $publicKeyPath, $logger)
    {
        $this->apiKey = $apiKey;
        $this->requestHandler = new RequestHandler();//I know it is not good
        $this->sshPublicKeyPath = $publicKeyPath;
        $this->logger = $logger;
    }

    /**
     * Get list of safes
     * 100 max (paging is not implemented)
     * @return array
     */
    public function getSafe()
    {
        $answer = $this->sendC14Query('GET', 'safe', [
            'count' => 100,
        ]);
        return $answer;
    }

    /**
     * Get list of archives
     * 100 max (paging is not implemented)
     * @param string $safeName
     * @return array
     */
    public function getArchive($safeName)
    {
        $safeList = $this->getSafe();
        $safeUUID = null;
        foreach ($safeList as $item)
        {
            if ($item['name'] === $safeName || $item['uuid_ref'] === $safeName)
            {
                $safeUUID = $item['uuid_ref'];
                break;
            }
        }
        if ($safeUUID)
        {
            $list = $this->sendC14Query('GET', 'safe/' . $safeUUID . '/archive', ['count' => 100]);
        }
        return $list;
    }

    /**
     * Creates new archive for 7 days
     * @param string $safeUUID
     * @param string $archiveName
     * @return string
     */
    public function createArchive($safeUUID, $archiveName)
    {

        $archiveUUID = $this->sendC14Query('POST', 'safe/' . $safeUUID . '/archive', [
            'name' => $archiveName,
            'description' => ' ',
            'parity' => 'standard',
            'protocols' => ['ssh'],
            'ssh_keys' => [$this->getSSHKeyId()],
            'days' => 7,
            'platforms' => ['1'],
        ]);
        return $archiveUUID;
    }

    /**
     * Save archive name and description
     * @param string $safeUUID
     * @param string $archiveUUID
     * @param $data
     */
    public function modifyArchive($safeUUID, $archiveUUID, $data)
    {
        $this->sendC14Query('PATCH', "safe/$safeUUID/archive/$archiveUUID", $data);
    }

    /**
     * Returns uuid of ssh key in C14. If key is not found, add it to the C14
     * @return string
     * @throws Exception
     */
    public function getSSHKeyId()
    {
        exec('ssh-keygen -E md5 -lf ' . escapeshellarg($this->sshPublicKeyPath), $out);
        if (!isset($out[0]))
        {
            throw new Exception("Openssh is installed?");

        }
        preg_match('/MD5:([a-z0-9:]*)/', $out[0], $matches);
        $fingerPrint = $matches[1];
        $keys = $this->sendUserQuery('GET', 'key/ssh');
        foreach ($keys as $key)
        {
            if ($key['fingerprint'] === $fingerPrint)
            {
                return $key['uuid_ref'];
            }
        }
        return $this->sendUserQuery('POST', 'key/ssh', [
            'description' => $this->archiveNamePrefix . ' ssh key',
            'content' => file_get_contents($this->sshPublicKeyPath),
        ]);
    }

    /**
     * Creates safe with given name and return its uuid
     * @param string $safeName
     * @return string
     */
    public function createSafe($safeName)
    {
        return $this->sendC14Query('POST', 'safe', ['name' => $safeName, 'description' => 'For automated backups']);
    }

    /**
     * @param string $safeName
     * @return mixed
     */
    public function getOrCreateSafeUUID($safeName)
    {
        $safeList = $this->getSafe();
        foreach ($safeList as $item)
        {
            if ($item['name'] === $safeName)
            {
                return $item['uuid_ref'];
            }
        }
        return $this->createSafe($safeName)['uuid_ref'];
    }

    /**
     * @param string $safeUUID
     * @param string $archiveUUID
     */
    public function deleteArchive($safeUUID, $archiveUUID)
    {
        $this->sendC14Query('DELETE', 'safe/' . $safeUUID . '/archive/' . $archiveUUID);
    }

    /**
     * @param string $safeUUID
     * @return array
     * @throws ErrorException
     */
    public function getArchiveForBackupByUUID($safeUUID)
    {
        $archiveList = $this->getArchive($safeUUID);
        foreach ($archiveList as $item)
        {
            if (($pos = strpos($item['name'], $this->archiveNamePrefix)) !== false)
            {
                $archive = $this->getArchiveDetails($safeUUID, $item['uuid_ref']);
                if ($archive['status'] === 'active' && isset($archive['bucket']['archival_date']))
                {
                    $date = new DateTime($archive['bucket']['archival_date']);
                    //var_dump($archive);
                    $date->setTimezone((new DateTime())->getTimezone());
                    if ($date->diff(new DateTime(), true)->d > 0)
                    {
                        return $archive;
                    }
                }
            }
        }
        $archiveUUID = $this->createArchive($safeUUID, $this->archiveNamePrefix . ' ' . (new DateTime())->format('Y-m-d H:i:s'));
        $waited = 0;
        $timeout = 5;
        do
        {
            sleep($timeout);//wait while archive will be active
            $waited += $timeout;
            if ($waited > static::MAX_WAIT_TIME)
            {
                throw new ErrorException('Cannot wait so long while new archive will be active');
            }
            $archive = $this->getArchiveDetails($safeUUID, $archiveUUID);
        } while ($archive['status'] !== 'active');
        sleep(10);
        return $archive;
    }

    /**
     * Fetch all information about archive. See more in official API docs
     * @param string $safeUUID
     * @param string $archiveUUID
     * @return array
     */
    public function getArchiveDetails($safeUUID, $archiveUUID)
    {
        return $this->sendC14Query('GET', 'safe/' . $safeUUID . '/archive/' . $archiveUUID);
    }

    /**
     * Choose the best archive for backuping
     * @param string $safeName
     * @return array
     */
    public function getArchiveForBackupBySafeName($safeName)
    {
        $this->logger->debug('Get archive for safe ' . $safeName);
        $safeUUID = $this->getOrCreateSafeUUID($safeName);
        $this->logger->debug('Chosen safe UUID ' . $safeUUID);
        $archive = $this->getArchiveForBackupByUUID($safeUUID);
        return $archive;
    }

    /**
     * Send query to C14 part of API
     * @param string $httpMethod
     * @param string $endpoint
     * @param array $params
     * @return mixed
     */
    public function sendC14Query($httpMethod, $endpoint, $params = [])
    {
        $this->logger->debug('Send query to  ' . $httpMethod . ' /storage/c14/' . $endpoint . static::objToStr($params));
        $result = json_decode($this->requestHandler->sendQuery($httpMethod, '/storage/c14/' . $endpoint, $this->apiKey, $params), true);
        $this->logger->debug('Result of query ' . static::objToStr($result));
        return $result;
    }

    /**
     * Send query to User part of API
     * @param string $httpMethod
     * @param string $endpoint
     * @param array $params
     * @return mixed
     */
    public function sendUserQuery($httpMethod, $endpoint, $params = [])
    {
        $this->logger->debug('Send query to  ' . $httpMethod . ' /user/' . $endpoint . static::objToStr($params));
        $result = json_decode($this->requestHandler->sendQuery($httpMethod, '/user/' . $endpoint, $this->apiKey, $params), true);
        $this->logger->debug('Result of query ' . static::objToStr($result));
        return $result;
    }

}