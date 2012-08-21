<?php
class Siamgeo_Aws_Service
{
    protected $_logger = null;
    protected $_region = null;
    protected $_username;
    protected $_idProfile;
    protected $_groupName = null;
    protected $_customersDataDir;
    protected $_regionMappings;
    protected $_amiName;
    protected $_ec2;

    private $_shortRegionToLongRegionMappings = array(
        'us-east-1'      => AmazonEC2::REGION_US_E1,
        'us-west-1'      => AmazonEC2::REGION_US_W1,
        'us-west-2'      => AmazonEC2::REGION_US_W2,
        'eu-west-1'      => AmazonEC2::REGION_EU_W1,
        'ap-southeast-1' => AmazonEC2::REGION_APAC_SE1,
        'ap-northeast-1' => AmazonEC2::REGION_APAC_NE1,
        'sa-east-1'      => AmazonEC2::REGION_SA_E1
    );

    public function __construct($ec2, $config)
    {
        $this->_ec2 = $ec2;
        $configProfile = $config['profile'];

        $this->_customersDataDir = $config[$configProfile]['siamgeo']['customersDataDir'];

        $currentAmiSet = $config[$configProfile]['siamgeo']['currentAmiSet'];
        $this->_regionMappings = $config[$configProfile]['siamgeo']['regionToAmiMappings'][$currentAmiSet];
    }

    public function setLogger($logger)
    {
        $this->_logger = $logger;
        return $this;
    }

    public function setContext($region, $username, $idProfile)
    {
        $this->_region = $region;
        $this->_username = $username;
        $this->_idProfile = (int) $idProfile;

        if (isset($this->_regionMappings[$region])) {
            $this->_amiName = $this->_regionMappings[$region];
        } else {
            if ($this->_logger) $this->_logger->emerg('Unknown region passed: ' . $region);
            throw new Exception('Unknown region ' . $region);
        }

        // create the group name in the format ackme-000002
        $this->_groupName = $username . '-' . sprintf("%06d", (int) $idProfile);

        if (isset($this->_shortRegionToLongRegionMappings[$region])) {
            $queueUrl = $this->_shortRegionToLongRegionMappings[$region];
        } else {
            if ($this->_logger) $this->_logger->emerg('Cannot find queue URL for region: ' . $region);
            throw new Exception('Cannot find queue URL for region ' . $region);
        }

        // set the region
        $this->_ec2->set_region($queueUrl);

        return $this;
    }

    public function allocateIpAddress()
    {
        // allocate a new ip addres
        $response = $this->_ec2->allocate_address();

        if ($response->isOK()) {
            return (string) $response->body->publicIp;
        } else {
            if ($this->_logger) $this->_logger->emerg('failed to allocate an ip');
            throw new Exception('failed to allocate an ip');
        }

        return $this;
    }

    public function createSecurityGroup($includeSsl = false)
    {
        if ((null === $this->_region) || (null === $this->_groupName)) {
            if ($this->_logger) $this->_logger->emerg('Failed to call setContext before calling createSecurityGroup');

            throw new Exception('You must call setContext first before calling this function');
        }

        if ($this->_logger) $this->_logger->info('create a security group for ' );

        // create a security group for this profile
        $response = $this->_ec2->create_security_group($this->_groupName, $this->_groupName);
        if ($response->isOK()) {
            $groupId = (string) $response->body->groupId;
            $requestId = (string) $response->body->requestId;
        }

        // open SSH (Port 22) and HTTP (Port 80)
        $securityGroupOptions = array(
            'GroupId' => $groupId,
            'IpPermissions' => array(
                array(
                    'IpProtocol' => 'tcp',
                    'FromPort' => '22',
                    'ToPort' => '22',
                    'IpRanges' => array(
                        array('CidrIp' => '0.0.0.0/0'),
                    )
                ),
                array(
                    'IpProtocol' => 'tcp',
                    'FromPort' => '80',
                    'ToPort' => '80',
                    'IpRanges' => array(
                        array('CidrIp' => '0.0.0.0/0'),
                    )
                )
            )
        );

        if ($includeSsl) {
            // open SSL (Port 443)
            array_push($securityGroupOptions['IpPermissions'], array(
                'IpProtocol' => 'tcp',
                'FromPort' => '443',
                'ToPort' => '443',
                'IpRanges' => array(
                    array('CidrIp' => '0.0.0.0/0'),
                )
            ));
        }

        $response = $this->_ec2->authorize_security_group_ingress($securityGroupOptions);
        if (!$response->isOK()) {
            if ($this->_logger) $this->_logger->emerg('authorize_security_group_ingress failed');
            throw new Exception("Response not OK");
        }
    }

