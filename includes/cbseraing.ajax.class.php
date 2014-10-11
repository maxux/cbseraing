<?php
namespace CBSeraing;

class ajax {
	private $root;
	
	function __construct($root) {
		$this->root = $root;
		
		header('Content-type: application/json');
		
		if(!$this->root->connected()) {
			echo $this->error('login required');
			return;
		}
		
		$this->stage1();
	}
	
	function error($message) {
		$data = array('status' => 'error', 'message' => $message);
		return json_encode($data);
	}
	
	function notifications() {
		$data = array('status' => 'success', 'unread' => $this->root->forum->unreads());
		return json_encode($data);
	}
	
	function stage1() {
		switch($_GET['ajax']) {
			case 'notifications':
				echo $this->notifications();
			break;
			
			default:
				echo $this->error('unknown request');
		}
	}
}
?>
