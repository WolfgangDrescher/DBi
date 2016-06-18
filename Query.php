<?php

/*!
---
Query.php
Wolfgang Drescher - wolfgangdrescher.ch
This class allows you to send queries to a database with the PHP PDO class.
...
*/

require_once 'DBi.php';

class QueryException extends Exception {}

class Query {
	
	// Public variables for class settings
	public static $throwExceptions = true;
	public static $autoSend = false;
	
	const ParamBool = PDO::PARAM_BOOL; // bool
	const ParamNull = PDO::PARAM_NULL; // null
	const ParamStr = PDO::PARAM_STR; // string
	const ParamInt = PDO::PARAM_INT; // integer
	const ParamFloat = self::ParamStr; // float (in PDO treated as a string)
	const ParamLOB = PDO::PARAM_LOB; // blob
	
	const FetchAssoc = PDO::FETCH_ASSOC;
	const FetchNum = PDO::FETCH_NUM;
	const FetchBoth = PDO::FETCH_BOTH;
	const FetchLazy = PDO::FETCH_LAZY;
	const FetchNamed = PDO::FETCH_NAMED;
	const FetchObj = PDO::FETCH_OBJ;
	const FetchColumn = PDO::FETCH_COLUMN;
	const FetchClass = PDO::FETCH_CLASS;
	