    public function createKeyPair()
    {
        // Create a Key Pair and write the .pem file to the file system
        $response = $this->_ec2->create_key_pair($this->_groupName);
        if ($response->isOK()) {
            if (isset($response->body->keyMaterial)) {
                $usernameDir = $this->_customersDataDir . DIRECTORY_SEPARATOR . $this->_username;

                if (!file_exists($usernameDir)) {
                    mkdir($usernameDir);
                    chmod($usernameDir, 0777);
                }

                $profileDir = $usernameDir . DIRECTORY_SEPARATOR . sprintf("%06d", $this->_idProfile);
                if (!file_exists($profileDir)) {
                    mkdir($profileDir);
                    chmod($profileDir, 0777);
                }

                $keyName = (string) $response->body->keyName;
                $fullpath = $profileDir . DIRECTORY_SEPARATOR . $keyName . '.pem';

                // write the file to file system e.g customers/ackme/000001/ackme-000001.pem
                if (!file_put_contents($fullpath, (string) $response->body->keyMaterial, LOCK_EX)) {
                    if ($this->_logger) $this->_logger->alert('Failed to write the ' . $keyName . ' file to the filesystem path = ' . $fullpath);

                    throw new Exception('Failed to write the ' . $keyName . ' file to the filesystem path = ' . $fullpath);
                }
            }
        } else {
            if ($this->_logger) $this->_logger->emerg('call to create_key_pair failed');
            throw new Exception("Response not OK");
        }
    }

    public function runInstances($instanceType)
    {
        // Run an instance
        if ($this->_logger) $this->_logger->debug(__CLASS__ . ' - Attempting to start ec2 instance with ami=' . $this->_amiName .', instanceType=' . $instanceType);

        $response = $this->_ec2->run_instances($this->_amiName, 1, 1, array(
            'KeyName'            => $this->_groupName,
            'SecurityGroup'      => $this->_groupName,
            'InstanceType'       => $instanceType,
            'Monitoring.Enabled' => false,
            'DisableApiTermination' => false
        ));

        if (!$response->isOK()) {
            if ($this->_logger) $this->_logger->emerg('call to run_instances failed');

            throw new Exception("Response not OK");
        }

        $body = $response->body;

        $instanceData = array(
            'instanceType'      => (string) $body->instancesSet->item->instanceType,
            'instanceName'      => (string) $body->instancesSet->item->instanceId,
            'amiName'           => (string) $body->instancesSet->item->imageId,
            'akiName'           => (string) $body->instancesSet->item->kernelId,
            'availabilityZone'  => (string) $body->instancesSet->item->placement->availabilityZone,
            'reservationId'     => (string) $body->reservationId,
            'ownerId'           => (string) $body->ownerId,
            'launchTime'        => (string) $body->instancesSet->item->launchTime,
            'instanceStateCode' => (string) $body->instancesSet->item->instanceState->code,
            'instanceStateName' => (string) $body->instancesSet->item->instanceState->name,
            'reservationId' => (string) $response->body->reservationId,
        );

        return $instanceData;
    }

    public function describeInstances($instanceName)
    {
        if ($this->_logger) $this->_logger->debug(__CLASS__ . ' - Attempting to describe_instance with instanceName=' . $instanceName);
        $response = $this->_ec2->describe_instances(array(
            'InstanceId' => $instanceName,
        ));

        if (!$response->isOK()) {
            if ($this->_logger) $this->_logger->emerg('call to describe_instances failed');

            throw new Exception("Response not OK");
        }

        $code           = (string) $response->body->reservationSet->item->instancesSet->item->instanceState->code;
        $name           = (string) $response->body->reservationSet->item->instancesSet->item->instanceState->name;
        $privateDnsName = (string) $response->body->reservationSet->item->instancesSet->item->privateDnsName;
        $dnsName        = (string) (string) $response->body->reservationSet->item->instancesSet->item->dnsName;

        if ($this->_logger) $this->_logger->debug(__CLASS__ . ' - describe_instance returned code=' . $code . ', name=' . $name . ', privateDnsName=' . $privateDnsName . ', dnsName=' . $dnsName);

        return array(
            'code'           => $code,
            'name'           => $name,
            'privateDnsName' => $privateDnsName,
            'dnsName'        => $dnsName
        );
    }

    public function associateAddress($instanceName, $publicIp)
    {
        $response = $this->_ec2->associate_address($instanceName, $publicIp);
        if (!$response->isOK()) {
            if ($this->_logger) $this->_logger->emerg('call to associate_address failed');

            throw new Exception("Response not OK");
        }
    }
}
