<?php
class Siamgeo_Scheduler_Engine
{
    /**
     * @var AmazonEC2
     */
    private $_awsService;
    private $_templateService;
    private $_deployer;

    private $_debug;

    private $_usernameLookup;

    private $_tables = array();

    /**
     * @var Zend_Log
     */
    private $_logger;

    private $_currentGroupName;

    private $_state;

    private $_profileTbl;

    /**
     * Singleton instance
     *
     * @var Siamgeo_Scheduler_Engine
     */
    protected static $_instance = null;

    /**
     * Singleton pattern implementation makes "new" unavailable
     *
     * @return void
     */
    protected function __construct(Siamgeo_Aws_Service $awsService, Siamgeo_Template_Service $templateService,
                                   Siamgeo_Deploy $deployer, Siamgeo_Service_CustomerService $customerService)
    {
        $this->_awsService = $awsService;
        $this->_templateService = $templateService;
        $this->_deployer = $deployer;

        $this->_usernameLookup = $customerService->getCustomerUsernameLookup();
        $this->_profileTbl = $this->getTable('Profile');
    }

    /**
     * Singleton pattern implementation makes "clone" unavailable
     *
     * @return void
     */
    protected function __clone() {}

    /**
     * Returns an instance of Siamgeo_Scheduler_Engine
     *
     * Singleton pattern implementation
     *
     * @return Siamgeo_Scheduler_Engine Provides a fluent interface
     */
    public static function getInstance(Siamgeo_Aws_Service $awsService, Siamgeo_Template_Service $templateService,
                                   Siamgeo_Deploy $deployer, Siamgeo_Service_CustomerService $customerService)
    {
        if (null === self::$_instance) {
            self::$_instance = new self($awsService, $templateService, $deployer, $customerService);
        }

        return self::$_instance;
    }

    private function idCustomerToUsername($idCustomer)
    {
        // warning - currently has no error checking
        return $this->_usernameLookup[$idCustomer];
    }

    public function setDebugMode($debug = true)
    {
        $this->_debug = $debug;
        return $this;
    }

    public function getDebugMode()
    {
        return $this->_debug;
    }

    public function setLogger(Zend_Log $logger)
    {
        $this->_logger = $logger;
        $this->_awsService->setLogger($logger);
        return $this;
    }

    private function _logContext()
    {
        if ($this->_logger) {
            $this->_logger->debug(__FILE__ . '(' . __LINE__ . ') Context {'
                . 'idProfile=' . $this->getState('idProfile') . ', '
                . 'idcustomer=' . $this->getState('idCustomer') . ', '
                . 'domainName=' . $this->getState('domainName') . ', '
                . 'sslRequired=' . $this->getState('sslRequired') . ', '
                . 'publicIp=' . $this->getState('publicIp') . ', '
                . 'instanceType=' . $this->getState('instanceType') . ', '
                . 'regionName=' . $this->getState('regionName') . ', '
                . 'status=' . $this->getState('status') . ', '
                . 'metaStatus=' . $this->getState('metaStatus') . '}'
            );
        }
    }

