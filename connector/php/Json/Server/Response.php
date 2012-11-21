<?php

include_once 'Error.php';

class Json_Server_Response
{
    /**
     * Response error
     * @var null|Json_Server_Error
     */
    protected $_error;

    /**
     * Request ID
     * @var mixed
     */
    protected $_id;

    /**
     * Result
     * @var mixed
     */
    protected $_result;

    /**
     * JSON-RPC version
     * @var string
     */
    protected $_version;

    /**
     * Set result
     *
     * @param  mixed $value
     * @return Zend_Json_Server_Response
     */
    public function setResult($value)
    {
        $this->_result = $value;
        return $this;
    }

    /**
     * Get result
     *
     * @return mixed
     */
    public function getResult()
    {
        return $this->_result;
    }

    // RPC error, if response results in fault
    /**
     * Set result error
     *
     * @param  Zend_Json_Server_Error $error
     * @return Zend_Json_Server_Response
     */
    public function setError(Json_Server_Error $error)
    {
        $this->_error = $error;
        return $this;
    }

    /**
     * Get response error
     *
     * @return null|Zend_Json_Server_Error
     */
    public function getError()
    {
        return $this->_error;
    }

    /**
     * Is the response an error?
     *
     * @return bool
     */
    public function isError()
    {
        return $this->getError() instanceof Zend_Json_Server_Error;
    }

    /**
     * Set request ID
     *
     * @param  mixed $name
     * @return Zend_Json_Server_Response
     */
    public function setId($name)
    {
        $this->_id = $name;
        return $this;
    }

    /**
     * Get request ID
     *
     * @return mixed
     */
    public function getId()
    {
        return $this->_id;
    }

    /**
     * Set JSON-RPC version
     *
     * @param  string $version
     * @return Zend_Json_Server_Response
     */
    public function setVersion($version)
    {
        $version = is_array($version)
            ? implode(' ', $version)
            : $version;
        if ((string)$version == '2.0') {
            $this->_version = '2.0';
        } else {
            $this->_version = null;
        }
        return $this;
    }

    /**
     * Retrieve JSON-RPC version
     *
     * @return string
     */
    public function getVersion()
    {
        return $this->_version;
    }

    /**
     * Cast to JSON
     *
     * @return string
     */
    public function toJson()
    {
        if ($this->_error instanceof Json_Server_Error) {
            $response = array(
                'error'  => $this->getError()->toArray(),
                'id'     => $this->getId(),
            );
        } else {
            $response = array(
                'result' => $this->getResult(),
                'id'     => $this->getId(),
            );
        }

        if (null !== ($version = $this->getVersion())) {
            $response['jsonrpc'] = $version;
        }

        return json_encode($response);
    }

        
    /**
     * Cast to string (JSON)
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }
    
    
    public function sendResponse()
    {
    	if(!headers_sent()) {
    		header("Content-Type: application/json");
    		header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
			header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
			header("Cache-Control: no-store, no-cache, must-revalidate");
			header("Cache-Control: post-check=0, pre-check=0", false);
			header("Pragma: no-cache");
    	}
    	
    	echo $this->toJson();
    	exit(0);
    }
}

