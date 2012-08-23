<?php
class Siamgeo_Template_Service
{
    private $_logger;

    private $_templateEngine;
    private $_profileConfigDataService;

    public function __construct(Siamgeo_Template_Engine $templateEngine, Siamgeo_Service_ProfileConfigDataService $profileConfigDataService)
    {
        $this->_templateEngine = $templateEngine;
        $this->_profileConfigDataService = $profileConfigDataService;
    }

    public function setLogger($logger)
    {
        $this->_logger = $logger;
        return $this;
    }

    private function logHash($name, $hash)
    {
        if ($this->_logger) {
            foreach ($hash as $n=>$v) {
                $this->_logger->debug(__FILE__ . '(' . __LINE__ . ') $' . $name . ' ' . $n . '=' . $v);
            }
        }
    }

    public function processTemplates($username, $idProfile, $customersDataDir)
    {
        $this->_templateEngine->loadTemplate('apache-vhost-template.txt');
        $this->_templateEngine->loadTemplate('setup-server.txt');
        $this->_templateEngine->loadTemplate('cloud-init.txt');

        $apacheConfig = $this->_profileConfigDataService->getConfigList($idProfile, 'apache');
        $setupAndMagentoConfig = $this->_profileConfigDataService->getConfigList($idProfile,
            array('setup', 'magento')
        );
        $this->logHash('apacheConfig', $apacheConfig);
        $this->logHash('setupAndMagentoConfig', $setupAndMagentoConfig);

        // fill the templates with the values from the model
        $this->_templateEngine->fillTemplate('apache-vhost-template.txt', $apacheConfig);
        $this->_templateEngine->fillTemplate('setup-server.txt', $setupAndMagentoConfig);


        $this->_templateEngine->setCustomerDataDirectory($customersDataDir);
        $this->_templateEngine->setContext($username, $idProfile);
        $this->_templateEngine->saveTemplate('apache-vhost-template.txt', $apacheConfig['server-name']);
        $this->_templateEngine->saveTemplate('setup-server.txt', 'server-setup.sh');
        $this->_templateEngine->saveTemplate('cloud-init.txt', 'cloud-init.txt');

    }
}
