<?php
namespace CBSeraing;
include('cbseraing.config.class.php');
include('cbseraing.sql.class.php');
include('cbseraing.forum.class.php');
include('cbseraing.gallery.class.php');
include('cbseraing.ajax.class.php');

class cbseraing {
	private $profile  = 'photos/profile/';
	private $guest    = 99;
	
	private $type = array();
	private $user = null;
	
	private $layout;
	public $sql;
	public $forum;
	
	private $menu_highlight = array();
	private $menu = array(
		'/accueil'  => 'Accueil',
		'/comite'   => 'Notre comité',
		'/folklore' => 'Folklore',
		'/chants'   => 'Chants',
		'/forum'    => 'Forum',
		'/albums'   => 'Photos',
		'/contact'  => 'Contact',
	);
	
	private $skiptypes = array(
		5 => true
	);
	
	function __construct($layout, $init = true) {
		$this->layout = $layout;
		$this->layout->custom_add('SITE_BASE', config::$base);
		
		$this->sql = new sql(config::$sql_serv, config::$sql_user, config::$sql_pass, config::$sql_db);
		$this->sql->production(config::$prod);
		
		setlocale(LC_ALL, 'fr_BE.UTF-8');

		if($init)
			session_start();
		
		//
		// access list
		//
		$req = $this->sql->query('SELECT * FROM cbs_acl');
		while(($data = $this->sql->fetch($req)))
			$this->acl[$data['uid']] = $data['level'];
		
		//
		// users types
		//
		$req = $this->sql->query('SELECT * FROM cbs_types');
		while(($data = $this->sql->fetch($req)))
			$this->type[$data['id']] = array('type' => $data['type'], 'name' => $data['name']);
		
		//
		// initializing page
		//
		if(!isset($_GET['page']))
			$_GET['page'] = 'accueil';
	}
		
	function strip($url) {
		$string = str_replace(array('?', '!', '#'), '', $url);
		
		$a = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿŔŕ /1234567890';
		$b = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyRr--XXXXXXXXXX';
		
		$string = utf8_decode($string);
		$string = strtr($string, utf8_decode($a), $b);
		$string = strtolower($string);
		
		return utf8_encode($string);
	}
	
	function urlstrip($id, $name) {
		return $id.'-'.$this->strip($name);
	}
	
	function urlslash($id, $name) {
		return $id.'/'.$this->strip($name);
	}
	
	
	
