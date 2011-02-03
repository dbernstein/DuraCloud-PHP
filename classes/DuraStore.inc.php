<?php

/**
 * @file classes/DuraStore.inc.php
 *
 * Copyright (c) 2011 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class DuraStore
 * @ingroup duracloud_classes
 *
 * @brief DuraStore client implementation
 */

//
// DuraStore standard metadata element names
//
define('DURACLOUD_SPACE_ACCESS', 'space-access');
define('DURACLOUD_SPACE_ACCESS_OPEN', 'OPEN');
define('DURACLOUD_SPACE_ACCESS_CLOSED', 'CLOSED');
define('DURACLOUD_SPACE_COUNT', 'space-count');
define('DURACLOUD_SPACE_CREATED', 'space-created');

// Default store
define('DURACLOUD_DEFAULT_STORE', null);

// DuraCloud metadata prefix
define('DURACLOUD_METADATA_PREFIX', 'x-dura-meta-');

class DuraStore extends DuraCloudComponent {
	/**
	 * Constructor
	 * @param $dcc DuraCloudConnection
	 */
	function DuraStore(&$dcc) {
		parent::DuraCloudComponent($dcc, 'durastore');
	}

	/**
	 * Get a list of stores.
	 * @return array List of store IDs
	 */
	function getStores() {
		// Get the stores list
		$dcc =& $this->getConnection();
		$xml = $dcc->get($this->getPrefix() . 'stores');
		if (!$xml) return false;

		// Parse the result
		$parser = new DuraCloudXMLParser();
		if (!$parser->parse($xml)) return false;

		$returner = array();
		$storageProviderAccounts =& $parser->getResults();
		assert($storageProviderAccounts['name'] === 'storageProviderAccounts');
		foreach ((array) $storageProviderAccounts['children'] as $i => $storageAcct) {
			assert($storageAcct['name'] === 'storageAcct');
			foreach ($storageAcct['children'] as $c) {
				assert(in_array($c['name'], array('id', 'storageProviderType')));
				if (!isset($returner[$i])) {
					$returner[$i] = array(
						'primary' => $storageAcct['attributes']['isPrimary'] == 'true'?true:false
					);
				}
				$returner[$i][$c['name']] = $c['content'];
			}
		}

		$parser->destroy();
		return $returner;
	}

	/**
	 * Get a list of spaces.
	 * @param $storeId int optional ID of store
	 * @return array List of space IDs
	 */
	function getSpaces($storeId = DURACLOUD_DEFAULT_STORE) {
		// Get the spaces list
		$dcc =& $this->getConnection();
		$xml = $dcc->get(
			$this->getPrefix() . 'spaces',
			$storeId !== DURACLOUD_DEFAULT_STORE ? array('storeID' => $storeId) : array()
		);

		if (!$xml) return false;
		// Parse the result
		$parser = new DuraCloudXMLParser();
		if (!$parser->parse($xml)) return false;

		$returner = array();
		$spaces =& $parser->getResults();
		assert($spaces['name'] === 'spaces');
		foreach ($spaces['children'] as $c) {
			assert($c['name'] === 'space');
			$returner[] = $c['attributes']['id'];
		}

		$parser->destroy();

		return $returner;
	}

	/**
	 * Get a list of a space's contents.
	 * @param $storeId int optional ID of store
	 * @param $metadata Reference to variable that will receive metadata
	 * @param $storeId int optional
	 * @param $prefix string optional
	 * @param $maxResults int optional
	 * @param $marker string optional
	 * @return array List of space IDs
	 */
	function getSpace($spaceId, &$metadata, $storeId = DURACLOUD_DEFAULT_STORE, $prefix = null, $maxResults = null, $marker = null) {
		// Get the space contents list
		$dcc =& $this->getConnection();
		$params = array();
		if ($storeId !== DURACLOUD_DEFAULT_STORE) $params['storeId'] = $storeId;
		if ($prefix !== null) $params['prefix'] = $prefix;
		if ($maxResults !== null) $params['maxResults'] = (int) $maxResults;
		if ($marker !== null) $params['marker'] = $marker;
		if (!$dcc->get(
			$this->getPrefix() . urlencode($spaceId),
			$params
		)) return false;
		$xml = $dcc->getData();
		$headers = $dcc->getHeaders();

		// Parse the result headers to return as metadata
		$metadata = $this->_filterMetadata($headers);

		// Parse the result XML
		$parser = new DuraCloudXMLParser();
		if (!$parser->parse($xml)) return false;

		$returner = array();
		$space =& $parser->getResults();
		assert($space['name'] === 'space');
		foreach ((array) $space['children'] as $c) {
			assert($c['name'] === 'item');
			$returner[] = $c['content'];
		}

		$parser->destroy();

		return $returner;
	}

	/**
	 * Get a list of a space's metadata.
	 * @param $spaceId string
	 * @param $storeId int optional ID of store
	 * @return array List of space metadata
	 */
	function getSpaceMetadata($spaceId, $storeId = DURACLOUD_DEFAULT_STORE) {
		// Get the space metadata list
		$dcc =& $this->getConnection();
		$params = array();
		if ($storeId !== DURACLOUD_DEFAULT_STORE) $params['storeId'] = $storeId;
		if (!$dcc->head(
			$this->getPrefix() . urlencode($spaceId),
			$params
		)) return false;
		$headers = $dcc->getHeaders();

		// Parse the result headers to return as metadata
		$metadata = $this->_filterMetadata($headers);

		return $metadata;
	}

	/**
	 * Create a space.
	 * @param $spaceId string
	 * @param $storeId int optional
	 * @param metadata array optional
	 * @return Location of the new space iff success; false otherwise
	 */
	function createSpace($spaceId, $storeId = DURACLOUD_DEFAULT_STORE, $metadata = array()) {
		// Create a new space
		$dcc =& $this->getConnection();
		$params = array();
		if ($storeId !== DURACLOUD_DEFAULT_STORE) $params['storeId'] = $storeId;

		$headers = array();
		foreach ($metadata as $name => $value) {
			$headers[DURACLOUD_METADATA_PREFIX . $name] = $value;
		}

		if (!$dcc->put(
			$this->getPrefix() . urlencode($spaceId),
			null, 0, // No file
			$params,
			$headers
		)) return false;
		$headers = $dcc->getHeaders();

		if (isset($headers['Location'])) return $headers['Location'];

		return false;
	}

	//
	// For internal use only
	//

	/**
	 * Used internally by getSpace and getSpaceMetadata to filter extaneous HTTP headers
	 * out of the metadata set and return only the DuraCloud-specific content.
	 * @param $headers array
	 * @return array
	 */
	function _filterMetadata($headers) {
		$metadata = array();
		foreach ($headers as $key => $value) {
			if (strpos($key, DURACLOUD_METADATA_PREFIX) === 0) {
				$metadata[substr($key, strlen(DURACLOUD_METADATA_PREFIX))] = $value;
			}
		}

		return $metadata;
	}
}

?>
