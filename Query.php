<?php

/*!
---
Query.php
Wolfgang Drescher - wolfgangdrescher.ch
This class allows you to send queries to a database with the PHP MySQLi class.
...
*/

require_once 'DBi.php';

class QueryException extends Exception {}

class Query {
	
	// Public variables for class settings
	public static $throwExceptions = true;
	public static $autoSend = false;
	
	private $sql = '';
	private $duration = 0;
	private $connection = null;
	private $result = null;
	private $statement = null;
	private $errno = null;
	private $error = '';
	private $boundParams = array();
	private $boundResult = array();
	
	const PARAM_STR = 's';
	const PARAM_INT = 'i';
	const PARAM_FLOAT = 'd';
	const PARAM_BLOB = 'b';
	
	// Returns new self as an object to enable method chaining in one line
	public static function init($sql = null, $params = null, $connection = null) {
		return new self($sql, $params, $connection);
	}
	
	// Executes queries instantly without considering the value of Query::$autoSend 
	public static function exec($sql = null, $params = null, $connection = null) {
		$instance = new self($sql, $params, $connection);
		return self::$autoSend === true ? $instance : $instance->send();
	}
	
	// Passes all arguments for preparing a statement and sends query if Query::$autoSend is true
	public function __construct($sql = null, $params = null, $connection = null) {
		$this->prepare($sql);
		$this->bindParams($params);
		$this->setConnection($connection);
		if(self::$autoSend === true) {
			$this->send();
		}
	}
	
	// Closes a prepared statement and frees stored result memory
	public function __destruct() {
		if(!$this->isError()) {
			$this->getStatement()->free_result();
			$this->getStatement()->close();
		}
	}
	
	// Displays the result of a statement as table by echoing the query object itself
	public function __toString() {
		ob_start();
		echo "\n";
		echo '<div class="panel panel-default">'."\n";
		echo '	<div class="panel-body">'."\n";
		echo '		<div><pre class="pre-scrollable">'.htmlentities($this->getSql()).'</pre></div>'."\n";
		$cols = (!$this->isError() AND method_exists($this->getStatement()->result_metadata(), 'fetch_fields')) ? $this->getStatement()->result_metadata()->fetch_fields() : array();
		$tables = array();
		foreach($cols as $value) {
			if(!in_array($value->orgtable, $tables)) {
				$tables[] = $value->orgtable;
			}
		}
		echo '		<div>Affected rows: '.$this->rows()."</div>\n";
		echo '		<div>Duration: '.($this->getDuration(7) * 1000)." milliseconds</div>\n";
		echo '		<div>Affected tables: <code>'.implode('</code>, <code>', $tables)."</code></div>\n";
		echo '	</div>'."\n";
		if($this->rows() > 0) {
			echo '	<div class="table-responsive" style="overflow-x: auto;">'."\n";
			echo '		<table class="table table-striped table-bordered table-hover">'."\n";
			echo '			<thead>'."\n";
			echo '				<tr>'."\n";
			echo '					<th>#</th>'."\n";
			foreach($cols as $key => $value) {
				echo '					<th>';
				echo $value->name == $value->orgname ? (
					count($tables) > 1 ? $value->table.'.'.$value->orgname.'' : $value->orgname
				) : '`'.$value->name.'`';
				echo '</th> '."\n";
			}
			echo '				</tr>'."\n";
			echo '			</thead>'."\n";
			echo '			<tbody>'."\n";
			foreach($this->fetchAll() as $key => $row) {
				echo '				<tr>'."\n";
				echo '					<td>'.intval($key + 1).'</td>'."\n";
				echo '					<td>'.implode($row, "</td>\n					<td>").'</td>'."\n";
				echo '				</tr>'."\n";
			}
			echo '			</tbody>'."\n";
			echo '		</table>'."\n";
			echo '	</div>'."\n";
		}
		echo '</div>'."\n";
		return ob_get_clean();
	}
	
	public function getSql() {
		return $this->sql;
	}
	
	public function setSql($sql) {
		$this->sql = trim($sql);
		return $this;
	}
	
	public function getDuration($decimals = null) {
		return $decimals === null ? $this->duration : number_format($this->duration, $decimals);
	}
	
	private function setDuration($duration) {
		$this->duration = $duration;
	}
	
	public function getResult() {
		return $this->result;
	}
	
	private function setResult($result) {
		$this->result = $result;
	}
	
	public function getStatement() {
		return $this->statement;
	}
	
	private function setStatement($statement) {
		$this->statement = $statement;
	}
	
	public function getErrno() {
		return $this->errno;
	}
	
	private function setErrno($errno) {
		$this->errno = intval($errno);
	}
	
	public function getError() {
		return $this->error;
	}
	
	private function setError($error) {
		$this->error = $error;
	}
	
	public function getConnection() {
		return $this->connection;
	}
	
