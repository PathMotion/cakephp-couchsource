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
					'port' => $this->config['port']
				),
				'header' => array(
					'Content-Type' => 'application/json'
				)
			)));
			
			switch($this->config['auth_method']) {
				case 'cookie':
					$this->cookieConnect();
				break;
				case 'basic':
					$this->basicConnect();
				break;
			}
			
			if (strpos($this->Socket->get('/'), 'couchdb') !== false) {
				$this->connected = true;
			}
			
		}
		return $this->connected;
	}
	
	private function basicConnect() {
		$this->Socket->config['request']['uri']['user'] = $this->config['user'];
		$this->Socket->config['request']['uri']['pass'] = $this->config['password'];
	}
	
	private function cookieConnect() {
		$this->Socket->post('/_session', array(
				'name' => $this->config['user'],
				'password' => $this->config['password']
			), array('header' => array(
				'Content-Type' =>  'application/x-www-form-urlencoded'
			))
		);
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
			$base_uri = sprintf('/%s/_design/%s/_view/%s', $this->getDbName($model), $queryData['design'], $queryData['view']);
		} elseif(isset($queryData['conditions'][$model->alias . '.id'])) {
			// request a specific document
			$base_uri = sprintf('/%s/%s', $this->getDbName($model), $queryData['conditions'][$model->alias . '.id']);
		} else {
			// request all documents
			$base_uri = sprintf('/%s/_all_docs', $this->getDbName($model));
		}
		
		if($queryData['fields'] == 'count') {
			unset($queryData['params']['limit']);
		}
		
		$uri = $base_uri . '?' . http_build_query(array_merge(self::$default_params, isset($queryData['params']) ? $queryData['params'] : array()));
		
		$raw_result = $this->decode($this->Socket->get($uri));

		if(isset($raw_result['error'])) {
			return false;
		}
		
		$result = array();
		if(isset($queryData['conditions'][$model->alias . '.id'])) {
			// one document is requested
			if($queryData['fields'] == 'count') {
				$result[] = array( $model->alias => array(
					'count' => 1
				));
			} else {
				$result[] = array( $model->alias => $raw_result);
			}
		} else {
			if($queryData['fields'] == 'count') {
				// documents count is requested
				$result[] = array( $model->alias => array(
					'count' => count($raw_result->rows)
				));
			} else {
				// a collection of documents is requested
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
		
		// rebuild the data array
		if ($fields !== null && $values !== null) {
			$data = array_combine($fields, $values);
		}

		// id is specified => PUT method (even on a unexisting document)
		// otherwise => POST method (id is build by the CouchDB engine)
		if(in_array($model->primaryKey, $fields)) {
			$id = $data[$model->primaryKey];
			unset($data[$model->primaryKey]);
			return $this->decode($this->Socket->put(
				sprintf('/%s/%s', $this->getDbName($model), $id),
				$this->encode($data)
			));
		} else {
			return $this->decode($this->Socket->post(
				sprintf(sprintf('/%s', $this->getDbName($model))),
				$this->encode($data)
			));
		}
		
	}
	
	public function update(&$model, $fields = null, $values = null) {
		
		// not all the fields can be passed there, so merge with the existing document
		if ($fields !== null && $values !== null) {
			$data = array_combine($fields, $values);
			$id = $data[$model->primaryKey];
			$actual_data = $model->find('first', array('conditions' => array($model->alias . '.id' => $id)));
			if($actual_data) {
				$data = array_merge($actual_data[$model->alias], $data);
			}
			unset($data[$model->primaryKey]);
		}

		return $this->decode($this->Socket->put(
			sprintf('/%s/%s', $this->getDbName($model), $id),
			$this->encode($data)
		));
	}
	
	public function delete(&$model, $conditions = null) {
		
		$id = $conditions[sprintf('%s.%s', $model->alias, $model->primaryKey)];
		$actual_data = $model->find('first', array('conditions' => array($model->alias . '.id' => $id)));
		
		if($actual_data !== false) {
			$this->Socket->delete(
				sprintf('/%s/%s?rev=%s', $this->getDbName($model), $actual_data[$model->alias]['_id'], $actual_data[$model->alias]['_rev'])
			);
		}
	}
	
	public function query($method, $params, &$model) {

		if (strpos(strtolower($method), 'findby') === 0 || strpos(strtolower($method), 'findallby') === 0) {

			if (strpos(strtolower($method), 'findby') === 0) {
				$all  = false;
				$field = Inflector::underscore(preg_replace('/^findBy/i', '', $method));
			} else {
				$all  = true;
				$field = Inflector::underscore(preg_replace('/^findAllBy/i', '', $method));
			}

			$conditions = array($model->alias . '.' . $field => $params[0]);

			if ($all) {
				return $model->find('all', array(
					'conditions' => $conditions
				));
			} else {
				return $model->find('first', array(
					'conditions' => $conditions
				));
			}
		}
		
	}
	
	public function calculate(&$model, $func, $params = array()) {
		return 'count';
	}
	
	public function getDbName(&$model) {
		return isset($this->config['prefix']) ? sprintf('%s_%s', $this->config['prefix'], $model->table) : $model->table;
	}
	
}

?>