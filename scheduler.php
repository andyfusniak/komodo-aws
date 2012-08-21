<?php
require_once 'config.php';
require_once 'AwsSdk/sdk.class.php';
require_once 'AwsSdk/services/ec2.class.php';

$configProfile = $config['profile'];

// get the Zend logger (predifined in the config setup)
$logger = $config[$configProfile]['siamgeo']['zendlogger'];
$debug  = $config[$configProfile]['siamgeo']['debug'];

// create the AWS SDK EC2 object to communicate with the AWS API
$ec2 = new AmazonEC2(array(
    'certificate_authority' => false,
    // 'credentials' => ''
    // 'default_cache_config' => 'apc'
    'default_cache_config' => '',
    'key'    => $config[$configProfile]['ec2']['key'],
    'secret' => $config[$configProfile]['ec2']['secret']
));

// initiate our custom made service and pass in the EC2 object
$siamGeoAwsService = new Siamgeo_Aws_Service($ec2, $config);

$siamGeoAwsService->setLogger($logger);

// get all profiles that still require processing
$profilesTable = new Siamgeo_Db_Table_Profile();
$rowset = $profilesTable->getAllNonCompletedProfiles();

$customersTable = new Siamgeo_Db_Table_Customer();

$instanceTable = null;

// loop through all the non-completed profiles for all customers
foreach ($rowset as $row) {
    $status = $row->status;

    $customerRow = $customersTable->getCustomerByCustomerId($row->idCustomer);
    $siamGeoAwsService->setContext($row->regionName, $customerRow->username, $row->idProfile);

    //
    // pending
    //
    if (Siamgeo_Db_Table_Profile::PENDING == $status) {
        if ($logger) $logger->info(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PENDING . '" status so attempting to allocate ip address');

        try {
            $publicIp = $siamGeoAwsService->allocateIpAddress();

            $profilesTable->updatePublicIp($row->idProfile, $publicIp);
            $profilesTable->updateStatus($row->idProfile,
                Siamgeo_Db_Table_Profile::PASSED_ALLOCATED_IP_ADDRESS
            );

            $status = Siamgeo_Db_Table_Profile::PASSED_ALLOCATED_IP_ADDRESS;
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) .  ' failed to allocate ip address');
                $logger->debug(__FILE__ . ' - ' . $e->getMessage());
            }
            if ($debug) throw $e;
        }
    }

    //
    // allocate an ip address
    //
    if (Siamgeo_Db_Table_Profile::PASSED_ALLOCATED_IP_ADDRESS == $status) {
        $logger->info(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_ALLOCATED_IP_ADDRESS . '" status so attempting to create a new security group');

        try {
            $includeSsl = ($row->sslRequired == 'Y') ? true : false;
            $siamGeoAwsService->createSecurityGroup($includeSsl);

            $profilesTable->updateStatus($row->idProfile,
                Siamgeo_Db_Table_Profile::PASSED_SECURITY_GROUP_CREATED
            );

            $status = Siamgeo_Db_Table_Profile::PASSED_SECURITY_GROUP_CREATED;
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) .  ' failed to create a new security group');
                $logger->debug(__FILE__ . ' - ' . $e->getMessage());
            }
            if ($debug) throw $e;
        }
    }

    //
    // create a new key pair
    //
    if (Siamgeo_Db_Table_Profile::PASSED_SECURITY_GROUP_CREATED == $status) {
        $logger->info(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_SECURITY_GROUP_CREATED . '" status so attempting to create a new key pair');

        try {
            $siamGeoAwsService->createKeyPair();

            $profilesTable->updateStatus($row->idProfile,
                Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED
            );

            $status = Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED;
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) .  ' failed to create a new key pair');
                $logger->debug(__FILE__ . ' - ' . $e->getMessage());
            }
            if ($debug) throw $e;
        }
    }

    //
    // launch the instance
    //
    if (Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED == $status) {
        $logger->info(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_KEY_PAIR_GENERATED . '" status so attempting to run instance');

        try {
            // keep a local copy of the table gateway object
            if (null === $instanceTable)
                $instanceTable = new Siamgeo_Db_Table_Instance();

            $instanceData = $siamGeoAwsService->runInstances($row->instanceType);
            $instanceData['regionName'] = $row->regionName;

            $instanceTable->addInstance($row->idProfile, $instanceData);

            $profilesTable->updateStatus($row->idProfile,
                Siamgeo_Db_Table_Profile::PASSED_INSTANCE_STARTED
            );
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) .  ' failed to run instance');
                $logger->debug(__FILE__ . ' - ' . $e->getMessage());
            }
            if ($debug) throw $e;
        }
    }

    //
    // complete
    //
    if (Siamgeo_Db_Table_Profile::PASSED_INSTANCE_STARTED == $status) {
        $logger->info(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_INSTANCE_STARTED . '" status so attempting to associate the public ip to the instance');

        try {
            // keep a local copy of the table gateway object
            if (null === $instanceTable)
                $instanceTable = new Siamgeo_Db_Table_Instance();

            // lookup the running instance the instance name will be format of 'i-1234656'
            $instanceRow = $instanceTable->getActiveInstanceByProfileId($row->idProfile);

            // check to make sure the instance is in the running state before
            // attempting to associate the public ip to the instance name
            $instanceResponseData = $siamGeoAwsService->describeInstances($instanceRow->instanceName);

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
                $siamGeoAwsService->associateAddress($instanceRow->instanceName, $row->publicIp);

                // if we reach here we were successful, so update the status
                $profilesTable->updateStatus($row->idProfile,
                    Siamgeo_Db_Table_Profile::PASSED_ASSOCIATED_ADDRESS
                );

                // drop through to the condition below to complete
                $status = Siamgeo_Db_Table_Profile::PASSED_ASSOCIATED_ADDRESS;
            } else {
                if ($logger) $logger->notice(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) . ' is waiting for instance.  Instance is current in "' . $instanceResponseData['name'] . '" state.');
            }
        } catch (Exception $e) {
            if ($logger) {
                $logger->emerg(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) .  ' failed to describe instance');
                $logger->debug(__FILE__ . ' - ' . $e->getMessage());
            }
            if ($debug) throw $e;
        }
    }

    if (Siamgeo_Db_Table_Profile::PASSED_ASSOCIATED_ADDRESS == $status) {
        $logger->info(__FILE__ . ' - ' . 'Profile: ' . sprintf("%06d", $row->idProfile) . ' is in "' . Siamgeo_Db_Table_Profile::PASSED_ASSOCIATED_ADDRESS . '" status so we are done and marking the profile as complete');

        $profilesTable->updateStatus($row->idProfile,
            Siamgeo_Db_Table_Profile::COMPLETED
        );
    }
}
