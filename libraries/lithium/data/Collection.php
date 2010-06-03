<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2010, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace lithium\data;

use \RuntimeException;

/**
 * The `Collection` class extends the generic `lithium\util\Collection` class to provide
 * context-specific features for working with sets of data persisted by a backend data store. This
 * is a general abstraction that operates on abitrary sets of data from either relational or
 * non-relational data stores.
 */
abstract class Collection extends \lithium\util\Collection {

	/**
	 * A reference to this object's parent `Document` object.
	 *
	 * @var object
	 */
	public $_parent = null;

	/**
	 * If this `Collection` instance has a parent document (see `$_parent`), this value indicates
	 * the key name of the parent document that contains it.
	 *
	 * @see lithium\data\Collection::$_parent
	 * @var string
	 */
	protected $_pathKey = null;

	/**
	 * The fully-namespaced class name of the model object to which this entity set is bound. This
	 * is usually the model that executed the query which created this object.
	 *
	 * @var string
	 */
	protected $_model = null;

	/**
	 * A reference to the object that originated this entity set; usually an instance of
	 * `lithium\data\Source` or `lithium\data\source\Database`. Used to load column definitions and
	 * lazy-load entities.
	 *
	 * @see lithium\data\Source
	 * @var object
	 */
	protected $_handle = null;

	/**
	 * A reference to the query object that originated this entity set; usually an instance of
	 * `lithium\data\model\Query`.
	 *
	 * @see lithium\data\model\Query
	 * @var object
	 */
	protected $_query = null;

	/**
	 * A pointer or resource that is used to load entities from the object (`$_handle`) that
	 * originated this collection.
	 *
	 * @var resource
	 */
	protected $_result = null;

	/**
	 * Indicates whether the current position is valid or not. This overrides the default value of
	 * the parent class.
	 *
	 * @var boolean
	 * @see lithium\util\Collection::valid()
	 */
	protected $_valid = true;

	/**
	 * Contains an array of backend-specific statistics generated by the query that produced this
	 * `Collection` object. These stats are accessible via the `stats()` method.
	 *
	 * @see lithium\data\Collection::stats()
	 * @var array
	 */
	protected $_stats = array();

	/**
	 * By default, query results are not fetched until the collection is iterated. Set to `true`
	 * when the collection has begun iterating and fetching entities.
	 *
	 * @see lithium\data\Collection::rewind()
	 * @see lithium\data\Collection::_populate()
	 * @var boolean
	 */
	protected $_hasInitialized = false;

	/**
	 * Holds an array of values that should be processed on initialization.
	 *
	 * @var array
	 */
	protected $_autoConfig = array(
		'data', 'classes' => 'merge', 'handle', 'model',
		'result', 'query', 'parent', 'stats', 'pathKey'
	);

	/**
	 * Class constructor
	 *
	 * @param array $config
	 * @return void
	 */
	public function __construct(array $config = array()) {
		$defaults = array('data' => array(), 'handle' => null, 'model' => null);
		parent::__construct($config + $defaults);

		foreach (array('data', 'classes', 'handle', 'model', 'result', 'query') as $key) {
			unset($this->_config[$key]);
		}
		if (!is_array($this->_data)) {
			$message = 'Error creating new Collection instance; data format invalid.';
			throw new RuntimeException($message);
		}
	}

	/**
	 * Configures protected properties of a `Collection` so that it is parented to `$parent`.
	 *
	 * @param object $parent
	 * @param array $config
	 * @return void
	 */
	public function assignTo($parent, array $config = array()) {
		foreach ($config as $key => $val) {
			$this->{'_' . $key} = $val;
		}
		$this->_parent =& $parent;
	}

	/**
	 * Returns the model which this particular collection is based off of.
	 *
	 * @return string The fully qualified model class name.
	 */
	public function model() {
		return $this->_model;
	}

	/**
	 * Returns a boolean indicating whether an offset exists for the 
	 * current `Collection`.
	 *
	 * @param string $offset String or integer indicating the offset or 
	 *               index of an entity in the set.
	 * @return boolean Result.
	 */
	public function offsetExists($offset) {
		return ($this->offsetGet($offset) !== null);
	}

	/**
	 * Reset the set's iterator and return the first entity in the set.
	 * The next call of `current()` will get the first entity in the set.
	 *
	 * @return object Returns the first `Entity` instance in the set.
	 */
	public function rewind() {
		$this->_valid = (reset($this->_data)  || count($this->_data));

		if (!$this->_valid && !$this->_hasInitialized) {
			$this->_hasInitialized = true;

			if ($entity = $this->_populate()) {
				$this->_valid = true;
				return $entity;
			}
		}
	}

	/**
	 * Returns meta information for this `Collection`.
	 *
	 * @return array
	 */
	public function meta() {
		return array('model' => $this->_model);
	}

	/**
	 * Applies a callback to all data in the collection.
	 *
	 * Overridden to load any data that has not yet been loaded.
	 *
	 * @param callback $filter The filter to apply.
	 * @return object This collection instance.
	 */
	public function each($filter) {
		if (!$this->closed()) {
			while($this->next()) {}
		}
		return parent::each($filter);
	}

	/**
	 * Applies a callback to a copy of all data in the collection
	 * and returns the result.
	 *
	 * Overriden to load any data that has not yet been loaded.
	 *
	 * @param callback $filter The filter to apply.
	 * @param array $options The available options are:
	 *              - `'collect'`: If `true`, the results will be returned wrapped
	 *              in a new Collection object or subclass.
	 * @return array|object The filtered data.
	 */
	public function map($filter, array $options = array()) {
		if (!$this->closed()) {
			while($this->next()) {}
		}
		return parent::map($filter, $options);
	}

	/**
	 * Gets the stat or stats associated with this `Collection`.
	 *
	 * @param string $name Stat name.
	 * @return mixed Single stat if `$name` supplied, else all stats for this
	 *               `Collection`.
	 */
	public function stats($name = null) {
		if ($name) {
			return isset($this->_stats[$name]) ? $this->_stats[$name] : null;
		}
		return $this->_stats;
	}

	/**
	 * Executes when the associated result resource pointer reaches the end of its data set. The
	 * resource is freed by the connection, and the reference to the connection is unlinked.
	 *
	 * @return void
	 */
	public function close() {
		if (!$this->closed()) {
			$this->_result = $this->_handle->result('close', $this->_result, $this);
			unset($this->_handle);
		}
	}

	/**
	 * Checks to see if this entity has already fetched all available entities and freed the
	 * associated result resource.
	 *
	 * @return boolean Returns true if all entities are loaded and the database resources have been
	 *         freed, otherwise returns false.
	 */
	public function closed() {
		return (empty($this->_result) && (!isset($this->_handle) || empty($this->_handle)));
	}

	/**
	 * Ensures that the data set's connection is closed when the object is destroyed.
	 *
	 * @return void
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * A method to be implemented by concrete `Collection` classes which, provided a reference to a
	 * backend data source (see the `$_handle` property), and a resource representing a query result
	 * cursor, fetches new result data and wraps it in the appropriate object type, which is added
	 * into the `Collection` and returned.
	 *
	 * @param mixed $data Data (in an array or object) that is manually added to the data
	 *              collection. If `null`, data is automatically fetched from the associated backend
	 *              data source, if available.
	 * @param mixed $key String, integer or array key representing the unique key of the data
	 *              object. If `null`, the key will be extracted from the data passed or fetched,
	 *              using the associated `Model` class.
	 * @return object Returns a `Record` or `Document` object, or other `Entity` object.
	 */
	abstract protected function _populate($data = null, $key = null);
}

?>