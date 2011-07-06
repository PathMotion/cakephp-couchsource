<?php
App::import('Core', 'HttpSocket');

class CouchSource extends DataSource {

	public $config = array();
	public $connected = false;

	private static $default_params = array(
		'group' => null
	);


	public function __construct($config = null, $autoConnect = true) {
		parent::__construct($config, $autoConnect);
		if($autoConnect) {
			$this->connect();
		}
	}
	
	public function connect() {
		if (!$this->connected) {
			$this->Socket = new HttpSocket(array( 'request' => array( 
				'uri' => array(
					'host' => $this->config['host'],
					'port' => $this->config['port'],
					'user' => $this->config['user'],
					'pass' => $this->config['password']
				),
				'header' => array(
					'Content-Type' => 'application/json'
				)
			)));
			if (strpos($this->Socket->get('/'), 'couchdb') !== false) {
				$this->connected = true;
			}
		}
		return $this->connected;
	}
	
	public function close() {
		if($this->connected) {
			$this->Socket->reset();
			$this->connected = false;
		}
	}
	
	//public function listSources() {
	//	if($this->connected) {
	//		return $this->decode($this->Socket->get('/_all_dbs'));
	//	}
	//}
	
	public function decode($data) {
		return json_decode($data, true);
	}
	
	public function encode($data) {
		return json_encode($data);
	}
	
	public function describe(&$model) {
		
		return $model->_schema;
		
	}
	
	public function read(&$model, $queryData = array()) {

		if(isset($queryData['view'])) {
			// request a view
			$base_uri = sprintf('/%s/_design/%s/_view/%s', $model->table, $queryData['design'], $queryData['view']);
		} elseif(isset($queryData['conditions'][$model->alias . '.id'])) {
			// request a specific document
			$base_uri = sprintf('/%s/%s', $model->table, $queryData['conditions'][$model->alias . '.id']);
		} else {
			// request all documents
			$base_uri = sprintf('/%s/_all_docs', $model->table);
		}
		
		if($queryData['fields'] == 'count') {
			unset($queryData['params']['limit']);
		}
		
		$uri = $base_uri . '?' . http_build_query(array_merge(self::$default_params, isset($queryData['params']) ? $queryData['params'] : array()));
		
		$raw_result = $this->decode($this->Socket->get($uri));
		
		if(isset($raw_result->error)) {
			return false;
		}
		
		$result = array();
		if(isset($queryData['conditions'][$model->alias . '.id'])) {
			if($queryData['fields'] == 'count') {
				$result[] = array( $model->alias => array(
					'count' => 1
				));
			} else {
				$result[] = array( $model->alias => $raw_result);
			}
		} else {
			if($queryData['fields'] == 'count') {
				$result[] = array( $model->alias => array(
					'count' => count($raw_result->rows)
				));
			} else {
				if (isset($raw_result['rows']) && !empty($raw_result['rows'])){
					foreach($raw_result['rows'] as $row) {
						$result[] = array( $model->alias => $row);
					}
				}
			}
		}
		
		return $result;
		
	}
	
	public function create(&$model, $fields = null, $values = null) {
		if ($fields !== null && $values !== null) {
			$data = array_combine($fields, $values);
		}
		
		return $this->decode($this->Socket->post(
			sprintf(sprintf('/%s', $model->table)),
			$this->encode($data)
		));
	}
	
	public function update(&$model, $fields = null, $values = null) {

		if ($fields !== null && $values !== null) {
			$data = array_combine($fields, $values);
			$id = $data[$model->primaryKey];
			$actual_data = $model->find('first', array('conditions' => array($model->alias . '.id' => $id)));
			$data = array_merge($actual_data[$model->alias], $data);
			unset($data[$model->primaryKey]);
		}

		return $this->decode($this->Socket->put(
			sprintf('/%s/%s', $model->table, $id),
			$this->encode($data)
		));
	}
	
	public function delete(&$model, $conditions = null) {
		
		$id = $conditions[sprintf('%s.%s', $model->alias, $model->primaryKey)];
		$actual_data = $model->find('first', array('conditions' => array($model->alias . '.id' => $id)));
		
		if($actual_data !== false) {
			$this->Socket->delete(
				sprintf('/%s/%s?rev=%s', $model->table, $actual_data[$model->alias]['_id'], $actual_data[$model->alias]['_rev'])
			);
		}
	}
	
	
	public function calculate(&$model, $func, $params = array()) {
		return 'count';
	}
	
}

?>