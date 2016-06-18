DBi
===

This framework allows to handle multiple PDO connections and to send queries with prepared statements and named parameters.

— [Wolfgang Drescher](http://wolfgangdrescher.ch/)

Features
--------

- Prepared statements
- Named parameters
- Echoing the query object will display a table with results
- Method chaining e.g. `$id = Query::init($insertSql)->send()->insertId();`
- Execute statements instantly with `Query::exec()`
- Nicely designed error messages with Bootstrap

Requirements
------------

- A server running at least PHP version 5.3.
- Result dumps and error messages are formated with [Bootstrap](http://getbootstrap.com/).

License
-------

This framework is standing under MIT licence. Feel free to use it, but please place a reference to my name or website.

Setup
-----

Include the Bootstrap stylesheet to your websites header. Either use the following Bootstrap CDN link, or [download it directly](http://getbootstrap.com/getting-started/#download) from their server.

	<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap.min.css">

Include in your config PHP file either DBi.php or Query.php. You can make the following configurations:

	// your config.php
	require_once 'DBi.php';
	DBi::$autoSelect = false; // (default: true) disable auto selecting a connection with `DBi::add()`
	Query::$throwExceptions = false; // (default: true) disable error messages
	Query::$autoSend = true; // (default: false) enable automatically sending queries when a new query object is created

I recommend the setting `Query::$throwExceptions` to `false` in a productive environment.

DBi.php
-------

Connect to a database with `DBi::connect()`. This method returns an object which represents the PDO connection to a SQL server.
	
	$handle = DBi::connect('mysql:host=localhost;dbname=cms;', 'root', 'root');

You can add a connection to the DBi class with `DBi::add()`. If the variable `DBi::$autoSelect` is set to true (default) `DBi::add()` will automatically set the passed connection as the currently used connection.

	DBi::add(DBi::connect($server, $user, $password));

Multiple connections at the same time are possible. Set the current connection with `DBi::set($key)` and use the same key as second argument in `DBi::add()`.

	DBi::$autoSelect = false;
	DBi::add(DBi::connect($server, $user, $password), 'live');
	DBi::add(DBi::connect($server, $user, $password), 'debug');
	DBi::set('live');
	// do some stuff in the live database
	DBi::set('debug');
	// do some other stuff in the debug database

You can get a connection with `DBi::get()`. This function *will not* set the current connection.

	DBi::get(); // returns the current connection
	DBi::get('live'); // returns a specific connection

Query.php
---------

Use SQL strings like these examples:

	$sqlNamedParams = "SELECT * FROM user WHERE email = :email LIMIT :limit";
	$sqlParams = "SELECT * FROM user WHERE email = ? LIMIT ?";
	$sql = "SELECT * FROM user WHERE email = '".DBi::e($email)."' LIMIT ".intval($limit);
	$insertSql = "INSERT INTO user SET email = :email";

However I recommend **always** using prepared statements like `$sqlNamedParams` and `$sqlParams`. But if you know what you are doing use `DBi::escape($str[, $connection])` or its shortcut `DBi::e()` to escape a sql string. You can also write your own function for escaping:

	function e() {
		return call_user_func_array('DBi::escape', func_get_args());
	}

Use `->prepare()` to set the SQL string. Bind named parameters as array with `->bindParams()`. This method will autodetect the type of the passed parameters (string, int, float). Execute a statement with `->send()`. The colons in front of the named parameters in `->bindParams()` are optional but they are required in the SQL string.

	$stmt = new Query();
	$stmt->prepare($sqlNamedParams);
	$stmt->bindParams(array(
		':limit' => 1,
		'email' => $email // colon automatically added by the class
	));
	$stmt->send();

Display the result of a query as table by echoing the query object.

	echo $stmt;

Set the connection for a single statement with `->setConnection()`. Remember to always set the connection at the very beginning directly after creating the Query class object.

	$stmt->setConnection(DBi::get('debug'));

Use the second argument of `->prepare()` to bind named parameters directly to it.

	$stmt->prepare($sqlNamedParams, array(
		'limit' => 1,
		':email' => $email
	));

The SQL string can also be passed as first argument of `new Query($sql)`. Bind parameters to an unnamed SQL string (params will replace the `?`) as comma seperated arguments of `->bindParams()`. This method will autodetect the type of bound parameters.

	$stmt = new Query($sqlParams);
	$stmt->bindParams($email, 1);

Bind a single parameter with `->bindParam()`. The first argument is the key of the named parameter in the SQL string, the second is the value, and the optional third will set the type (`Query::ParamStr`, `Query::ParamInt`, `Query::ParamFloat` and `Query::ParamLOB`). If the third parameter is not set the method will autodetect the value's type.

	$stmt->bindParam('email', $email, Query::ParamStr);
	$stmt->bindParam(':limit', 1); // autodetects type Query::ParamInt
	// $fp = fopen($_FILES['file']['tmp_name'], 'rb');
	// $stmt->bindParam(':file', $fp, Query::ParamLOB);

Named parameters can also be bound as an array argument of `->send()`.

	$stmt->send(array(
		':limit' => 1,
		':email' => $email
	));

Query methods can be chained. You can also chain a query in one single line with `Query::init()`.

	$stmt = new Query();
	$stmt->prepare($sqlParams)->bindParams($email, 1)->send();
	
	echo $id = Query::init($insertSql, array('email' => $email))->send()->insertId();

Execute statements instantly with `Query::exec()`. This method will always send a statement directly without considering the value of `Query::$autoSend`.

	echo $rows = Query::exec("SELECT * FROM user")->rows();

If you know what you are doing make a statement without parameters or the MySQLi prepare method. **Remember to escape the variables with `DBi::e()`**.

	$stmt = new Query();
	$stmt->setSql($sql)->send();

Set the static variable `Query::$autoSend` to true if you want to execute a statement automatically with `new Query()` or `Query::init()`.

	Query::$autoSend = true;
	$stmt = new Query($sqlNamedParams, array('limit' => 1, 'email' => $email));
	$stmt = Query::init($sqlParams, array($email, 1));

Get the duration of a statement with `->getDuration()` (in milliseconds). You can set the number of decimal points as an argument.

	echo $stmt->getDuration(5); // echo's milliseconds

Set the result pointer of the result stack with `->seek($index)`.

Fetch a result row with `->fetch($mode)`. The default fetching method will be `->fetchAssoc()` or `->fetchVar()` if there are any result parameters bound. `->fetchAssoc()` will return an associative array of the current result row.

	$stmt = new Query($sqlNamedParams, array('email' => $email, 'limit' => 1));
	$stmt->send();
	if($stmt->rows()) {
		while($row = $stmt->fetch()) {
			echo '<pre>'.print_r($row, true).'</pre>';
		}
	}

`->fetchRow()` will return a result row as an enumerated array.

Use `->fetchArray()` if you need a combined associative and enumerated array. Pass the string `both` (default), `assoc` or `num` as argument to select the type of the result array.

`->fetchObject()` will return the current row of the result set as an object (default object is `stdClass`).

	class User { /*...*/ }
	while($row = $stmt->fetchObject('User', array($arg1, $arg2))) {
		echo '<pre>'.print_r($row, true).'</pre>';
	}

`->fetchVar()` will set all variables bound with `->bindResult()` to the columns of the current result row.

	$stmt = Query::exec("SELECT id, name, email FROM user");
	$stmt->bindResult($id, $name, $email);
	while($stmt->fetch()) {
		echo "User-ID: $id, Name: $name, E-Mail: $email.";
	}

Note that `->bindResult(&$a,...&$z)` currently only supports 26 arguments, because I could not find another way to pass references to the class. If you need support for more parameters or want to make it the proper way use the native MySQLi functions for this. See issue #4.

	$stmt = Query::exec("SELECT id, name, email FROM user");
	$stmt->getStatement()->bind_result($id, $name, $email);
	while($stmt->getStatement()->fetch()) {
		echo "User-ID: $id, Name: $name, E-Mail: $email.";
	}

The method `->fetchAll()` will return an array with the complete result.

Get the whole result as a JSON string with `->getJSON()`.

Thanks for using
----------------

Contribute to this repository and help to improve this framework by [fixing issues](https://github.com/WolfgangDrescher/MySQLi/issues) and commenting them.