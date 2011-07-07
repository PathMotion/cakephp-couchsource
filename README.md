CouchSource
===========

CouchSource is a [CakePHP datasource](http://book.cakephp.org/view/1075/DataSources) allowing to build Models from a [CouchDB](http://couchdb.apache.org/) database.
This is the one in use on the [PathMotion](http://www.pathmotion.com) website.

Install
-------

After having [set the couchdb](http://wiki.apache.org/couchdb/Installation) engine, follow this steps to make it work with CakePHP :

* Put the *couch_source.php* file into the *datasource* directory, usually <code>app/models/datasource</code>.
* Edit the *database.php* configuration file to add the following :

	class DATABASE_CONFIG {
		
		// [...]
		
		var $couch = array(
			'datasource' => 'couch',
			'host' => '127.0.0.1',
			'port' => 5984,
			'user' => 'my_couchdb_user',
			'password' => 'my_couchdb_password'
		);
	
	}
	
* create a model that you want to use CouchDB

	class MyModel extends Model {

		public $name = 'MyModel';
		public $useDbConfig = 'couch';
		public $useTable = 'couchdb_database_name';
		public $primaryKey = 'id';

		// since CouchDB is shema-less, the fields here are only required 
		// for CakePHP to validate and save them into the database
		public $_schema = array(
			'id' => array(
				'type' => 'string',
				'key' => 'primary',
				'length' => 32
			),
			'anyfield' => array(
				'type' => 'json',
				'null' => true
			),
			'anotherfield' => array(
				'type' => 'json',
				'null' => true
			)
		);
		
	}

Use Cases
---------

### **Create** an all new document
	
	class MyModel extends Model {
		
		fucntion create_record() {
			$this->save(array('MyModel' => array(
				'anyfield' => 'anyvalue',
				'anotherfield' => array(
					'title' => 'awesome title'
					'content' => 'less awesome content'
				)
			)));
		}
		
	}


### **Update** an existing document

	class MyModel extends Model {
		
		fucntion update_record($id) {
			$this->save(array('MyModel' => array(
				'id' => $id,
				'anyfield' => 'anyvalue',
				'anotherfield' => array(
					'title' => 'awesome title'
					'content' => 'less awesome content'
				)
			)));
		}
		
	}
	
### **Delete** an existing document

	class MyModel extends Model {

		fucntion delete_record($id) {
			$this->delete($id);
		}

	}
	
### **Read** an existing document

	class MyModel extends Model {

		fucntion read_record($id) {
			$this->findById($id);
		}

	}
	
### **Read** data from a view

The params to use here are :

* *design* the CouchDB design where the view resides
* *view* the name of the view you want to request
* *params* an array of [query options](http://wiki.apache.org/couchdb/HTTP_view_API#Querying_Options)

	class MyModel extends Model {

		fucntion read_view($start, $end) {
			$this->find('all', array(
				'design' => 'mycouchdbdesign',
				'view' => 'mycouchdbview',
				'params' => array('start_key' => $start, 'end_key' => $end, 'group' => null)
			));
		}

	}

