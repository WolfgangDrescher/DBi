<?php

/**
 * Query.php
 * Wolfgang Drescher - wolfgangdrescher.ch
 * This class allows you to send queries to a database with the PHP MySQLi class.
 */

require_once 'DBi.php';

class QueryException extends Exception {}

class Query {
	
	public static $throwExceptions = true;
	public static $autoSend = false;
	
	private $sql = '';
	private $duration = 0;
	private $connection = null;
	private $result = null;
	private $errno = null;
	private $error = '';
	
	
	public function __construct($sql, $connection = null) {
		$this->setSql($sql);
		$this->setConnection($connection);
		if(self::$autoSend === true) {
			$this->send();
		}
	}
	
	public function __destruct() {
		if(!$this->isError()) {
			$this->getResult()->close();
		}
	}
	
	public function __toString() {
		ob_start();
		echo "\n";
		echo '<div class="panel panel-default">'."\n";
		// echo '	<div class="panel-heading"><h3 class="panel-title">Panel title</h3></div>'."\n";
		echo '	<div class="panel-body">'."\n";
		echo '		<div><pre>'.htmlentities($this->getSql()).'</pre></div>'."\n";
		$cols = $this->isError() ? array() : $this->getResult()->fetch_fields();
		$tables = array();
		foreach($cols as $value) {
			if(!in_array($value->orgtable, $tables)) {
				$tables[] = $value->orgtable;
			}
		}
		echo '		<div>Affected rows: ' . $this->rows() . "</div>\n";
		echo '		<div>Duration: ' . $this->getDuration(7) * 1000 . " milliseconds</div>\n";
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
				echo $value->name == $value->orgname ? (count($tables) > 1 ? $value->table . '.'.$value->orgname.''  : $value->orgname) : '`'.$value->name.'`';
				echo '</th> '."\n";
			}
			echo '				</tr>'."\n";
			echo '			</thead>'."\n";
			echo '			<tbody>'."\n";
			foreach($this->fetchAll('num') as $key => $row) {
				echo '				<tr>'."\n";
				echo '					<td>' .  intval($key + 1) . '</td>'."\n";
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
	}
	
	public function getDuration($decimals = null) {
		if($decimals === null) {
			return $this->duration;
		}
		return number_format($this->duration, $decimals);
	}
	
	public function setDuration($duration) {
		$this->duration = $duration;
	}
	
	public function getResult() {
		return $this->result;
	}
	
	public function setResult($result) {
		$this->result = $result;
	}
	
	public function getErrno() {
		return $this->errno;
	}
	
	public function setErrno($errno) {
		$this->errno = intval($errno);
	}
	
	public function getError() {
		return $this->error;
	}
	
	public function setError($error) {
		$this->error = $error;
	}
	
	public function getConnection() {
		return $this->connection;
	}
	
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
	}
	
	public function isError() {
		return !$this->getResult() ? true : false;
	}
	
	public function insertId() {
		return $this->isError() ? null : intval($this->getConnection()->insert_id);
	}
	
	public function rows() {
		return $this->isError() ? null : $this->getResult()->num_rows;
	}
	
 	public function seek($rec = 0) {
		return $this->isError() ? null : $this->getResult()->data_seek($rec);
	}
	
	public function fetch($mode = 'assoc') {
		if($mode == 'row') {
			return $this->fetchRow();
		} elseif($mode == 'object') {
			return $this->fetchObject();
		} elseif($mode == 'array') {
			return $this->fetchArray();
		} elseif($mode == 'all') {
			return $this->fetchAll();
		}
		return $this->fetchAssoc();
	}
	
	public function fetchAssoc() {
		return $this->isError() ? false : $this->getResult()->fetch_assoc();
	}
	
	public function fetchRow() {
		return $this->isError() ? false : $this->getResult()->fetch_row();
	}
	
	public function fetchArray($type = 'both') {
		$mysqliConst = array('num' => MYSQLI_NUM, 'assoc' => MYSQLI_ASSOC, 'both' => MYSQLI_BOTH);
		$type = in_array($type, array('num', 'assoc', 'both'))? $type : 'both';
		return $this->isError() ? false : $this->getResult()->fetch_array($mysqliConst[$type]);
	}
	
	public function fetchObject() {
		return $this->isError() ? false : $this->getResult()->fetch_object();
	}
	
	public function fetchAll($type = 'both') {
		if($this->isError()) {
			return false;
		}
		$data = array();
		$this->seek(0);
		while($row = $this->fetchArray($type)) {
			$data[] = $row;
		}
		$this->seek(0);
		return $data;
	}
	
	function send($params = null) {
		try {
			if(!DBi::isConnection($this->getConnection())) {
				throw new DBiException('The selected MySQLi connection could not be used.');
			}
			$timestart = microtime(true);
			$result = $this->getConnection()->query($this->getSql());
			$this->setDuration(microtime(true) - $timestart);
			$this->setResult($result);
			if($this->isError()) {
				$this->setError($this->getConnection()->error);
				$this->setErrno($this->getConnection()->errno);
				throw new QueryException($this->getError(), $this->getErrno());
			}
		} catch(DBiException $e) {
			if(self::$throwExceptions === true) {
				echo '<div class="alert alert-danger">';
				echo htmlentities($e->getMessage());
				echo '</div>';
			}
		} catch(QueryException $e) {
			if(self::$throwExceptions === true) {
				echo '<div class="panel panel-danger">'."\n";
				echo '	<div class="panel-heading"><b>MySQL-Error</b> (#'.htmlentities($this->getErrno()).')</div>'."\n";
				echo '	<div class="panel-body">'."\n";
				echo '		<p>'.htmlentities($this->getError()).'</p>'."\n";
				$lines = preg_split('/((\r?\n)|(\r\n?))/', $this->getSql());
				preg_match('/at line (\d+)$/', $this->getError(), $matches);
				$atLine = isset($matches[1]) ? intval($matches[1]) - 1 : null;
				if(isset($lines[$atLine])) {
					$lines[$atLine] = '<mark>'.$lines[$atLine].'</mark>';
				}
				echo '		<pre>'.implode(PHP_EOL, $lines).'</pre>'."\n";  // htmlentities
				echo '	</div>'."\n";
				echo '</div>'."\n";
			}
		}
	}
	
	public function returnJSON($quotes = false, $type = 'assoc') {
		$json = json_encode($this->fetchAll($type));
		return $quotes === true ? '\''.$json.'\'' : $json;
	}
	
	public function echoJSON($quotes = false, $type = 'assoc') {
		echo $this->returnJSON($quotes, $type);
	}
	
}