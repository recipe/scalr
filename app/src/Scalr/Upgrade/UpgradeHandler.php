<?php

namespace Scalr\Upgrade;

use Scalr\Upgrade\Entity\MysqlUpgradeEntity;
use Scalr\Upgrade\Entity\FilesystemUpgradeEntity;
use Scalr\Upgrade\Entity\AbstractUpgradeEntity;
use Scalr\Exception;
use \FilesystemIterator;
use \RegexIterator;

define('FS_STORAGE_PATH', CACHEPATH . '/upgrades/');

/**
 * UpgradeHandler
 *
 * @author   Vitaliy Demidov  <vitaliy@scalr.com>
 * @since    4.5.0 (10.10.2013)
 */
class UpgradeHandler
{

    const DB_TABLE_UPGRADES = 'upgrades';

    const DB_TABLE_UPGRADE_MESSAGES = 'upgrade_messages';

    const CMD_RUN_SPECIFIC = 'run-specific';

    /**
     * Path to filesystem storage including enclosing slash
     */
    const FS_STORAGE_PATH = FS_STORAGE_PATH;

    /**
     * Prevents infinity loops
     */
    const MAX_ATTEMPTS = 10;

    /**
     * Database instance
     *
     * @var \ADODB_mysqli
     */
    protected $db;

    /**
     * Max datetime which has been processed
     *
     * @var string
     */
    private $maxDate;

    /**
     * Console
     *
     * @var Console
     */
    protected $console;

    /**
     * The updates list
     *
     * @var UpdateCollection
     */
    private $updates;

    /**
     * The state before upgrade
     *
     * @var array
     */
    private $stateBefore;

    /**
     * Attempts counter to handle loops
     *
     * @var array
     */
    private $attempts;

    /**
     * Recurrences of the failed status
     *
     * @var array
     */
    private $recurrences;

    /**
     * Options
     *
     * @var array
     */
    private $opt;

    /**
     * Constructor
     *
     * @param   object   $opt  Run options
     */
    public function __construct($opt)
    {
        $this->opt = $opt;
        $this->db = \Scalr::getDb();
        $this->console = new Console();
        $this->updates = new UpdateCollection();
        $this->maxDate = '2013-01-01 00:00:00';
    }

