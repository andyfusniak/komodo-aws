<?php
set_include_path(
    '.'
    . ':./library'
);

require_once 'Zend/Loader/Autoloader.php';
$loader = Zend_Loader_Autoloader::getInstance();
$loader->registerNamespace('Siamgeo_');

$config['profile'] = 'japan';  // change to 'live', 'japan', 'beta' etc

$config['japan']['siamgeo'] = array(
    'appdir' => '/var/www/japan.komodo-aws',
    'applog' => '/var/www/japan.komodo-aws/data/logs/siamgeo.log',
    'customersDataDir' => '/var/www/japan.komodo-aws/data/customers',
    'debug' => true
);

$config['japan']['ec2'] = array(
    'key'    => 'AKIAJKJFVF55XL3CPDWQ',
    'secret' => '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'
);

$config['japan']['siamgeo']['currentAmiSet'] = '12-04';

$config['japan']['siamgeo']['regionToAmiMappings'] = array(
    '12-04' => array(
        'eu-west-1'      => 'ami-e1e8d395',
        'sa-east-1'      => 'ami-8cd80691',
        'us-east-1'      => 'ami-a29943cb',
        'ap-northeast-1' => 'ami-60c77761',
        'us-west-2'      => 'ami-20800c10',
        'us-west-1'      => 'ami-87712ac2',
        'ap-southeast-1' => 'ami-a4ca8df6'
    )
);

$db = Zend_Db::factory('Pdo_Mysql', array(
    'host'             => 'localhost',
    'username'         => 'root',
    'password'         => 'mysql',
    'dbname'           => 'komodo-aws'
));
Zend_Db_Table_Abstract::setDefaultAdapter($db);

$config['japan']['siamgeo']['zendlogger'] = new Zend_Log(
    new Zend_Log_Writer_Stream($config['japan']['siamgeo']['applog'])
);
