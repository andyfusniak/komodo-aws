<?php
class Siamgeo_Service_ProfileConfigDataService
{
    private $_profileConfigDataTable;

    public function __construct(Siamgeo_Db_Table_ProfileConfigData $profileConfigDataTable)
    {
        $this->_profileConfigDataTable = $profileConfigDataTable;
    }

    public function setConfig($idProfile, $name, $value, $groupName = null)
    {
        $row = $this->_profileConfigDataTable->getProfileConfigDataByName($idProfile, $name);

        if (null === $row) {
            $this->_profileConfigDataTable->addProfileConfigData($idProfile, $name, $value, $groupName);
        } else {
            $this->_profileConfigDataTable->updateProfileConfigData($idProfile, $name, $value, $groupName);
        }

        return $this;
    }

    public function setConfigList($idProfile, $list, $groupName = null)
    {
        foreach ($list as $name=>$value) {
            $this->setConfig($idProfile, $name, $value, $groupName);
        }

        return $this;
    }

    /**
     * @param int $idProfile the profile id
     * @return array an associative array of name/value pairs for this profile
     */
    public function getConfigList($idProfile, $groupName = null)
    {
        $hashMap = array();

        $rowset = $this->_profileConfigDataTable->getAllProfileConfigDataByProfileId($idProfile, $groupName);
        foreach ($rowset as $row) {
            $hashMap[$row->name] = $row->value;
        }

        return $hashMap;
    }
}
