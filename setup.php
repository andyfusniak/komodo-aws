<?php
define('APP_DIR', getcwd());

set_include_path('.:'. APP_DIR . '/library');

require_once 'Zend/Loader/Autoloader.php';
$siamgeo['loader'] = Zend_Loader_Autoloader::getInstance();
$siamgeo['loader']->registerNamespace('Siamgeo_');

$config = new Zend_Config_Ini('config.ini', 'japan');

$siamgeo['db'] = Zend_Db::factory('Pdo_Mysql', array(
    'host'      => $config->db->dbhost,
    'username'  => $config->db->dbuser,
    'password'  => $config->db->dbpass,
    'dbname'    => $config->db->dbname
));
Zend_Db_Table_Abstract::setDefaultAdapter($siamgeo['db']);

$siamgeo['logger'] = new Zend_Log(new Zend_Log_Writer_Stream($config->appLog));
