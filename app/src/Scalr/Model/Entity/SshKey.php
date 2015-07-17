<?php
namespace Scalr\Model\Entity;

use Scalr\Model\AbstractEntity;
use \Exception;
use Scalr\Util\CryptoTool;

/**
 * SshKey entity
 *
 * @author   Igor Vodiasov  <invar@scalr.com>
 * @since    5.5 (30.04.2015)
 *
 * @Entity
 * @Table(name="ssh_keys")
 */
class SshKey extends AbstractEntity
{
    const TYPE_GLOBAL = 'global';
    const TYPE_USER	  = 'user';

    /**
     * The identifier of Ssh Key
     *
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     * @var integer
     */
    public $id;

    /**
     * Type of Ssh key
     *
     * @Column(type="string")
     * @var string
     */
    public $type;

    /**
     * Name of Ssh Key in cloud
     *
     * @Column(type="string")
     * @var string
     */
    public $cloudKeyName;

    /**
     * Private Key
     *
     * @Column(type="encrypted")
     * @var string
     */
    public $privateKey;

    /**
     * Public Key
     *
     * @Column(type="encrypted")
     * @var string
     */
    public $publicKey;

    /**
     * Platform
     *
     * @Column(type="string")
     * @var string
     */
    public $platform;

    /**
     * Cloud Location
     *
     * @Column(type="string")
     * @var string
     */
    public $cloudLocation;

    /**
     * The identifier of the farm
     *
     * @Column(type="integer",nullable=true)
     * @var integer
     */
    public $farmId;

    /**
     * The identifier of the client's environment
     *
     * @Column(type="integer",nullable=true)
     * @var integer
     */
    public $envId;

    /**
     * Check if key is used by any farm role
     *
     * @return bool
     */
    public function isUsed()
    {
        return !!$this->db()->GetOne("SELECT COUNT(*) FROM farm_roles WHERE farmid = ? AND platform = ? AND (cloud_location = ? OR cloud_location = '')", [
            $this->farmId,
            $this->platform,
            $this->cloudLocation
        ]);
    }

    /**
     * Find global key by FarmID
     *
     * @param $envId
     * @param $farmId
     * @param $cloudLocation
     * @param $platform
     * @return SshKey|null
     */
    public function loadGlobalByFarmId($envId, $farmId, $cloudLocation, $platform)
    {
        $farmId = $farmId ? $farmId : NULL;

        $criteria = [
            [ 'envId' => $envId ],
            [ 'type' => self::TYPE_GLOBAL ],
            [ 'platform' => $platform ],
            [ 'farmId' => $farmId ],
            [ '$or' => [['cloudLocation' => ''], ['cloudLocation' => $cloudLocation]] ]
        ];

        return self::findOne($criteria);
    }


    /**
     * Get list of platforms where ssh keys are available on environment
     *
     * @param $envId
     * @return array
     * @throws \Scalr\Exception\ModelException
     */
    public function getEnvironmentPlatforms($envId)
    {
        return $this->db()->GetCol('SELECT DISTINCT `platform` FROM ' . $this->table(). ' WHERE `env_id` = ?', [ $envId ]);
    }

    /**
     * Convert private key to putty format
     *
     * @return string
     */
    public function getPuttyPrivateKey()
    {
        $descriptorSpec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );

        $pemPrivateKey = tempnam("/tmp", "SSHPEM");
        @file_put_contents($pemPrivateKey, $this->privateKey);
        @chmod($pemPrivateKey, 0600);

        $ppkPrivateKey = tempnam("/tmp", "SSHPPK");

        $keyName = $this->cloudKeyName;
        if ($this->cloudLocation)
            $keyName .= ".{$this->cloudLocation}";
        
        $pipes = array();
        $process = @proc_open("puttygen {$pemPrivateKey} -C {$keyName} -o {$ppkPrivateKey}", $descriptorSpec, $pipes);
        if (@is_resource($process)) {
            @fclose($pipes[0]);

            stream_get_contents($pipes[1]);

            fclose($pipes[1]);
            fclose($pipes[2]);
        }

        $retval = file_get_contents($ppkPrivateKey);

        @unlink($pemPrivateKey);
        @unlink($ppkPrivateKey);

        return $retval;
    }

    /**
     * External call to ssh-keygen utility
     *
     * @param array $args
     * @param string $tmpFileContents
     * @param bool $readTmpFile
     * @return string
     */
    private function getSshKeygenValue($args, $tmpFileContents, $readTmpFile = false)
    {
        $descriptorSpec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );

        $filePath = CACHEPATH . "/_tmp." . CryptoTool::hash($tmpFileContents);

        if (!$readTmpFile) {
            @file_put_contents($filePath, $tmpFileContents);
            @chmod($filePath, 0600);
        }

        $pipes = array();
        $process = @proc_open("ssh-keygen -f {$filePath} {$args}", $descriptorSpec, $pipes);
        if (@is_resource($process)) {
            @fclose($pipes[0]);

            $retval = trim(stream_get_contents($pipes[1]));

            fclose($pipes[1]);
            fclose($pipes[2]);
        }

        if ($readTmpFile)
            $retval = file_get_contents($filePath);

        @unlink($filePath);

        return $retval;
    }

    /**
     * Generate private and public key
     *
     * @return array
     * @throws Exception
     */
    public function generateKeypair()
    {
        $this->privateKey = $this->getSshKeygenValue("-t dsa -q -P ''", "", true);
        $this->generatePublicKey();

        return [
            'private' => $this->privateKey,
            'public' => $this->publicKey
        ];
    }

    /**
     * Generate public key
     *
     * @return string
     * @throws Exception
     */
    public function generatePublicKey()
    {
        if (!$this->privateKey)
            throw new Exception("Public key cannot be generated without private key");

        $this->publicKey = $this->getSshKeygenValue("-y", $this->privateKey);
        return $this->publicKey;
    }
}