	private $sql = ''; // SQL string
	private $duration = 0; // Duration of the statement in milliseconds
	private $connection = null; // Handle to the PDO connection
	private $statement = null; // Handle to the PDO statement
	private $cacheFetchAll = null; // Caches result of fetchAll to use it multiple times
	
	
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
		$this->setConnection($connection);
		$this->prepare($sql);
		$this->bindParams($params);
		if(self::$autoSend === true) {
			$this->send();
		}
	}
	
	// Closes a prepared statement and frees stored result memory
	public function __destruct() {
		if(!$this->isError()) {
			$this->getStatement()->closeCursor();
			$this->setStatement(null);
		}
	}
	
	// Displays the result of a statement as table by echoing the query object itself
	public function __toString() {
		ob_start();
		echo "\n";
		echo '<div class="panel panel-default">'."\n";
		echo '	<div class="panel-body">'."\n";
		echo '		<div><pre class="pre-scrollable">'.htmlentities($this->getSql()).'</pre></div>'."\n";
		$cols = $tables = array();
		for($i = 0; $i < $this->getStatement()->columnCount(); $i++) {
			$cols[] = (object) $this->getStatement()->getColumnMeta($i);
		}
		foreach($cols as $col) {
			if(!in_array($col->table, $tables)) {
				$tables[] = $col->table;
			}
		}
		echo '		<div>Affected rows: '.$this->rows()."</div>\n";
		echo '		<div>Duration: '.$this->getDuration(3)." milliseconds</div>\n";
		echo '		<div>Affected tables: <code>'.implode('</code>, <code>', $tables)."</code></div>\n";
		echo '	</div>'."\n";
		if($this->rows() > 0) {
			echo '	<div class="table-responsive" style="overflow-x: auto;">'."\n";
			echo '		<table class="table table-striped table-bordered table-hover">'."\n";
			echo '			<thead>'."\n";
			echo '				<tr>'."\n";
			echo '					<th>#</th>'."\n";
			foreach($cols as $col) {
				echo '					<th>';
				echo count($tables) > 1 ? $col->table.'.'.$col->name.'' : $col->name;
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
		$this->initializeStatement();
		return $this;
	}
	
	public function getDuration($decimals = null, $inSeconds = false) {
		$duration = $inSeconds === true ? ($this->duration / 1000) : $this->duration;
		return $decimals === null ? $duration : number_format($duration, $decimals);
	}
	
	private function setDuration($duration) {
		$this->duration = floatval($duration);
	}
	
	public function getStatement() {
		return $this->statement;
	}
	
	private function setStatement($statement) {
		$this->statement = $statement;
	}
	
	private function initializeStatement() {
		if(DBi::isConnection($this->getConnection())) {
			$this->setStatement($this->getConnection()->prepare($this->getSql()));
		}
	}
	
	public function getConnection() {
		return $this->connection;
	}
	
	// PDO connection object, string or null can be passed to set the connection
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
		$this->initializeStatement();
		return $this;
	}
	
	public function isError() {
		return $this->getStatement()->errorCode() === '00000' ? false : true;
	}
	
	// Returns the auto generated id used in the last query
	public function insertId() {
		return $this->isError() ? null : intval($this->getConnection()->lastInsertId());
	}
	
	// Returns the number of rows in a result
	public function rows() {
		return $this->isError() ? null : $this->getStatement()->rowCount();
	}
	
	// Fetches the result whereas the default is ->fetchObject()
	public function fetch($type = self::FetchObj) {
		if($type == self::FetchObj OR $type == PDO::FETCH_CLASS) return $this->fetchObject();
		return $this->isError() ? false: $this->getStatement()->fetch($type);
	}
	
	// Returns the current row as an associative array
	public function fetchAssoc() {
		return $this->fetch(self::FetchAssoc);
	}
	
	// Returns the current row as an enumerated array
	public function fetchNum() {
		return $this->fetch(self::FetchNum);
	}
	
	// Returns the current row as an array (associative, enumerated or both)
	public function fetchArray($type = self::FetchBoth) {
		if(!in_array($type, array(self::FetchNum, self::FetchAssoc, self::FetchBoth))) {
			$type = self::FetchBoth;
		}
		return $this->getStatement()->fetch($type);
	}
	
	// Returns the current row of a result set as an object
	public function fetchObject($className = 'stdClass', $classParams = array()) {
		return $this->isError() ? false : (method_exists($className, '__construct') ? $this->getStatement()->fetchObject($className, $classParams) : $this->getStatement()->fetchObject($className));
	}
	
	// Returns an array with the complete result
	public function fetchAll($type = self::FetchAssoc) {
		return $this->isError() ? false : ($this->cacheFetchAll ?: $this->cacheFetchAll = $this->getStatement()->fetchAll($type));
	}
	
	// Returns the value of a bound parameter converted to the variable type
	private function getParamValue($value, $type) {
		$type = $this->getParamType($value, $type);
		if($type == self::ParamStr) {
			return strval($value);
		} elseif($type == self::ParamInt) {
			return intval($value);
		} elseif($type == self::ParamFloat) {
			return floatval($value);
		}
		return $value;
	}
	
	// Returns the type of a bound parameter
	private function getParamType($value, $type) {
		if($type === null OR !in_array($type, array(self::ParamStr, self::ParamInt, self::ParamFloat, self::ParamLOB))) {
			if(is_string($value)) {
				return self::ParamStr;
			} elseif(is_int($value)) {
				return self::ParamInt;
			} elseif(is_float($value)) {
				return self::ParamFloat;
			} elseif(is_bool($value)) {
				return self::ParamBool;
			} elseif(is_null($value)) {
				return self::ParamNull;
			}
		} else {
			return $type;
		}
		return self::ParamStr;
	}
	
	// Binds a parameter to the class object
	public function bindParam($key, $value, $type = null) {
		$this->getStatement()->bindValue(
			is_string($key) ? ':'.trim($key, ':') : (is_int($key) ? $key + 1 : '?'),
			$this->getParamValue($value, $type),
			$this->getParamType($value, $type)
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
	
	// Sets the SQL string and binds parameters
	public function prepare($sql, $params = null) {
		$this->setSql($sql);
		$this->bindParams($params);
		return $this;
	}
	
	// Executes a query and throws error messages
	public function send($params = null) {
		$this->bindParams($params);
		try {
			if(!DBi::isConnection($this->getConnection())) {
				throw new QueryException('The selected PDO connection cannot be used.', -1);
			}
			$timestart = microtime(true);
			$this->getStatement()->execute();
			$this->setDuration((microtime(true) - $timestart) * 1000);
		} catch(PDOException $e) {
			if(self::$throwExceptions === true) {
				echo '<div class="panel panel-danger">'."\n";
				echo '	<div class="panel-heading"><span class="glyphicon glyphicon-warning-sign fa fa-bug fa-spin"></span> <b>SQL-Error [#'.$e->getCode().']</b></div>'."\n";
				echo '	<div class="panel-body">'."\n";
				echo '		<p>'.$e->getMessage().'</p>'."\n";
				// echo '		<p>'.$this->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME).' [#'.$this->getStatement()->errorInfo()[1].']: '.$this->getStatement()->errorInfo()[2].'</p>'."\n";
				$lines = preg_split('/((\r?\n)|(\r\n?))/', $this->getSql());
				preg_match('/at line (\d+)$/', $e->getMessage(), $matches);
				$atLine = isset($matches[1]) ? intval($matches[1]) - 1 : null;
				if(isset($lines[$atLine])) {
					$lines[$atLine] = '<mark>'.$lines[$atLine].'</mark>';
				}
				echo '		<pre>'.implode(PHP_EOL, $lines).'</pre>'."\n";
				echo '		<pre class="alert alert-warning">'.htmlentities($e->getTraceAsString()).'</pre>';
				echo '	</div>'."\n";
				echo '</div>'."\n";
			}
		} catch(Exception $e) { // catches QueryException
			if(self::$throwExceptions === true) {
				echo '<div class="alert alert-danger"><span class="glyphicon glyphicon-warning-sign fa fa-database"></span> '.$e->getMessage().'</div>';
			}
		}
		return $this;
	}
	
	// Returns the complete result as a JSON string
	public function getJSON($type = self::FetchAssoc) {
		return json_encode($this->fetchAll($type), JSON_PRETTY_PRINT);
	}
	
}