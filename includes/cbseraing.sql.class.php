<?php
namespace CBSeraing;

class sql {
	private $mysqli = NULL;
	private $prod = false;

	function __construct($server, $user, $password, $db) {
		$this->mysqli = new \mysqli($server, $user, $password, $db);
		$this->mysqli->set_charset('utf8');
	}

	function query($sql) {
		if(!($req = $this->mysqli->query($sql))) {
			if($this->prod)
				die('SQL (query) Error, please contact administrator');

			else echo 'SQL (query) Error <!-- '.$sql.' -->: '.$this->mysqli->error;
		}

		return $req;
	}

	function prepare($sql) {
		if(!($req = $this->mysqli->prepare($sql))) {
			if($this->prod)
				die('SQL (prepare) Error, please contact administrator');

			else echo 'SQL (prepare) Error <!-- '.$sql.' -->: '.$this->mysqli->error;
		}

		return $req;
	}

	function exec($stmt) {
		if(!($req = $stmt->execute())) {
			if($this->prod)
				die('SQL (exec) Error, please contact administrator');

			else echo 'SQL (exec) Error:'.$this->mysqli->error;
		}

		if(!($meta = $stmt->result_metadata()))
			return array();

		while($field = $meta->fetch_field())
			$parameters[] = &$row[$field->name];

		call_user_func_array(array($stmt, 'bind_result'), $parameters);

		$results = array();
		while($stmt->fetch()) {
			foreach($row as $key => $val)
				$x[$key] = $val;

			$results[] = $x;
		}

		return $results;
	}

	function fetch($req) {
		return $req->fetch_assoc();
	}

	function production($value) {
		$this->prod = $value;
	}
}
?>