	// MySQLi connection object, string or null can be passed to set the connection
	public function setConnection($connection) {
		if(DBi::isConnection($connection)) {
			$this->connection = $connection;
		} elseif((is_string($connection) OR is_int($connection)) AND DBi::isConnection(DBi::get($connection))) {
			$this->connection = DBi::get($connection);
		} elseif($connection === null AND DBi::isConnection(DBi::get())) {
			$this->connection = DBi::get();
		} else {
			$this->connection = null;
		}
		return $this;
	}
	
	public function isError() {
		return $this->getErrno() === 0 ? false : true;
	}
	
	// Returns the auto generated id used in the last query
	public function insertId() {
		return $this->isError() ? null : intval($this->getConnection()->insert_id);
	}
	
	// Returns the number of rows in a result
	public function rows() {
		return $this->isError() ? null : $this->getStatement()->num_rows;
	}
	
	// Sets the result pointer to an arbitrary row in the result
 	public function seek($rec = 0) {
		return $this->isError() ? null : $this->getStatement()->data_seek($rec);
	}
	
	// Fetches the result whereas the default is ->fetchArray() or ->fetchVar() if result parameters are bound
	public function fetch($mode = null) {
		if($mode == 'row' OR $mode == 'num') {
			return $this->fetchRow();
		} elseif($mode == 'object') {
			return $this->fetchObject();
		} elseif($mode == 'array') {
			return $this->fetchArray();
		} elseif($mode == 'assoc') {
			return $this->fetchAssoc();
		} elseif($mode == 'all') {
			return $this->fetchAll();
		}
		return count($this->boundResult) ? $this->fetchVar() : $this->fetchAssoc();
	}
	
	// Passes the columns of the current row to the bound result variables
	public function fetchVar() {
		if($this->isError()) return false;
		call_user_func_array(array($this->getStatement(), 'bind_result'), $this->boundResult);
		return $this->getStatement()->fetch();
	}
	
	// Returns the current row as an associative array
	public function fetchAssoc() {
		return $this->fetchArray('assoc');
	}
	
	// Returns the current row as an enumerated array
	public function fetchRow() {
		return $this->fetchArray('row');
	}
	
	// Returns the current row as an array (associative, enumerated or both)
	public function fetchArray($type = 'both') {
		if($this->isError()) return false;
		foreach($this->getStatement()->result_metadata()->fetch_fields() as $field) {
			$params[] = & $row[$field->name];
		}
		call_user_func_array(array($this->getStatement(), 'bind_result'), $params);
		return $this->getStatement()->fetch() ? (
			$type == 'both' ? array_merge($row, array_values($row)) : (
				($type == 'row' OR $type == 'num') ? array_values($row) : $row
			)
		) : null;
	}
	
	// Returns the current row of a result set as an object
	public function fetchObject($className = 'stdClass', $classParams = array()) {
		if($this->isError()) return false;
		$reflectionObject = new ReflectionClass($className);
		$row = $reflectionObject->newInstanceArgs($classParams);
		foreach($this->getStatement()->result_metadata()->fetch_fields() as $field) {
			$params[] = & $row->{$field->name};
		}
		call_user_func_array(array($this->getStatement(), 'bind_result'), $params);
		return $this->getStatement()->fetch() ? $row : null;
	}
	
	// Returns an array with the complete result
	public function fetchAll($type = 'assoc') {
		if($this->isError()) return false;
		$data = array();
		$this->seek(0);
		while($row = $this->fetchArray($type)) {
			$data[] = $row;
		}
		$this->seek(0);
		return $data;
	}
	
	// Returns the value of a bound parameter converted to the variable type
	private function getParamValue($value, $type) {
		$type = $this->getParamType($value, $type);
		if($type == self::PARAM_STR) {
			return strval($value);
		} elseif($type == self::PARAM_INT) {
			return intval($value);
		} elseif($type == self::PARAM_FLOAT) {
			return floatval($value);
		}
		return $value;
	}
	
	// Returns the type of a bound parameter
	private function getParamType($value, $type) {
		if($type === null OR !in_array($type, array(self::PARAM_STR, self::PARAM_INT, self::PARAM_FLOAT, self::PARAM_BLOB), $type)) {
			if(is_string($value)) {
				return self::PARAM_STR;
			} elseif(is_int($value)) {
				return self::PARAM_INT;
			} elseif(is_float($value)) {
				return self::PARAM_FLOAT;
			}
		} else {
			return $type;
		}
		return self::PARAM_STR;
	}
	
	// Binds a parameter to the class object
	public function bindParam($key, $value, $type = null) {
		$this->boundParams[] = array(
			'key' => is_string($key) ? ':'.trim($key, ':') : '?',
			'value' => $this->getParamValue($value, $type),
			'type' => $this->getParamType($value, $type)
		);
		return $this;
	}
	
	// Binds multiple parameters to the class object
	public function bindParams() {
		if(func_num_args() > 1 OR func_get_arg(0) !== null) {
			foreach(is_array(func_get_arg(0)) ? func_get_arg(0) : func_get_args() as $key => $value) {
				$this->bindParam($key, $value);
			}
		}
		return $this;
	}
	
