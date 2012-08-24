<?php
define('APP_DIR', getcwd());

define('APPLICATION_ENV', getenv('APPLICATION_ENV'));

if (!APPLICATION_ENV) {
    echo "You must set the environment variable APPLICATION_ENV using export APPLICATION_ENV=<config-profile> where <config-profile> matches the profile in the config.ini file. e.g. japan, live or beta" . PHP_EOL;
    die();
}

set_include_path('.:'. APP_DIR . '/library');

require_once 'Zend/Loader/Autoloader.php';
$siamgeo['loader'] = Zend_Loader_Autoloader::getInstance();
$siamgeo['loader']->registerNamespace('Siamgeo_');

$config = new Zend_Config_Ini('config.ini', APPLICATION_ENV);
$siamgeo['db'] = Zend_Db::factory('Pdo_Mysql', array(
    'host'      => $config->db->dbhost,
    'username'  => $config->db->dbuser,
    'password'  => $config->db->dbpass,
    'dbname'    => $config->db->dbname
));
Zend_Db_Table_Abstract::setDefaultAdapter($siamgeo['db']);

$siamgeo['logger'] = new Zend_Log(new Zend_Log_Writer_Stream($config->appLog));