	// FIXME
	function forum_periods() {
		$periods = array();
		
		$req = $this->sql->query('
			SELECT name, UNIX_TIMESTAMP(value) x FROM cbs_frmperiods
			ORDER BY value DESC
		');
		
		while($data = $req->fetch_array()) {
			$periods[] = array(
				'name' => $data['name'],
				'value' => $data['x']
			);
		}
		
		return $periods;
	}
	
	//
	// handlers
	//
	function loguser($user) {
		$_SESSION['loggedin'] = true;
		$_SESSION['uid'] = $user['id'];
		$_SESSION['name'] = ($user['surnom'] != '') ? $user['surnom'] : $user['nomreel'];
		$_SESSION['picture'] = $user['picture'];
		$_SESSION['longname'] = $this->username($user);
		
		$req = $this->sql->prepare('UPDATE cbs_membres SET dervis = NOW() WHERE id = ?');
		$req->bind_param('i', $user['id']);
		$this->sql->exec($req);
	}
	
	//
	// perform a login check with given user/password
	// if granted, set $_SESSION according user informations
	//
	function login($username, $password, $keep = false) {		
		$req = $this->sql->prepare('
			SELECT * FROM cbs_membres
			WHERE email = ? AND code = MD5(?)
		');
		
		$req->bind_param('ss', $username, $password);
		$data = $this->sql->exec($req);
		
		if(count($data) == 0)
			return false;
		
		$user = $data[0];
		$this->loguser($user);
		
		// keep logged in
		if($keep) {
			$token = sha1(time().rand(0, 42).'lulz'.$user['id']);
			
			$req = $this->sql->prepare('INSERT INTO cbs_tokens (uid, token) VALUES (?, ?)');
			$req->bind_param('is', $user['id'], $token);
			$this->sql->exec($req);
			
			// set token cookie persistant (1 year)
			setcookie('token', $token, time() + (60 * 60 * 24 * 365));
		}
		
		return true;
	}
	
	//
	// validate a session with a token (persistance)
	//
	function token($token) {
		$req = $this->sql->prepare('
			SELECT t.*, u.* FROM cbs_tokens t, cbs_membres u
			WHERE token = ? AND u.id = t.uid
		');
		
		$req->bind_param('s', $token);
		$data = $this->sql->exec($req);
		
		// invalid token
		if(count($data) == 0)
			return;
		
		$this->loguser($data[0]);
	}
	
	//
	// disconnect a user
	//
	function disconnect() {
		if(!$this->connected())
			return;
		
		unset($_SESSION['loggedin']);
		unset($_SESSION['type']);
		unset($_SESSION['uid']);
		unset($_SESSION['name']);
		unset($_SESSION['picture']);
		unset($_SESSION['longname']);
		
		// destroy persistance
		if(isset($_COOKIE['token'])) {
			// invalidate session
			$req = $this->sql->prepare('DELETE FROM cbs_tokens WHERE token = ?');
			$req->bind_param('s', $_COOKIE['token']);
			$this->sql->exec($req);
			
			// removing cookie
			setcookie('token', '');
		}
	}
	
	function connected() {
		return (isset($_SESSION['loggedin']) && $_SESSION['loggedin']);
	}
	
	function allowed($requestlevel, $option = NULL) {
		if(!$this->connected())
			$this->user = array('type' => 99);
			
		//
		// reading user information on database if not already in cache
		//
		if(!$this->user) {
			$req = $this->sql->prepare('SELECT * FROM cbs_membres WHERE id = ?');
			$req->bind_param('i', $_SESSION['uid']);
			
			$data = $this->sql->exec($req);
			$this->user = $data[0];
		}
			
		switch($requestlevel) {
			case 'albums':
				$allowed = array(1, 2, 3, 4);
				return in_array($this->user['type'], $allowed);
			break;
			
			case 'comite':
				$allowed = array(0 => true, 1 => true, 2 => true, 3 => true, 4 => true);
				return isset($allowed[$option]);
			break;
		}
	}
	
	function usertype() {
		if($this->connected()) {
			$user = $this->userdata($_SESSION['uid']);
			return $user['type'];
		}
		
		return $this->guest;
	}
	
	//
	// helpers
	//
	
	//
	// return the filename of a user profil picture
	// (or a default picture if not set)
	//
	function picture($filename) {
		if($filename == '')
			return 'images/no-picture.jpg';
		
		return 'photos/profile/'.$filename;
	}
	
	//
	// getters
	//
	function types() {
		$types = array();
		
		$req = $this->sql->query('SELECT * FROM cbs_types');
		while($data = $this->sql->fetch($req))
			$types[$data['id']] = $data['name'];
		
		return $types;
	}
	
	function songthemes() {
		$categories = array();
		
		$req = $this->sql->query('SELECT * FROM cbs_chants_cats');
		while($data = $this->sql->fetch($req))
			$categories[$data['id']] = $data['name'];
		
		return $categories;
	}
	
	function gettype($type) {
		if(isset($this->type[$type]['name']))
			return $this->type[$type]['name'];
		
		return '(inconnu)';
	}
	
	
	
	//
	// read user data from database
	//
	function userdata($id) {
		$req = $this->sql->prepare('SELECT * FROM cbs_membres WHERE id = ?');
		$req->bind_param('i', $id);
		$data = $this->sql->exec($req);
		
		if(count($data) == 0)
			return null;
		
		return $data[0];
	}
	
	//
	// format a long name from user (if possible):
	//  - surnom (nomreel)
	//  - surnom
	//  - nomreel
	//
	function username($user) {
		$name = null;
		
		if($user['surnom'] != '')
			$name = $user['surnom'];
			
		if($user['nomreel'] != '')
			$name .= ($name) ? ' ('.$user['nomreel'].')' : $user['nomreel'];
		
		return $name;
	}
	
	//
	// return 'surnom' if set, nomreel otherwise
	//
	function shortname($user) {
		if($user['surnom'] != '')
			return $user['surnom'];
		
		return $user['nomreel'];
	}
	
	//
	// return 'année baptême' if set and if not 'bleu'
	//
	function baptise($date, $type) {
		if($type == 0 && $date == 0)
			return 'Non baptisé';
		
		if($type == 0)
			return 'Non baptisé (tentative '.$date.')';
		
		if($date == 0)
			return '';
		
		return 'Baptisé '.$date;
	}
	
	//
	// special pages
	//
	
	//
	// format layout and display a user information block
	//
	function user($user) {
		if(!$this->allowed('comite', $user['type']))
			$this->layout->set('header', 'Nos amis:');
		
		$this->layout->custom_add('CUSTOM_MEMBER_ID', $user['id']);
		$this->layout->custom_add('CUSTOM_MEMBER_URL', $this->urlslash($user['id'], $this->shortname($user)));
		$this->layout->custom_add('CUSTOM_NAME', $this->username($user));
		$this->layout->custom_add('CUSTOM_PICTURE', $this->picture($user['picture']));
		$this->layout->custom_add('CUSTOM_BAPTISE', $this->baptise($user['anbapt'], $user['type']));
		$this->layout->custom_add('CUSTOM_ACTUELLEMENT', $user['actu']);
		$this->layout->custom_add('CUSTOM_TITRES', nl2br($user['titres']));
		$this->layout->custom_add('CUSTOM_ETUDES', nl2br($user['etudes']));
		$this->layout->custom_add('CUSTOM_RANG', $user['fonction']);
		$this->layout->custom_add('CUSTOM_ETOILES', $this->stars($user['etoiles']));
		
		$this->layout->container_append(
			$this->layout->parse_file_custom('layout/comite.list.layout.html')
		);
	}
	
	//
	// list all members with a specific type
	//
	function comite($type, $comite = 1) {
		if(!$this->allowed('comite', $type)) {
			$this->layout->set('header', 'Nos amis:');
			$comite = 0;
		}
		
		//
		// easter egg: fake sql injection
		//
		if(is_string($type) && $type[0] == "'")
			die("Bravo, tu as trouvé la « faille » d'injection SQL !
			    Tu peux maintenant aller affoner Maxux et recevoir ta signature :D");
		
		$req = $this->sql->prepare('
			SELECT *
			FROM cbs_membres WHERE type = ? AND comite = ?
			ORDER BY ordre DESC, anbapt DESC
		');
		
		$req->bind_param('ii', $type, $comite);
		$data = $this->sql->exec($req);
		
		if(count($data) == 0 || isset($this->skiptypes[$type]))
			return $this->layout->error_append('Personne pour le moment');
		
		foreach($data as $user)
			$this->user($user);
	}
	
	//
	// display a single member data
	//
	function membre($id) {
		if(!($user = $this->userdata($id)))
			return $this->layout->error_append('Membre introuvable');
		
		$this->user($user);
	}
	
	function stars($stars) {
		$list = array(
			'*' => 'doree',
			'+' => 'argent',
			'r' => 'erasmus',
			'R' => 'fossile-erasmus',
			'F' => 'fossile-doree',
			'f' => 'fossile-argent',
			'b' => 'bougeois'
		);
		
		$data = array();
		
		for($i = 0; $i < strlen($stars); $i++) {
			$star = $stars[$i];
			
			// unknown key
			if(!isset($list[$star]))
				continue;
			
			$data[] = '<span class="'.$list[$star].'"></span>';
		}
		
		return implode($data);
	}
	
	function profile($edit = false) {
		$user = $this->userdata($_SESSION['uid']);
			
		$this->layout->custom_add('CUSTOM_MEMBER_ID', $user['id']);
		$this->layout->custom_add('CUSTOM_NAME', $this->username($user));
		$this->layout->custom_add('CUSTOM_PICTURE', $this->picture($user['picture']));
		$this->layout->custom_add('CUSTOM_ACTUELLEMENT', $user['actu']);
		$this->layout->custom_add('CUSTOM_TITRES', nl2br($user['titres']));
		$this->layout->custom_add('CUSTOM_ETUDES', nl2br($user['etudes']));
		$this->layout->custom_add('CUSTOM_TITRES_PLAIN', $user['titres']);
		$this->layout->custom_add('CUSTOM_ETUDES_PLAIN', $user['etudes']);
		$this->layout->custom_add('CUSTOM_RANG', $this->gettype($user['type']));
		$this->layout->custom_add('CUSTOM_EMAIL', $user['email']);
		$this->layout->custom_add('CUSTOM_SURNOM', $user['surnom']);
		$this->layout->custom_add('CUSTOM_NOM_REEL', $user['nomreel']);
		$this->layout->custom_add('CUSTOM_ETOILES', $this->stars($user['etoiles']));
		$this->layout->custom_add('CUSTOM_ETOILES_PLAIN', $user['etoiles']);
		$this->layout->custom_add('CUSTOM_BAPTEME', $user['anbapt']);
		$this->layout->custom_add('CUSTOM_BAPTISE', $this->baptise($user['anbapt'], $user['type']));
		
		if($edit)
			$this->layout->file('layout/profile.edit.layout.html');
			
		else $this->layout->file('layout/profile.layout.html');
	}
	
	//
	// lists songs on a specific category
	//
	function songs($category) {
		$req = $this->sql->prepare('SELECT * FROM cbs_chants WHERE cat = ?');
		$req->bind_param('i', $category);
		$data = $this->sql->exec($req);
		
		if(count($data) == 0)
			return $this->layout->error_append('Catégories introuvable');
		
		foreach($data as $song) {
			$this->layout->custom_add('CUSTOM_TITLE', $song['titre']);
			$this->layout->custom_add('CUSTOM_SONG', nl2br($song['chant']));
			
			$this->layout->container_append(
				$this->layout->parse_file_custom('layout/chant.list.layout.html')
			);
		}
	}
	
	function agenda() {
		$req = $this->sql->query('SELECT *, UNIX_TIMESTAMP(date_ev) udate FROM cbs_agenda WHERE date_ev >= DATE(NOW())');
		if($req->num_rows == 0) {
			$this->layout->container_append('{{ERRORS}}');
			return $this->layout->error_append("Rien de prévu pour l'instant");
		}
			
		while(($event = $this->sql->fetch($req))) {
			$this->layout->custom_add('CUSTOM_TITLE', $event['descri']);
			$this->layout->custom_add('CUSTOM_LOCATION', $event['lieu']);
			$this->layout->custom_add('CUSTOM_DATE', 'Le '.date('d/m/Y', $event['udate']));
			$this->layout->custom_add('CUSTOM_THEME', $event['theme'] ? $event['theme'] : '(nope)');
			
			$this->layout->container_append(
				$this->layout->parse_file_custom('layout/agenda.layout.html')
			);
		}
	}
	
	function events() {		
		$req = $this->sql->query('
			SELECT *, UNIX_TIMESTAMP(date) uts FROM cbs_events
			ORDER BY date
		');
		
		while($data = $req->fetch_assoc()) {
			$events[$data['id']]['event'] = $data;
			$events[$data['id']]['going'] = array();
		}

		$req = $this->sql->query('
			SELECT e.*, u.type, u.nomreel, u.surnom
			FROM cbs_events_going e, cbs_membres u
			WHERE u.id = e.uid
			ORDER BY u.type
		');
		
		while($data = $req->fetch_assoc()) {
			$events[$data['eid']]['going'][$data['uid']] = $data;
			
			if(isset($events[$data['eid']]['going_type'][$data['type']]))
				$events[$data['eid']]['going_type'][$data['type']]++;
				
			else $events[$data['eid']]['going_type'][$data['type']] = 1;
		}
		
		if(!isset($events)) {
			$this->layout->container_append('{{ERRORS}}');
			return $this->layout->error_append("Rien de prévu pour l'instant");
		}
			
		foreach($events as $event) {
			$this->layout->custom_add('CUSTOM_NAME', $event['event']['name']);
			$this->layout->custom_add('CUSTOM_DATE', ucfirst(strftime('%A %d %B %G', $event['event']['uts'])));
			$this->layout->custom_add('CUSTOM_ID', $event['event']['id']);
			
			//
			// Who is going ?
			//
			$count = count($event['going']);				
			$this->layout->custom_add('CUSTOM_GOING', $count);
			$this->layout->custom_add('CUSTOM_ARE_GOING', ($count > 1) ? 'participants' : 'participant');
			
			$list = array();
			foreach($event['going'] as $user)
				$list[] = $this->shortname($user);
				
			$this->layout->custom_add('CUSTOM_GOING_LIST', implode(", ", $list));
			
			//
			// I'm going
			//
			if(isset($_SESSION['uid'])) {
				$going = isset($event['going'][$_SESSION['uid']]) ? "J'y vais plus :(" : "J'y vais !";
			
			} else $going = '(vous devez vous connecter)';
			
			$this->layout->custom_add('CUSTOM_IMGOING', $going);
			
			$this->layout->container_append(
				$this->layout->parse_file_custom('layout/events.event.layout.html')
			);
		}
	}
	
	function going($id) {
		$req = $this->sql->prepare('SELECT * FROM cbs_events_going WHERE eid = ? AND uid = ?');		
		$req->bind_param('ii', $id, $_SESSION['uid']);
		$data = $this->sql->exec($req);
		
		//
		// not already going
		//
		if(count($data) == 0) {
			$req = $this->sql->prepare('INSERT INTO cbs_events_going (eid, uid) VALUES (?, ?)');
			
		} else $req = $this->sql->prepare('DELETE FROM cbs_events_going WHERE eid = ? AND uid = ?');
		
		$req->bind_param('ii', $id, $_SESSION['uid']);
		$this->sql->exec($req);
	}
	
	//
	// news manager
	//
	function news($backlog) {
		$req = $this->sql->prepare('
			SELECT *, UNIX_TIMESTAMP(`when`) utime FROM cbs_news 
			ORDER BY `when` DESC LIMIT '.$backlog
		);
		
		return $this->sql->exec($req);
	}
	
	function homepage() {
		$news = $this->news(1);
		$news = $news[0];
		
		$intro = ($news['utime'] < time()) ? 'Dernier évènement' : 'Prochain évènement';
		$when = strftime('Le %A %e %B %Y à %Hh%M', $news['utime']);
			
		$this->layout->custom_add('EVENT_ID', $news['id']);
		$this->layout->custom_add('EVENT_INTRO', $intro);
		$this->layout->custom_add('EVENT_NAME', $news['name']);
		$this->layout->custom_add('EVENT_COVER', $news['cover']);
		$this->layout->custom_add('EVENT_DESCRIPTION', $news['description']);
		$this->layout->custom_add('EVENT_WHERE', $news['where']);
		$this->layout->custom_add('EVENT_WHEN', $when);
		$this->layout->custom_add('EVENT_ORIPEAUX', $news['oripeaux']);
		$this->layout->custom_add('EVENT_LINK', $news['link']);
		
		$this->layout->file('layout/home.layout.html');
	}
	
	//
	// updaters
	//
	function updatepic($checksum) {
		$user = $this->userdata($_SESSION['uid']);
		
		//
		// remove previous profile picture (if different)
		//
		$filename = $this->profile.$user['picture'];
		
		if(file_exists($filename) && md5(file_get_contents($filename)) != $checksum)
			unlink($filename);
		
		//
		// update picture on database
		//
		$filename = $checksum.'.jpg';
		
		$req = $this->sql->prepare('UPDATE cbs_membres SET picture = ? WHERE id = ?');
		$req->bind_param('si', $filename, $_SESSION['uid']);
		$this->sql->exec($req);
		
		$_SESSION['picture'] = $filename;
	}
	
	//
	// check if file is an image
	//
	function image($filename) {
		$finfo = new \finfo(FILEINFO_MIME);
		return (substr($finfo->file($filename), 0, 10) == 'image/jpeg');
	}
	
	//
	// stages
	//
	// stage 1: - login/disconnection request checking/handling
	//          - building menu according user status
	//
	// stage 2: - handling upload/post/user modification
	//
	// stage 3: - parsing page and displaying requested page
	//
	function stage1() {		
		//
		// need to check login
		//
		if(isset($_GET['login'])) {
			if(!$this->login($_REQUEST['login'], $_REQUEST['password'], isset($_REQUEST['keeplog']))) {
				$this->layout->error_append('Login ou mot de passe invalide');
				$_GET['page'] = 'login';
			}
		}
		
		//
		// checking for persistant session to restore
		//
		if(!$this->connected() && isset($_COOKIE['token']))
			$this->token($_COOKIE['token']);
		
		//
		// request a disconnection
		//
		if(isset($_GET['disconnect']))
			$this->disconnect();
		
		//
		// initializing forum subsystem
		//
		$this->forum = new forum($this, $this->layout);
		// $this->layout->custom_add('CUSTOM_UNREAD', );
		if($this->forum->unreads() > 0)
			$this->menu_highlight['/forum'] = 'newmessage';
		
		//
		// starting ajax-request if needed
		//
		if(isset($_GET['ajax'])) {
			new ajax($this);
			die();
		}
		
		//
		// initializing menu
		//
		$this->layout->menu($this->menu, '/'.$_GET['page'], $this->menu_highlight);
		
		//
		// now we are connected or disconnected, checking granted access or not
		//
		if(isset($_SESSION['loggedin']) && $_SESSION['loggedin']) {
			// update request
			$this->stage2();
			
			$this->layout->custom_add('MENU_PICTURE', $this->picture($_SESSION['picture']));
			$this->layout->custom_add('MENU_USERNAME', $_SESSION['name']);
			$this->layout->custom_add('MENU_LONGNAME', $_SESSION['longname']);
			$this->layout->custom_add('MENU_LOGIN', $this->layout->parse_file_custom(
				'layout/menu.logged.layout.html'
			));
			
		} else $this->layout->custom_add('MENU_LOGIN', '<a href="/login">Se connecter</a>');
		
		//
		// page dispatcher
		//
		$this->stage3();
	}
	
	function stage2() {
		if(!$this->connected()) {
			$this->layout->error_append('Non autorisé');
			return;
		}
		
		//
		// upload profile picture
		//
		if(isset($_GET['picture'])) {
			if(!$this->image($_FILES['filename']['tmp_name'])) {
				$this->layout->error_append("Format d'image invalide");
				return;
			}
			
			$input    = $_FILES['filename']['tmp_name'];
			$content  = file_get_contents($input);
			$checksum = md5($content);
			$output   = $this->profile.$checksum.'.jpg';
			
			if(move_uploaded_file($input, $output)) {
				$this->updatepic($checksum);
				header('Location: /profile');
				
			} else $this->layout->error_append('Erreur lors de la mise à jour de la photo de profil');
		}
		
		//
		// change loal settings
		//
		if($_GET['page'] == 'settings' && isset($_GET['save'])) {
			$req = $this->sql->prepare('
				UPDATE cbs_membres SET
				surnom = ?, nomreel = ?, actu = ?, etoiles = ?, anbapt = ?, titres = ?, etudes = ?
				WHERE id = ?
			');
			
			if($_POST['surnom'] == '' && $_POST['nomreel'] == '') {
				$this->layout->error_append('Vous devez spécifier au moins un surnom ou votre nom réel');
				
				$_GET['page'] = 'settings';
				return;
			}
			
			$req->bind_param('ssssissi',
				$_POST['surnom'],
				$_POST['nomreel'],
				$_POST['actu'],
				$_POST['etoiles'],
				$_POST['bapteme'],
				$_POST['titres'],
				$_POST['etudes'],
				$_SESSION['uid']
			);
			
			$this->sql->exec($req);
			header('Location: /profile');
		}
		
		//
		// change password
		//
		if($_GET['page'] == 'profile' && isset($_GET['password'])) {
			$user = $this->userdata($_SESSION['uid']);
			
			if($user['code'] != md5($_POST['before']))
				return $this->layout->error_append("Le mot de passe actuel est invalide");
			
			if($_POST['after'] == '')
				return $this->layout->error_append("Le nouveau mot de passe est vide");
			
			$req = $this->sql->prepare('UPDATE cbs_membres SET code = MD5(?) WHERE id = ?');
			$req->bind_param('si', $_POST['after'], $_SESSION['uid']);
			$this->sql->exec($req);
		}
		
		//
		// forum post
		//
		if($_GET['page'] == 'forum' && isset($_GET['create'])) {
			if(!isset($_POST['subject']) || $_POST['subject'] == '')
				return $this->layout->error_append("Vous devez entrer un sujet");
			
			if(!isset($_POST['message']) || $_POST['message'] == '')
				return $this->layout->error_append("Vous devez entrer un message");
			
			if(!isset($_POST['category']) || $_POST['category'] == '')
				return $this->layout->error_append("Erreur de catégorie");
			
			// create subject and post original message
			$subject = $this->forum->create($_POST['category'], $_SESSION['uid'], $_POST['subject']);
			$this->forum->reply($subject, $_SESSION['uid'], $_POST['message']);
			
			header('Location: /forum/subject/'.$this->urlstrip($subject, $_POST['subject']));
		}
		
		//
		// forum response
		//
		if($_GET['page'] == 'forum' && isset($_GET['reply'])) {
			if(!isset($_POST['message']) ||  $_POST['message'] == '')
				return $this->layout->error_append("Votre message est vide");
			
			if(!isset($_POST['subject']) || $_POST['subject'] == '')
				return $this->layout->error_append("Erreur de sujet");
			
			// post reply
			$subject = $this->forum->reply($_POST['subject'], $_SESSION['uid'], $_POST['message']);
			
			header('Location: /forum/subject/'.$this->urlstrip($_POST['subject'], $subject['subject']));
		}
		
		//
		// forum all-read
		//
		if($_GET['page'] == 'forum' && isset($_GET['allread']) && $this->connected())
			$this->forum->allread();
		
		if($_GET['page'] == 'forum' && isset($_GET['edit']) && isset($_GET['save'])) {
			if(($id = $this->forum->update($_POST['messageid'])) != false)
				header('Location: /forum/subject/'.$this->urlstrip($id, '#'));
		}
		
		if($_GET['page'] == 'forum' && isset($_GET['hide'])) {
			if(($id = $this->forum->hide($_GET['id'])) != false)
				header('Location: /forum/subject/'.$this->urlstrip($id, '#'));
		}
		
		//
		// Updating events going
		//
		if($_GET['page'] == 'events' && isset($_GET['toggle']))
			$this->going($_GET['toggle']);
	}
	
	function stage3() {
		//
		// new account
		//
		if($_GET['page'] == 'newaccount' && isset($_GET['save'])) {
			$req = $this->sql->prepare('
				INSERT INTO cbs_membres (nomreel, code, email, mobile)
				VALUES (?, MD5(?), ?, ?)
			');
			
			$req->bind_param('ssss', $_POST['nomreel'], $_POST['password'], $_POST['email'], $_POST['gsm']);
			$this->sql->exec($req);
			
			$_GET['page'] = 'newaccount-confirm';
		}
		
		switch($_GET['page']) {
			case 'login':
				$this->layout->set('header', 'Se connecter:');
				$this->layout->file('layout/login.layout.html');
			break;
			
			case 'folklore':
				if(!isset($_GET['sub']))
					$_GET['sub'] = null;
				
				
				$list = array(
					'./folklore/bleu'     => 'Guide du bleu',
					'./folklore/insignes' => 'Insignes',
					'./folklore/comites'  => 'Comités',
				);
				
				$list = $this->layout->items($list, './folklore/'.$_GET['sub']);
				$this->layout->submenu('layout/submenu.layout.html', $list);
				
				switch($_GET['sub']) {
					case 'bleu':
						$this->layout->set('header', 'Le guide du bleu:');
						$this->layout->file('layout/folklore.bleu.layout.html');
					break;
					
					case 'insignes':
						$this->layout->set('header', 'Les insignes:');
						$this->layout->file('layout/folklore.insignes.layout.html');
					break;
					
					case 'comites':
						$this->layout->set('header', 'Les comités:');
						$this->layout->file('layout/folklore.comites.layout.html');
					break;
					
					default:
						$this->layout->set('header', 'Le folklore:');
						$this->layout->file('layout/folklore.layout.html');
				}
			break;
			
			case 'chants':
				if(!isset($_GET['sub']))
					$_GET['sub'] = 7; // default value (chant cbs)
				
				//
				// get types from database
				//
				$types = $this->songthemes();
				
				//
				// building menu
				//
				$url = array();
				foreach($types as $id => $name)
					$url['/chants/'.$this->urlstrip($id, $name)] = $name;
					
				$list = $this->layout->items(
					$url,
					'/chants/'.$this->urlstrip($_GET['sub'], isset($_GET['name']) ? $_GET['name'] : '')
				);
				$this->layout->submenu('layout/submenu.layout.html', $list);
				
				//
				// working
				//
				$this->layout->set('header', 'Les chants:');
				$this->songs($_GET['sub']);
			break;
			
			case 'forum':
				if(isset($_GET['category'])) {
					$this->forum->subjects($_GET['category']);
					
				} else if(isset($_GET['subject'])) {
					if(!isset($_GET['pid']))
						$_GET['pid'] = 1;
						
					$this->forum->post($_GET['subject'], $_GET['pid']);
				
				} else if(isset($_GET['edit']) && !isset($_GET['save'])) {
					$this->forum->edit($_GET['id']);
					
				} else $this->forum->categories();
				
				$this->layout->container_append(
					$this->layout->parse_file_custom('layout/forum.layout.html')
				);		
			break;
			
			case 'albums':
			case 'photos':
				$gallery = new gallery($this, $this->layout);
				
				if(!isset($_GET['album']))
					$_GET['album'] = 0;
					
				$gallery->albums($_GET['album']);
			break;
			
			case 'contact':
				$this->layout->set('header', 'Nous contacter:');
				$this->layout->file('layout/contact.layout.html');
			break;
			
			case 'comite':
				if(!isset($_GET['sub']))
					$_GET['sub'] = null;
				
				//
				// get types from database
				//
				$types = $this->types();
				unset($types[$this->guest]);
				
				//
				// building submenu
				//
				$url = array();
				foreach($types as $id => $name) {
					if(isset($this->skiptypes[$id]))
						continue;
					
					$url['/comite/'.$this->urlstrip($id, $name.'s')] = $name.'s'; // s for plurial
				}
					
				$list = $this->layout->items(
					$url,
					'/comite/'.$this->urlstrip($_GET['sub'], isset($_GET['name']) ? $_GET['name'] : '')
				);
				
				$this->layout->submenu('layout/submenu.layout.html', $list);
				
				//
				// displaying content
				//
				$this->layout->set('header', 'Notre comité:');
				$this->layout->container_append('{{ERRORS}}');
				
				if($_GET['sub'] != null)
					$this->comite($_GET['sub']);
				
				else if(isset($_GET['membre']))
					$this->membre($_GET['membre']);
					
				// default page
				else $this->layout->file('layout/comite.layout.html');
			break;
			
			case 'profile':
				if(!$this->connected())
					header('Location: /login');
				
				
				$this->layout->set('header', 'Mon profil:');
				$this->profile();
			break;
			
			case 'settings':
				if(!$this->connected())
					header('Location: /login');
				
				
				$this->layout->set('header', 'Modifier mon profil:');
				$this->profile(true);
			break;
			
			case 'newaccount':
				$this->layout->set('header', 'Créer un compte:');
				$this->layout->file('layout/newaccount.layout.html');
			break;
			
			case 'newaccount-confirm':
				$this->layout->set('header', 'Compte créé !');
				$this->layout->file('layout/newaccount.confirm.layout.html');
			break;
			
			case 'agenda':
				$this->layout->set('header', 'Agenda des activités:');
				$this->agenda();
			break;
			
			case 'events':
				$this->layout->set('header', 'Les events:');
				$this->events();
			break;
			
			default:
				$this->layout->set('header', 'Accueil:');
				$this->homepage();
			break;
		}
	}
}
?>