	// Binds variables to the result for later use of ->fetchVar()
	public function bindResult(&$a = null, &$b = null, &$c = null, &$d = null, &$e = null, &$f = null, &$g = null, &$h = null, &$i = null, &$vj = null, &$k = null, &$l = null, &$m = null, &$n = null, &$o = null, &$p = null, &$q = null, &$r = null, &$s = null, &$t = null, &$u = null, &$v = null, &$w = null, &$x = null, &$y = null, &$z = null) {
		$trace = debug_backtrace(false);
		$args = isset($trace[0]['args']) ? $trace[0]['args'] : array();
		foreach($args as & $value) {
			$this->boundResult[] = & $value;
		}
		return $this;
	}
	
	// Sets the SQL string and binds parameters
	public function prepare($sql, $params = null) {
		$this->setSql($sql);
		$this->bindParams($params);
		return $this;
	}
	
	// Replaces all named parameters with `?`
	private function parseSql() {
		return preg_replace('/(:\w+)/is', '?', $this->getSql());
	}
	
	// Returns an array with the bound parameters in correct order for usage with ->parseSql()
	private function parseNamedParams() {
		$array = array('');
		$params = $this->boundParams;
		if(preg_match_all('/(:\w+|\?)/is', $this->getSql(), $matches)) {
			foreach($matches[0] as $value) {
				// $keyIndex = array_search($value, array_column($params, 'key')); // 5.5
				$keyIndex = array_search($value, array_map(function($element) { return $element['key']; }, $params)); // 5.3
				if($keyIndex !== false) {
					$array[0] .= $params[$keyIndex]['type'];
					$array[] = & $params[$keyIndex]['value'];
					unset($params[$keyIndex]);
					$params = array_values($params);
				} else {
					throw new QueryException('No named parameter <code>'.($value).'</code> was bound to the SQL statement.', -1);
				}
			}
		}
		return count($array) > 1 ? $array : null;
	}
	
	// Executes a query and throws error messages
	public function send($params = null) {
		$this->bindParams($params);
		try {
			if(!DBi::isConnection($this->getConnection())) {
				throw new DBiException('The selected MySQLi connection could not be used.', -1);
			}
			mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
			$this->setStatement($this->getConnection()->stmt_init());
			$this->getStatement()->prepare($this->parseSql());
			if($this->parseNamedParams()) {
				if(count($this->parseNamedParams()) - 1 >= $this->getStatement()->param_count) {
					call_user_func_array(array($this->getStatement(), 'bind_param'), $this->parseNamedParams());
				} else {
					throw new QueryException('Number of variables does not match number of parameters in prepared statement.', -1);
				}
			}
			$timestart = microtime(true);
			$this->getStatement()->execute();
			$this->setDuration(microtime(true) - $timestart);
			if(extension_loaded('mysqlnd') AND method_exists($this->getStatement(), 'get_result')) {
				$this->setResult($this->getStatement()->get_result());
			}
			$this->getStatement()->store_result(); // needed fot $stmt->num_rows
			$this->setError($this->getStatement()->error);
			$this->setErrno($this->getStatement()->errno);
			if($this->isError()) {
				throw new QueryException($this->getError(), $this->getErrno());
			}
		} catch(DBiException $e) {
			$this->setError($e->getMessage());
			$this->setErrno($e->getCode());
			if(self::$throwExceptions === true) {
				echo '<div class="alert alert-danger"><span class="glyphicon glyphicon-warning-sign fa fa-database"></span> '.$e->getMessage().'</div>';
			}
		} catch(Exception $e) { // catch QueryException and MySQLi_SQL_Exception 
			$this->setError($e->getMessage());
			$this->setErrno($e->getCode());
			if(self::$throwExceptions === true) {
				echo '<div class="panel panel-danger">'."\n";
				echo '	<div class="panel-heading"><span class="glyphicon glyphicon-warning-sign fa fa-bug fa-spin"></span> <b>MySQLi-Error</b> (#'.$this->getErrno().')</div>'."\n";
				echo '	<div class="panel-body">'."\n";
				echo '		<p>'.$this->getError().'</p>'."\n";
				$lines = preg_split('/((\r?\n)|(\r\n?))/', $this->parseSql());
				preg_match('/at line (\d+)$/', $this->getError(), $matches);
				$atLine = isset($matches[1]) ? intval($matches[1]) - 1 : null;
				if(isset($lines[$atLine])) {
					$lines[$atLine] = '<mark>'.$lines[$atLine].'</mark>';
				}
				echo '		<pre>'.implode(PHP_EOL, $lines).'</pre>'."\n";
				echo '		<pre class="alert alert-warning">'.htmlentities($e->getTraceAsString()).'</pre>';
				echo '	</div>'."\n";
				echo '</div>'."\n";
			}
		}
		return $this;
	}
	
	// Returns the complete result as a JSON string
	public function getJSON($type = 'assoc') {
		return json_encode($this->fetchAll($type), JSON_PRETTY_PRINT);
	}
	
}