<?php
require_once 'config.php';

$customerTable = new Siamgeo_Db_Table_Customer();

//$customerTable->updateCustomerByCustomerId(4, array(
//    'firstname' => 'Jon'
//));

//$obj = $customerTable->getCustomerByCustomerId(4);

//$obj->delete();

//var_dump($obj->idCustomer, $obj->username, $obj->firstname);


// add a new customer
try {
    $rowId = $customerTable->addCustomer(array(
        'username'  => 'johndepp',
        'firstname' => 'Johny'
    ));

    echo "The new row was " . $rowId . PHP_EOL;
} catch (Exception $e) {
   echo "I couldn't add a new customer :(";
}


//$profileTable = new Siamgeo_Db_Table_Profile();
//$rowset = $profileTable->getAllProfilesByCustomerId(1);

//foreach ($rowset as $row) {
//    echo "Profile Id: " . $row->idProfile . PHP_EOL;
//}

/*

$instanceTable = new Siamgeo_Db_Table_Instance();
$obj = $instanceTable->getInstanceByInstanceName('i-123456');

if (null === $obj) {
    echo "Couldn't find the instance";
    exit;
}

echo $obj->regionName;
*/