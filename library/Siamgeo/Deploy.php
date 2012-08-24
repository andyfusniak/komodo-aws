<?php
class Siamgeo_Deploy
{
    /**
     * @var Zend_Log
     */
    private $_logger = null;

    private $_sshConnection = null;

    private $_host = null;
    private $_port = 22;
    private $_methods = array(
        'hostkey' => 'ssh-rsa'
    );

    private $_username = null;
    private $_publickey = null;
    private $_privatekey = null;

    private $_transfers = array();
    private $_schedule = array();

    private $_output = array();

    public function __construct($logger = null)
    {
        $this->_logger = $logger;
    }

    private function connect()
    {
        $this->_sshConnection = ssh2_connect($this->_host, $this->_port, $this->_methods);

        if (!$this->_sshConnection) {
            if ($this->_logger)
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') SSH Failed to connect to host=' . $this->_host . ', port=' . $this->_port);
        }
    }

    private function auth()
    {
        if (!ssh2_auth_pubkey_file($this->_sshConnection, $this->_username, $this->_publickey, $this->_privatekey)) {
            if ($this->_logger)
                $this->_logger->emerg(__FILE__ . '(' . __LINE__ . ') SSH Authorization failed for username=' . $this->_username . ', publickey='
                                      . $this->_publickey . ', privatekey=' . $this->_privatekey);
        }
    }

    public function setHost($host)
    {
        $this->_host = $host;
        return $this;
    }

    public function getHost()
    {
        return $this->_host;
    }

    public function setUsername($username)
    {
        $this->_username = $username;
        return $this;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function setPublicKey($publickey)
    {
        $this->_publickey = $publickey;
        return $this;
    }

    public function getPublicKey()
    {
        return $this->_publickey;
    }

    public function setPrivateKey($privatekey)
    {
        $this->_privatekey = $privatekey;
        return $this;
    }

    public function getPrivateKey()
    {
        return $this->_privatekey;
    }

    public function addTransfer($sourceFile, $destFile, $mode)
    {
        if (!file_exists($sourceFile)) {
            throw new Exception("Source file " . $sourceFile . " could not be found");
        }

        array_push($this->_transfers, array(
            'source' => $sourceFile,
            'dest'   => $destFile,
            'mode'   => $mode
        ));

        return $this;
    }

    public function scheduleFile($remoteFile)
    {
        array_push($this->_schedule, $remoteFile);
        return $this;
    }

    public function getSchedule()
    {
        return $this->_schedule;
    }

    public function sendFiles()
    {
        $this->connect();
        $this->auth();

        foreach($this->_transfers as $transfer) {
            if (!ssh2_scp_send($this->_sshConnection, $transfer['source'], $transfer['dest'], $transfer['mode'])) {
                if ($this->_logger) {
                    $this->_logger->emerg(
                        __FILE__ . '(' . __LINE__ . ') Failed to deploy file '
                        . $transfer['source'] . ' to remote destination file ' . $transfer['dest']
                        . ' with file mode ' . decoct($transfer['mode'])
                    );
                }
            }
        }

        return $this;
    }

    public function resetOutput()
    {
        foreach ($this->_output as $name=>$output) {
            $this->_output[$name] = '';
        }
    }

    public function getOutput($remoteFile = null)
    {
        if ($remoteFile)
            return $this->_output[$remoteFile];

        return $this->_output;
    }

    public function saveOutput($remoteFile, $outFile)
    {
        $output = $this->getOutput($remoteFile);

        if (!file_put_contents($outFile, $output, LOCK_EX)) {
            throw new Exception('Failed to write ' . $remoteFile . ' to ' . $outFile);
        }
    }

    public function executeSchedule()
    {
        foreach ($this->_schedule as $remoteFile) {
            $stream = ssh2_exec($this->_sshConnection, $remoteFile);

            // force PHP to wait for the output
            stream_set_blocking($stream, true);

            $this->_output[$remoteFile] = stream_get_contents($stream);
        }

        if ($this->_schedule)
            @fclose($stream);

        return $this;
    }

}
