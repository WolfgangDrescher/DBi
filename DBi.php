<?php

/*!
---
DBi.php
Wolfgang Drescher - wolfgangdrescher.ch
This class allows you to connect to a MySQL database with the PHP MySQLi class
and handles multiple connections.
...
*/

require_once 'Query.php';

class DBiException extends Exception {}

class DBi {
	
	public static $autoSelect = true;
	private static $databases = array();
	private static $currentConnection = null;
	
	// Connects to a database with MySQLi
	public static function connect($host = null, $user = null, $password = null, $dbname = null, $port = null) {
		try {
			mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
			$connection = new MySQLi($host, $user, $password, $dbname, $port);
			$connection->select_db($dbname);
			if(!($connection->connect_errno === 0)) {
				throw new DBiException($connection->connect_error, $connection->connect_errno);	
			} else {
				return $connection;
			}
		} catch (Exception $e) { // catch DBiException and MySQLi_SQL_Exception 
			ob_start();
			echo '<div class="alert alert-danger"><span class="glyphicon glyphicon-warning-sign fa fa-exclamation-triangle"></span> ';
			echo '<b>Unable to connect to MySQL database</b> (#' . $e->getCode() . ')<br/>';
			echo '<i>' . $e->getMessage() . '</i>';
			echo '</div>';
			die(ob_get_clean());
		}
	}
	
	// Adds a connection to the DBi::$databases array
	public static function add($connection, $key = null) {
		if(self::isConnection($connection)) {
			if($key === null) {
				self::$databases[] = $connection;
			} else {
				self::$databases[$key] = $connection;
			}
			if(self::$autoSelect === true) {
				self::set($connection);
			}
			return true;
		}
		return false;
	}
	
	// Sets a connection to the currently used connection
	public static function set($connection) {
		if(self::isConnection(self::get($connection))) {
			self::$currentConnection = self::get($connection);
		} elseif(self::isConnection($connection)) {
			self::$currentConnection = $connection;
		}
	}
	
	// Returns a connection out of the DBi::$databases array or DBi::$currentConnection
	public static function get($key = null) {
		if($key === null) {
			if(self::$currentConnection !== null) {
				return self::$currentConnection;
			} elseif(count(self::$databases) === 1) {
				return array_shift(array_values(self::$databases));
			}
		} else {
			if(array_key_exists($key, self::$databases)) {
				return self::$databases[$key];
			} elseif(is_int($key) AND array_key_exists($key, $arrayValues = array_values(self::$databases))) {
				return $arrayValues[$key];
			}
		}
		return null;
	}
	
	// Checks if a connection is a valid MySQL connection
	public static function isConnection($connection) {
		return (
			$connection !== null AND
			gettype($connection) == 'object' AND
			strtolower(get_class($connection)) == 'mysqli' AND
			$connection->connect_errno === 0
		) ? true : false;
	}
	
	// Escapes a string with mysqli_real_escape_string
	public static function escape($var, $connection = null) {
		if(self::isConnection($connection)) {
			return $connection->real_escape_string($var);
		} elseif(self::isConnection(self::get())) {
			return self::get()->real_escape_string($var);
		}
		// at least do something... (throw DBiException)
		return mysql_escape_string($var);
	}
	
	// Shortcut for DBi::escape()
	public static function e() {
		return call_user_func_array('DBi::escape', func_get_args());
	}
	
}