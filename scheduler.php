<?php
$startTime = time();

require_once 'setup.php';
require_once 'AwsSdk/sdk.class.php';
require_once 'AwsSdk/services/ec2.class.php';

$debug  = (bool) $config->debug;
$logger = $siamgeo['logger'];

// create the AWS SDK EC2 object to communicate with the AWS API
$ec2 = new AmazonEC2(array(
    'certificate_authority' => false,
    // 'credentials' => ''
    // 'default_cache_config' => 'apc'
    'default_cache_config' => '',
    'key'    => $config->ec2->key,
    'secret' => $config->ec2->secret
));

// initiate our custom made service and pass in the EC2 object
$awsService = new Siamgeo_Aws_Service($ec2, $config);
$templateEngine = new Siamgeo_Template_Engine($config->serverTemplateDir);
$profileConfigDataService = new Siamgeo_Service_ProfileConfigDataService(new Siamgeo_Db_Table_ProfileConfigData());
$deployer = new Siamgeo_Deploy($logger);

$templateService = new Siamgeo_Template_Service($templateEngine, $profileConfigDataService);
$templateService->setLogger($logger);

$customerService = new Siamgeo_Service_CustomerService(new Siamgeo_Db_Table_Customer());

$engine = Siamgeo_Scheduler_Engine::getInstance($awsService, $templateService, $deployer, $customerService);

if ($config->debug)
    $engine->setDebugMode(true);

if ($siamgeo['logger'])
    $engine->setLogger($siamgeo['logger']);

//var_dump($engine);

//die();

// get all profiles that still require processing
$profilesTable = new Siamgeo_Db_Table_Profile();
$rowset = $profilesTable->getAllNonCompletedProfiles();

if ($logger) $logger->info(__FILE__ . '(' . __LINE__ . ') +++++++++++++++++++++++$i+++++++++++++++ START ++++++++++++++++++++++++++++++++++++++');

if (sizeof($rowset) == 0) {
    if ($logger) $logger->info(__FILE__ . '(' . __LINE__ . ') Nothing to process in the Profiles table');
}



// loop through all the non-completed profiles for all customers
foreach ($rowset as $row) {
    $engine->setContext(array(
        'idProfile'    => $row->idProfile,
        'idCustomer'   => $row->idCustomer,
        'domainName'   => $row->domainName,
        'sslRequired'  => $row->sslRequired,
        'publicIp'     => $row->publicIp,
        'instanceType' => $row->instanceType,
        'regionName'   => $row->regionName,
        'status'       => $row->status,
        'metaStatus'     => $row->metaStatus
    ));

    // pending
    if ($engine->getCurrentStatus() == Siamgeo_Db_Table_Profile::PENDING) {
        try {
            $engine->allocateIpAddress();
        } catch (Exception $e) {
            if ($this->_logger) {
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($debug) throw $e;
        }
    }

    // allocate an ip address
    if ($engine->getCurrentStatus() == Siamgeo_Db_Table_Profile::PASSED_ALLOCATED_IP_ADDRESS) {
        try {
            $engine->createSecurityGroup();
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($debug) throw $e;
        }
    }

    // create a new key pair
    if ($engine->getCurrentStatus() == Siamgeo_Db_Table_Profile::PASSED_SECURITY_GROUP_CREATED) {
        try {
            $engine->createKeyPair();
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($debug) throw $e;
        }
    }

    // launch the instance
    if ($engine->getCurrentStatus() == Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED) {
        if ($logger)
            $logger->info(__FILE__ . '(' . __LINE__ .') Profile: ' . sprintf("%06d", $row->idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED . '" status so attempting to run instance');

        try {
            $engine->launchInstance($config->configInitFilepath);
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($debug) throw $e;
        }
    }

    // describe instances
    if ($engine->getCurrentStatus() == Siamgeo_Db_Table_Profile::PASSED_INSTANCE_STARTED) {
        if ($logger)
            $logger->info(__FILE__ . '(' . __LINE__ .') Profile: ' . sprintf("%06d", $row->idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_INSTANCE_STARTED . '" status so attempting to associate the public ip to the instance');

        try {
            $engine->describeInstances();
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($debug) throw $e;
        }
    }

    if ($engine->getCurrentStatus() == Siamgeo_Db_Table_Profile::PASSED_ASSOCIATED_ADDRESS) {
        try {
            $engine->generateTemplateFiles($config->customersDataDir);
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($debug) throw $e;
        }
    }

    if ($engine->getCurrentStatus() == Siamgeo_Db_Table_Profile::PASSED_GENERATED_DEPLOY_FILES) {
        try {
            $engine->transferAndExcute(
                $config->ssh->username,
                $config->ssh->publickey,
                $config->ssh->privatekey
            );
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $logger->emerg(__FILE__ . '(' . __LINE__ . ') ' . $e->getTraceAsString());
            }
            if ($debug) throw $e;
        }
    }

    if ($engine->getCurrentStatus() == Siamgeo_Db_Table_Profile::PASSED_EXECUTED_AND_SENT_FILES) {
        $engine->complete();
    }
}

$endTime = time();

$timeElapsed = $endTime - $startTime;
if ($logger) $logger->info(__FILE__ . '(' . __LINE__ .') Took ' . $timeElapsed . ' seconds to execute');
if ($logger) $logger->info(__FILE__ . '(' . __LINE__ .') ++++++++++++++++++++++++++++++++++++++ END ++++++++++++++++++++++++++++++++++++++ at ');
