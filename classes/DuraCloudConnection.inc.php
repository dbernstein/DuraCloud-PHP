<?php

/**
 * @defgroup duracloud_classes
 */

/**
 * @file classes/DuraCloudConnection.inc.php
 *
 * Copyright (c) 2011 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class DuraCloudConnection
 * @ingroup duracloud_classes
 *
 * @brief DuraCloud Connection class
 */

class DuraCloudConnection {
	/** @var $baseUrl string */
	var $baseUrl;

	/** @var $username string */
	var $username;

	/** @var $password string */
	var $password;

	/** @var $headers string */
	var $headers;

	/** @var $data string */
	var $data;

	/**
	 * Construct a new DuraCloudConnection.
	 * @param $baseUrl Base URL to DuraCloud, i.e. https://pkp.duracloud.org
	 * @param $username Username
	 * @param $password Password
	 */
	function DuraCloudConnection($baseUrl, $username, $password) {
		$this->baseUrl = $baseUrl;
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * Execute a GET request to DuraCloud. Not for external use.
	 * @param $path string
	 * @param $params array
	 * @return string data
	 */
	function get($path, $params = array()) {
		$ch =& $this->_curlOpenHandle($this->username, $this->password);
		if (!$ch) return false;

		curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/' . $path . $this->_buildUrlVars($params));
		list($this->headers, $this->data) = $this->_separateHeadersFromData(curl_exec($ch));

		curl_close($ch);
		return $this->data;
	}

	/**
	 * Execute a HEAD request to DuraCloud. Not for external use.
	 * @param $path string
	 * @param $params array
	 * @return array headers
	 */
	function head($path, $params = array()) {
		$ch =& $this->_curlOpenHandle($this->username, $this->password);
		if (!$ch) return false;

		curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/' . $path . $this->_buildUrlVars($params));
		curl_setopt($ch, CURLOPT_NOBODY, 1);

		list($this->headers, $this->data) = $this->_separateHeadersFromData(curl_exec($ch));

		curl_close($ch);
		return $this->getHeaders();
	}

	/**
	 * Execute a POST request to DuraCloud. Not for external use.
	 * @param $path string
	 * @param $params array Associative array of POST parameters
	 */
	function post($path, $params = array()) {
		$ch =& $this->_curlOpenHandle($this->username, $this->password);
		if (!$ch) return false;

		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/' . $path);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		list($this->headers, $this->data) = $this->_separateHeadersFromData(curl_exec($ch));

		curl_close($ch);
		return $this->data;
	}

	/**
	 * Execute a PUT request to DuraCloud. Not for external use.
	 * @param $path string
	 * @param $params array Associative array of URL parameters
	 * @param $headers array Associative array of HTTP headers
	 */
	function put($path, $fp = null, $size = 0, $params = array(), $headers = array()) {
		$ch =& $this->_curlOpenHandle($this->username, $this->password);
		if (!$ch) return false;

		curl_setopt($ch, CURLOPT_PUT, 1);
		curl_setopt($ch, CURLOPT_INFILESIZE, $size);

		$headerList = array();
		foreach ($headers as $name => $value) {
			$headerList[] = "$name: $value";
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headerList);

		if ($fp) curl_setopt($ch, CURLOPT_INFILE, $fp);
		curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/' . $path . $this->_buildUrlVars($params));

		list($this->headers, $this->data) = $this->_separateHeadersFromData(curl_exec($ch));

		curl_close($ch);
		return $this->getHeaders();
	}

	/**
	 * Return the data resulting from the last successful operation
	 * @return string
	 */
	function getData() {
		return $this->data;
	}

	/**
	 * Return the headers resulting from the last successful operation
	 * @return array
	 */
	function getHeaders() {
		// First, split the header chunk into lines
		$lines = explode("\r\n", $this->headers);

		// Remove the response line and treat it specially
		$response = array_shift($lines);
		$returner = array('response' => $response);

		// For the rest of the lines, split into associative array
		foreach ($lines as $line) {
			$i = strpos($line, ':');
			$returner[trim(substr($line, 0, $i))] = trim(substr($line, $i+2));
		}
		return $returner;
	}

	//
	// The following are STATIC functions. They are not declared static for
	// the sake of PHP4 compatibility. Not for external use.
	//


	//
	// cURL / REST-related functions.
	//

	/**
	 * Open a cURL handle. Not for external use.
	 * @param $username string
	 * @param $password string
	 * @return object
	 */
	function &_curlOpenHandle($username, $password) {
		// Check to see whether or not cURL support is installed
		if (!function_exists('curl_init')) {
			$ch = false;
			return $ch;
		}

		// Initialize the cURL handle object, if possible.
		$ch = curl_init();

		if ($ch) {
			// Set common cURL options
			curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
			curl_setopt($ch, CURLOPT_FAILONERROR, 1);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_HEADER, 1);
			curl_setopt($ch, CURLOPT_USERAGENT, 'DuraCloud-PHP ' . DURACLOUD_PHP_VERSION); 
			curl_setopt($ch, CURLOPT_SSLVERSION, 3);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		}
		return $ch;
	}

	/**
	 * Used internally to take a response and split it into headers and response data.
	 * @param $response string
	 * @return array (headers, data) iff both headers and data were found; otherwise string data
	 */
	function _separateHeadersFromData($response) {
		$separator = "\r\n\r\n";
		$i = strpos($response, $separator);
		if (!$i) return $response; // If no separator was found, it's all data
		return array(
			substr($response, 0, $i),
			substr($response, $i+strlen($separator))
		);
	}

	/**
	 * Used internally to build a portion of a URL describing variables (from the '?' onwards).
	 * @param $array
	 * @return string
	 */
	function _buildUrlVars($urlVars) {
		$returner = '';
		foreach ($urlVars as $name => $value) {
			if (!empty($returner)) $returner .= '&';
			$returner .= urlencode($name) . '=' . urlencode($value);
		}
		if ($returner !== '') $returner = '?' . $returner;
		return $returner;
	}
}

?>
