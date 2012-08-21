<?php
class Application_Model_Customer
{
    private $idCustomer;
    private $username;
    private $firstname;


    public function setCustomerId($idCustomer)
    {
        $this->idCustomer = $idCustomer;
        return $this;
    }

    public function getCustomerId()
    {
        return $this->idCustomer;
    }

    public function setUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function setFirstname($firstname)
    {
        $this->firstname = $firstname;
        return $this;
    }

    public function getFirstname()
    {
        return $this->firstname;
    }
}