    /**
     * Gets path to updates
     *
     * @return   string Returns path to updates without trailing slash
     */
    public static function getPathToUpdates()
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Updates';
    }

    /**
     * Gets last datetime which has been processed
     *
     * @return  string  Returns UTC in format 'YYYY-MM-DD HH:ii:ss'
     */
    protected function getLastDate()
    {
        return $this->maxDate;
    }

    /**
     * Fetches statuses of the previous updates
     */
    private function fetchStatusBefore()
    {
        $this->stateBefore = new \ArrayObject();

        //Loads performed updates of MYSQL type
        $rs = $this->db->Execute("
            SELECT LOWER(HEX(u.`uuid`)) `uuid`, u.`released`, u.`appears`, u.`applied`, u.`status`, LOWER(HEX(u.`hash`)) `hash`
            FROM `" . self::DB_TABLE_UPGRADES . "` u
        ");
        while ($rec = $rs->FetchRow()) {
            $entity = new MysqlUpgradeEntity();
            $entity->load($rec);
            $this->stateBefore[$rec['uuid']] = $entity;
            if (isset($entity->appears) && $this->maxDate < $entity->appears) {
                $this->maxDate = $entity->appears;
            }
        }

        //Loads updates of FileSystem type
        self::checkFilesystemStorage();

        //Loads performed updates of Filesystem type
        foreach (new FilesystemStorageIterator(self::FS_STORAGE_PATH) as $fileInfo) {
            /* @var $fileInfo \SplFileInfo */
            if (!$fileInfo->isReadable()) {
                throw new Exception\UpgradeException(sprintf(
                    'Could not read from file "%s". Lack of access permissions.', $fileInfo->getFilename()
                ));
            }
            $entity = new FilesystemUpgradeEntity();
            $obj = unserialize(file_get_contents($fileInfo->getPathname()));
            if (!is_object($obj)) {
                throw new Exception\UpgradeException(sprintf(
                    'There was error while trying to load record from filesystem storage "%s". Object is expected, %s given',
                    $fileInfo->getPathname(), gettype($obj)
                ));
            }
            $entity->load($obj);
            $this->stateBefore[$entity->uuid] = $entity;
            if (isset($entity->appears) && $this->maxDate < $entity->appears) {
                $this->maxDate = $entity->appears;
            }
            unset($obj);
        };
    }

    /**
     * Checks filesystem storage
     *
     * @throws   Exception\UpgradeException
     */
    public static function checkFilesystemStorage()
    {
        if (!is_dir(self::FS_STORAGE_PATH)) {
            if (@mkdir(self::FS_STORAGE_PATH, 0777) === false) {
                throw new Exception\UpgradeException(sprintf(
                    'Could not create directory "%s". Lack of access permissions to application cache folder.',
                    self::FS_STORAGE_PATH
                ));
            } else {
                file_put_contents(self::FS_STORAGE_PATH . '.htaccess', "Order Deny,Allow\nDeny from all\n");
                chmod(self::FS_STORAGE_PATH . '.htaccess', 0644);
            }
        }
    }

    /**
     * Loads updates from the implemented classes
     */
    protected function loadUpdates()
    {
        $this->fetchStatusBefore();
        foreach (new UpdatesIterator(self::getPathToUpdates()) as $fileInfo) {
            /* @var $fileInfo \SplFileInfo */
            $updateClass = __NAMESPACE__ . '\\Updates\\' . substr($fileInfo->getFilename(), 0, 20);
            try {
                /* @var $update \Scalr\Upgrade\AbstractUpdate */
                $update = new $updateClass($fileInfo, $this->stateBefore);
                $this->updates[$update->getUuidHex()] = $update;
            } catch (\Exception $e) {
                $this->console->error("Cound not load update %s. %s", $fileInfo->getPathname(), $e->getMessage());
            }
        }
    }

    /**
     * Applies update
     *
     * @param   AbstractUpdate   $upd   Update to apply
     */
    protected function applyUpdate(AbstractUpdate $upd)
    {
        if (!isset($this->attempts[$upd->getUuidHex()])) {
            $this->attempts[$upd->getUuidHex()] = 1;
        } else {
            $this->attempts[$upd->getUuidHex()]++;
        }

        if ($this->attempts[$upd->getUuidHex()] > self::MAX_ATTEMPTS) {
            throw new Exception\UpgradeException(sprintf(
                '"%s" Failed due to infinity loop. Max number of attempts (%d) reached!',
                $upd->getName(),
                self::MAX_ATTEMPTS
            ));
        }

        if ($upd->getStatus() == AbstractUpgradeEntity::STATUS_OK) {
            //Upgrade file is updated.
            $upd->updateAppears();
            //Compare checksum
            if ($upd->getEntity()->hash == $upd->getHash()) {
                if (isset($this->opt->cmd) && $this->opt->cmd == self::CMD_RUN_SPECIFIC &&
                    $this->opt->uuid == $upd->getUuidHex()) {
                    $this->console->warning('Nothing to do. %s has complete status.', $upd->getName());
                }
                return true;
            } else {
                //Update script has been changed and needs to be re-executed
                $upd->setStatus(AbstractUpgradeEntity::STATUS_PENDING);
                $upd->updateHash();
                $upd->getEntity()->save();
            }
        }

        $this->console->success('%s...', ($upd->description ?: $upd->getName()));

        //Checks updates this upgrade depends upon
        if (!empty($upd->depends)) {
            foreach ($upd->depends as $uuid) {
                $uuidhex = AbstractUpdate::castUuid($uuid);
                if (!empty($this->updates[$uuidhex])) {
                    $update = $this->updates[$uuidhex];
                    if ($update->getStatus() == AbstractUpgradeEntity::STATUS_OK) {
                        //Relative update has already been successfully applied.
                        continue;
                    }
                } else if (isset($this->stateBefore[$uuidhex])) {
                    /* @var $upgradeEntity \Scalr\Upgrade\Entity\AbstractUpgradeEntity */
                    $upgradeEntity = $this->stateBefore[$uuidhex];
                    if ($updateEntity->status == AbstractUpgradeEntity::STATUS_OK) {
                        //Relative update has already been applied
                        continue;
                    } else {
                        //Relative update needs to be applied before dependant.
                        $this->console->warning(
                            '"%s" has been declined as it depends on incomplete update "%s" which has status "%s". '
                          . 'Desired class "%s" does not exist in the expected folder.',
                            $upd->getName(),
                            $uuid,
                            $upgradeEntity->getStatusName(),
                            $upgradeEntity->getUpdateClassName()
                        );
                        return false;
                    }
                } else {
                    //Relative update has not been delivered yet.
                    $this->console->warning(
                        '"%s" has been postponed as it depends on "%s" which has not been delivered yet.',
                        $upd->getName(), $uuid
                    );
                    return false;
                }

                if ($update->getStatus() == AbstractUpgradeEntity::STATUS_FAILED && isset($this->recurrences[$update->getUuidHex()])) {
                    //Recurrence of the failed status. We don't need to report about it again.
                    $this->console->warning(
                        '"%s" has been declined because of failure dependent update "%s".',
                        $upd->getName(), $uuid
                    );
                    return false;
                }

                //Relative update has not been applied or it has incomplete status.
                //We need to apply it first.
                if ($this->applyUpdate($update) === false) {
                    $this->console->warning(
                        '"%s" has been declined. Could not apply related update "%s".',
                        $upd->getName(), $update->getName()
                    );
                    return false;
                }
            }
        }

        //Checks if update class implements SequenceInterface
        $stages = $upd instanceof SequenceInterface ? range(1, $upd->getNumberStages()) : array(1);
        $skip = 0;

        foreach ($stages as $stage) {
            //Checks if update is applied
            if ($upd->isApplied($stage)) {
                $upd->console->warning(
                    'Skips over the stage %d of update %s because it has already been applied.',
                    $stage, $upd->getName()
                );
                $skip++;
                continue;
            }

            //Validates environment before applying
            if (!$upd->validateBefore($stage)) {
                $this->console->warning(
                    'Stage %d of update %s could not be applied because of invalid environment!',
                    $stage, $upd->getName()
                );
                return false;
            }

            //Applies the update
            try {
                $upd->run($stage);
            } catch (\Exception $e) {
                //We should avoid repetition when another update depends on failed.
                $this->recurrences[$upd->getUuidHex()] = true;

                $upd->setStatus(AbstractUpgradeEntity::STATUS_FAILED);
                $upd->console->error('Stage %d of update %s failed! %s', $stage, $upd->getName(), $e->getMessage());
                $upd->getEntity()->save();
                $upd->getEntity()->createFailureMessage($upd->console->getLog());

                return false;
            }
        }

        $this->console->success("%s - OK", ($upd->description ?: $upd->getName()));
        $upd->setStatus(AbstractUpgradeEntity::STATUS_OK);
        $upd->updateHash();
        $upd->updateApplied();
        $upd->getEntity()->save();

        return true;
    }

    /**
     * Runs upgrade process
     */
    public function run()
    {
        //Loads updates
        $this->loadUpdates();

        if (isset($this->opt->cmd) && $this->opt->cmd == self::CMD_RUN_SPECIFIC) {
            $pending = array();
            if (!isset($this->updates[$this->opt->uuid])) {
                $this->console->warning("Could not find specified update %s", $this->opt->uuid);
                exit();
            }
            $pending[] = $this->updates[$this->opt->uuid];
        } else {
            $dt = new \DateTime($this->getLastDate(), new \DateTimeZone('UTC'));
            $pending = $this->updates->getPendingUpdates($dt->getTimestamp());
        }

        //Applies updates
        foreach ($pending as $update) {
            $this->applyUpdate($update);
        }
    }
}