    public function setContext($options)
    {
        $idProfile  = $options['idProfile'];
        $idCustomer = $options['idCustomer'];
        $regionName = $options['regionName'];

        $username = $this->_usernameLookup[$idCustomer];

        $this->_currentGroupName = $username . '-' . sprintf("%06d", $idProfile);

        $this->_state[$this->_currentGroupName] = $options;

        try {
            $this->_awsService->setContext($regionName, $username, $idProfile);
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logContext();
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }

            if ($this->_debug) throw $e;
        }

    }

    private function getTable($tableName)
    {
        if (isset($this->_tables[$tableName]))
            return $this->_tables[$tableName];

        $class = 'Siamgeo_Db_Table_' .$tableName;

        $this->_tables[$tableName] = new $class();
        return $this->_tables[$tableName];
    }

    private function getState($name)
    {
        return $this->_state[$this->_currentGroupName][$name];
    }

    private function setState($name, $value)
    {
        $this->_state[$this->_currentGroupName][$name] = $value;
    }

    public function getCurrentStatus()
    {
        return $this->_state[$this->_currentGroupName]['status'];
    }

    public function allocateIpAddress()
    {
        $idProfile = $this->getState('idProfile');
        if ($this->_logger) $this->_logger->info(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PENDING . '" status so attempting to allocate ip address');

        try {
            $publicIp = $this->_awsService->allocateIpAddress();

            $this->_profileTbl->updatePublicIp($idProfile, $publicIp);
            $this->_profileTbl->updateStatus($idProfile, Siamgeo_Db_Table_Profile::PASSED_ALLOCATED_IP_ADDRESS);

            $this->setState('status', Siamgeo_Db_Table_Profile::PASSED_ALLOCATED_IP_ADDRESS);
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logContext();
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }

            if ($this->_debug) throw $e;
        }
    }

    public function createSecurityGroup()
    {
        try {
            $includeSsl = ($this->getState('sslRequired') == 'Y') ? true : false;
            $this->_awsService->createSecurityGroup($includeSsl);

            $this->_profileTbl->updateStatus($this->getState('idProfile'), Siamgeo_Db_Table_Profile::PASSED_SECURITY_GROUP_CREATED);
            $this->setState('status', Siamgeo_Db_Table_Profile::PASSED_SECURITY_GROUP_CREATED);
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logContext();
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }

            if ($this->_debug) throw $e;
        }
    }

    public function createKeyPair()
    {
        if ($this->_logger)
            $this->_logger->info(__FILE__ . '(' . __LINE__ .') Profile: ' . sprintf("%06d", $this->getState('idProfile')) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_SECURITY_GROUP_CREATED . '" status so attempting to create a new key pair');

        try {
            $this->_awsService->createKeyPair();
            $this->_profileTbl->updateStatus($this->getState('idProfile'), Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED);
            $this->setState('status', Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED);
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logContext();
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }

            if ($this->_debug) throw $e;
        }
    }

    public function launchInstance($configInitFilepath)
    {
        if ($this->_logger)
            $this->_logger->info(__FILE__ . '(' . __LINE__ .') Profile: ' . sprintf("%06d", $this->getState('idProfile')) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED . '" status so attempting to run instance');

        try {
            $instanceTable = $this->getTable('Instance');

            $configInit = base64_encode(file_get_contents($configInitFilepath));

            if (!$configInit) {
                if ($this->_logger)
                    $this->_logger->emerg(__FILE__ . '(' . __LINE__ .') failed to load the config-init file ' . $configInitFilepath);
            }

            $instanceData = $this->_awsService->runInstances($this->getState('instanceType'), $configInit);
            $instanceData['regionName'] = $this->getState('regionName');

            $instanceTable->addInstance($this->getState('idProfile'), $instanceData);

            $this->_profileTbl->updateStatus($this->getState('idProfile'), Siamgeo_Db_Table_Profile::PASSED_INSTANCE_STARTED);
            $this->setState('status', Siamgeo_Db_Table_Profile::PASSED_INSTANCE_STARTED);
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logContext();
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }

            if ($this->_debug) throw $e;
        }
    }

    public function describeInstances()
    {

        if ($this->_logger)
            $this->_logger->info(__FILE__ . '(' . __LINE__ .') Profile: ' . sprintf("%06d", $this->getState('idProfile')) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_INSTANCE_STARTED . '" status so attempting to associate the public ip to the instance');

        try {
            $instanceTable = $this->getTable('Instance');

            // lookup the running instance the instance name will be format of 'i-1234656'
            $instanceRow = $instanceTable->getActiveInstanceByProfileId($this->getState('idProfile'));

            // check to make sure the instance is in the running state before
            // attempting to associate the public ip to the instance name
            $instanceResponseData = $this->_awsService->describeInstances($instanceRow->instanceName);

            // update the instance status whatever happens
            $instanceTable->updateStatus($instanceRow->idInstance,
                $instanceResponseData['code'],
                $instanceResponseData['name'],
                $instanceResponseData['privateDnsName'],
                $instanceResponseData['dnsName']
            );

            // If the instance is running then
            // attempt to assiciate the instance to the public ip
            if ($instanceResponseData['code'] == 16) {
                $this->_awsService->associateAddress($instanceRow->instanceName, $this->getState('publicIp'));

                $this->_profileTbl->updateStatus($this->getState('idProfile'), Siamgeo_Db_Table_Profile::PASSED_ASSOCIATED_ADDRESS);
                $this->setState('status', Siamgeo_Db_Table_Profile::PASSED_ASSOCIATED_ADDRESS);
            } else {
                if ($this->_logger)
                    $this->_logger->notice(__FILE__ . '(' . __LINE__ .') Profile: ' . sprintf("%06d", $this->getState('idProfile')) . ' is waiting for instance.  Instance is current in "' . $instanceResponseData['name'] . '" state.');
            }
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logContext();
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($this->debug) throw $e;
        }
    }

    public function generateTemplateFiles($customersDataDir)
    {
        try {
            $username = $this->_usernameLookup[$this->getState('idCustomer')];
            $this->_templateService->processTemplates($username, $this->getState('idProfile'), $customersDataDir);

            $this->_profileTbl->updateStatus($this->getState('idProfile'), Siamgeo_Db_Table_Profile::PASSED_GENERATED_DEPLOY_FILES);
            $this->setState('status', Siamgeo_Db_Table_Profile::PASSED_GENERATED_DEPLOY_FILES);
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logContext();
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($debug) throw $e;
        }
    }

    public function transferAndExcute($username, $publickey, $privatekey)
    {
        $instanceTbl = $this->getTable('Instance');
        
        try {
            $this->_deployer->setHost('ec2-54-251-48-107.ap-southeast-1.compute.amazonaws.com');
            $this->_deployer->setUsername($username);
            $this->_deployer->setPublicKey($publickey);
            $this->_deployer->setPrivateKey($privatekey);
            $this->_deployer->addTransfer('/var/www/japan.komodo-aws/payload.sh', '/home/ubuntu/payload.sh', 0744);
            $this->_deployer->addTransfer('/var/www/japan.komodo-aws/payload.sh', '/home/ubuntu/payload2.sh', 0744);
            $this->_deployer->addTransfer('/var/www/japan.komodo-aws/payload.sh', '/home/ubuntu/payload3.sh', 0724);
            $this->_deployer->addTransfer('/var/www/japan.komodo-aws/payload.sh', '/home/ubuntu/payload4.sh', 0745);
            $this->_deployer->addTransfer('/var/www/japan.komodo-aws/payload.sh', '/home/ubuntu/payload5.sh', 0746);
            $this->_deployer->scheduleFile('/home/ubuntu/payload.sh');
            $this->_deployer->sendFiles();
            $this->_deployer->executeSchedule();

            $this->_profileTbl->updateStatus($this->getState('idProfile'), Siamgeo_Db_Table_Profile::PASSED_EXECUTED_AND_SENT_FILES);
            $this->setState('status', Siamgeo_Db_Table_Profile::PASSED_EXECUTED_AND_SENT_FILES);
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logContext();
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($this->_debug) throw $e;
        }
    }

    public function complete()
    {
        if ($this->_logger) {
            $this->_logger->info(__FILE__ . '(' . __LINE__ .') Profile: ' . sprintf("%06d", $this->getState('idProfile') . ' is in "' . $this->getState('status') . '" status so we are done and marking the profile as complete'));
            $this->_profileTbl->updateStatus($this->getState('idProfile'), Siamgeo_Db_Table_Profile::COMPLETED);
            $this->setState('status', Siamgeo_Db_Table_Profile::COMPLETED);
        }
    }
}
