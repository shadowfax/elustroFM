<?php

include_once 'Server/Request.php';
include_once 'Server/Response.php';

class Json_Server
{
	/**#@+
     * Version Constants
     */
    const VERSION_1 = '1.0';
    const VERSION_2 = '2.0';
    /**#@-*/
    
    /**
     * Request object
     * @var Json_Server_Request
     */
    protected $_request;
    
    protected $_response;
    
    
    public function __construct()
    {
    	// Sanity checks
    	if (strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') !== 0) {
			// Bad request
			header("HTTP/1.1 405 Method Not Allowed");
			die("<html><head><title>Method Not Allowed</title></head><body><h1>Method Not Allowed</h1></body></html>");
		}  elseif ((empty($_SERVER['CONTENT_TYPE'])) || (strcasecmp($_SERVER['CONTENT_TYPE'], 'application/json') !== 0)) {
			header("HTTP/1.1 400 Bad Request");
			die("<html><head><title>Bad Request</title></head><body><h1>Bad Request</h1></body></html>");
		}
		
		$json = file_get_contents('php://input');
        
		if (!empty($json)) {
			$json_array = @json_decode($json, true);
			if (!$json_array) {
			
			}
			
			$this->_request = new Json_Server_Request();
			$this->_request->setOptions($json_array);
			
			$methodName = $this->_request->getMethod() . "Action";
			
			if (method_exists($this, strtolower($this->_request->getMethod() . "Action")))
			{	
				// Initialize the server before calling the method
				if (method_exists($this, 'init')) {
					$this->init();
				}
				
				// Call the method
				$result = $this->{$methodName}();
				
				// forge the JSON RPC Response
				$this->_response = new Json_Server_Response();
				$this->_response->setVersion($this->_request->getVersion());
				$this->_response->setId($this->_request->getId());
				$this->_response->setResult($result);
			}
			
        } else {
        	header("HTTP/1.1 400 Bad Request");
			die("<html><head><title>Bad Request</title></head><body><h1>Bad Request</h1></body></html>");
        }
        
        // dispatch
        $this->dispatch();
    }
    
    public function dispatch()
    {
    	if(!headers_sent()) {
    		header("Content-Type: application/json");
    		header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
			header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
			header("Cache-Control: no-store, no-cache, must-revalidate");
			header("Cache-Control: post-check=0, pre-check=0", false);
			header("Pragma: no-cache");
    	}
    	
    	echo $this->_response->toJson();
    }
    
	public function getResponse()
	{
		if (null === $this->_response) {
			$this->_response = new Json_Server_Response();
			$this->_response->setVersion($this->_request->getVersion());
			$this->_response->setId($this->_request->getId());
		}
		
		return $this->_response;
	}
    
    public function getRequest()
    {
    	return $this->_request;
    }
}