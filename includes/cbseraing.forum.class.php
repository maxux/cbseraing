<?php
namespace CBSeraing;

class forum {
	private $root;
	private $layout;
	private $type;
	
	private $parser = array(
		'nicks' => array(),
	);
	
	private $unread = array(
		'categories' => array(),
		'subjects' => array(),
		'messages' => array()
	);
	
	private $pictos = array(
		'edit' => 'pencil',
		'hide' => 'remove'
	);
	
	function __construct($root, $layout) {
		$this->root = $root;
		$this->layout = $layout;
		
		$this->layout->set('header', 'Le forum:');
		$this->type = $this->root->usertype();
		
		//
		// checking if there is unread post
		//
		if(!$this->root->connected())
			return;
			
		$req = $this->root->sql->prepare('
			SELECT m.id, m.subject, s.category
			FROM cbs_forum_messages m, cbs_membres u, cbs_forum_subjects s, cbs_forum_acl a
			WHERE m.id NOT IN (SELECT mid FROM cbs_forum_read WHERE uid = ?)
			  AND s.id = m.subject
			  AND u.id = ?
			  AND m.author != u.id
			  AND s.category = a.cid
			  AND a.tid = u.type
		');
		
		$req->bind_param('ii', $_SESSION['uid'], $_SESSION['uid']);
		$data = $this->root->sql->exec($req);
		
		foreach($data as $message) {
			$this->unread['messages'][$message['id']] = $message;
			$this->unread['categories'][$message['category']] = $message;
			$this->unread['subjects'][$message['subject']] = $message;
		}
	}
	
	//
	// generate error and break (or not)
	//
	function error($str, $fatal) {
		$this->layout->error_append($str);
		
		//
		// cancel layout
		//
		$this->layout->custom_add('FORUM', '');
		
		if($fatal) {
			$this->noitem();
			return false;
		}
		
	}
	
	//
	// generate glyphcons and links for edit/hide message
	//
	function request($type, $message) {
		if(!$this->root->connected())
			return '';
		
		if($message['author'] != $_SESSION['uid'])
			return '';
		
		return '<a href="/forum/'.$type.'/'.$message['id'].'">'.
		       '<span class="glyphicon glyphicon-'.$this->pictos[$type].'"></span>'.
		       '</a>';
	}
	
	function getpost($id) {
		$post = $this->root->sql->prepare('SELECT * FROM cbs_forum_messages WHERE id = ?');
		$post->bind_param('i', $id);
		$data = $this->root->sql->exec($post);
		
		return $data;
	}
	
	//
	// hide a post
	//
	function hide($id) {
		if(!$this->root->connected())
			return $this->error("Non autorisé", true);
			
		// reading post
		$data = $this->getpost($id);
		
		if(count($data) == 0)
			return $this->error("Ce message n'existe pas", true);
			
		$message = $data[0];
		
		if($message['author'] != $_SESSION['uid'])
			return $this->error("Nope.", true);
		
		// update
		$post = $this->root->sql->prepare('UPDATE cbs_forum_messages SET hidden = 1 WHERE id = ?');
		$post->bind_param('i', $id);
		$this->root->sql->exec($post);
		
		return $message['subject'];
	}
	
	//
	// update a post (edit)
	//
	function update($id) {		
		if(!$this->root->connected())
			return $this->error("Non autorisé", true);
			
		// reading post
		$data = $this->getpost($id);
		
		if(count($data) == 0)
			return $this->error("Ce message n'existe pas", true);
			
		$message = $data[0];
		
		if($message['author'] != $_SESSION['uid'])
			return $this->error("Nope.", true);
		
		// update
		$post = $this->root->sql->prepare('UPDATE cbs_forum_messages SET message = ? WHERE id = ?');
		$post->bind_param('si', $_POST['message'], $id);
		$this->root->sql->exec($post);
		
		return $message['subject'];
	}
	
	//
	// request the post-edit page
	//
	function edit($id) {
		if(!$this->root->connected())
			return $this->error("Non autorisé", true);
		
		// reading post	
		$data = $this->getpost($id);
		
		if(count($data) == 0)
			return $this->error("Ce message n'existe pas", true);
			
		$message = $data[0];
		
		if($message['author'] != $_SESSION['uid'])
			return $this->error("Nope.", true);
		
		$this->layout->custom_add('CUSTOM_MESSAGE', $message['message']);
		$this->layout->custom_add('CUSTOM_MESSAGE_ID', $id);
		$this->layout->custom_add('NEWITEM', '');
		
		$this->layout->custom_append('FORUM',
			$this->layout->parse_file_custom('layout/forum.edit.layout.html')
		);
	}
	
	//
	// access denied
	//
	function denied() {
		$this->error("Ce que vous avez demandé n'existe pas (ou vous n'y avez pas accès)", true);
	}
	
	function noitem() {
		$this->layout->custom_add('CUSTOM_CATEGORY_ID', '');
		$this->layout->custom_add('CUSTOM_SUBJECT_ID', '');
		$this->layout->custom_add('NEWITEM', '');
	}
	
	//
	// lists forum categories
	//
	function categories() {
		//
		// empty breadcrumb
		//
		$this->layout->breadcrumb_add(null, 'Forum');
		
		//
		// lists categories
		//
		$req = $this->root->sql->prepare('
			SELECT * FROM cbs_forum_categories c, cbs_forum_acl a
			WHERE a.cid = c.id
			  AND a.tid = ?
		');
		
		$req->bind_param('i', $this->type);
		$data = $this->root->sql->exec($req);
		
		foreach($data as $category) {
			$this->layout->custom_add('CUSTOM_ID', $category['id']);
			$this->layout->custom_add('CUSTOM_URL', $this->root->urlstrip($category['id'], $category['nom']));
			$this->layout->custom_add('CUSTOM_TITLE', $category['nom']);
			$this->layout->custom_add('CUSTOM_DESCRIPTION', $category['description']);
			$this->layout->custom_add('CUSTOM_STATUS',
				(isset($this->unread['categories'][$category['id']]) ? 'active' : 'inactive')
			);
			
			$this->layout->custom_append('FORUM',
				$this->layout->parse_file_custom('layout/forum.categories.layout.html')
			);
		}
		
		$this->layout->custom_add('NEWITEM', !$this->root->connected() ? '' : '
			<h5 class="text-right" style="margin-top: 30px;">
			<a href="/forum/all-read">Marquer tous les messages comme lu</a>
			</h5>
		');
	}
	
	//
	// lists forum subjects of a given category
	//
	function subjects($category) {
		//
		// grab category title
		//
		$req = $this->root->sql->prepare('
			SELECT * FROM cbs_forum_categories c, cbs_forum_acl a
			WHERE id = ?
			  AND a.cid = c.id
			  AND a.tid = ?
		');
		
		
		$req->bind_param('ii', $category, $this->type);
		$data = $this->root->sql->exec($req);
		
		if(count($data) == 0)
			return $this->denied();
		
		//
		// breadcrumb
		//
		$this->layout->breadcrumb_add('/forum', 'Forum');
		$this->layout->breadcrumb_add(null, $data[0]['nom']);
		
		//
		// list subjects
		//
		$req = $this->root->sql->prepare('
			SELECT s.*, u.nomreel, u.surnom
			FROM cbs_forum_subjects s, cbs_forum_messages m, cbs_membres u
			WHERE m.subject = s.id
			  AND u.id = s.author
			  AND category = ?
			  AND m.id = (
			     SELECT id FROM cbs_forum_messages WHERE subject = m.subject ORDER BY created DESC LIMIT 1
			) ORDER BY m.created DESC 
			
		');
		
		$req->bind_param('i', $category);
		$data = $this->root->sql->exec($req);
		
		if(count($data) == 0)
			$this->error("Il n'y a aucun sujet dans cette catégorie pour l'instant", false);
		
		foreach($data as $subject) {
			$this->layout->custom_add('CUSTOM_ID', $subject['id']);
			$this->layout->custom_add('CUSTOM_URL', $this->root->urlstrip($subject['id'], $subject['subject']));
			$this->layout->custom_add('CUSTOM_TITLE', $subject['subject']);
			$this->layout->custom_add('CUSTOM_DATE', $subject['created']);
			$this->layout->custom_add('CUSTOM_AUTHOR', $this->root->shortname($subject));
			$this->layout->custom_add('CUSTOM_AUTHOR_URL',
				$this->root->urlslash($subject['author'], $this->root->shortname($subject)),
				$this->root->shortname($subject)
			);
			$this->layout->custom_add('CUSTOM_STATUS',
				(isset($this->unread['subjects'][$subject['id']]) ? 'active' : 'inactive')
			);
			
			$this->layout->custom_append('FORUM',
				$this->layout->parse_file_custom('layout/forum.subjects.layout.html')
			);
		}
		
		if($this->root->connected()) {
			$this->layout->custom_add('CUSTOM_CATEGORY_ID', $category);
			$this->layout->custom_add('NEWITEM',
				$this->layout->parse_file_custom('layout/forum.newpost.layout.html')
			);
			
		} else $this->noitem();
	}
	
	//
	// display a forum post
	//
	function post($subject, $page) {
		//
		// grab post subject
		//
		$req = $this->root->sql->prepare('
			SELECT * FROM cbs_forum_subjects s, cbs_forum_acl a
			WHERE s.id = ?
			  AND s.category = a.cid
			  AND a.tid = ?
		');
		
		$req->bind_param('ii', $subject, $this->type);
		$subject = $this->root->sql->exec($req);
		
		if(count($subject) == 0)
			return $this->denied();
		
		$subject = $subject[0];
		
		//
		// grab category name
		//
		$req = $this->root->sql->prepare('SELECT * FROM cbs_forum_categories WHERE id = ?');
		$req->bind_param('i', $subject['category']);
		$category = $this->root->sql->exec($req);
		$category = $category[0];
		
		//
		// building breadcrumb
		//
		$this->layout->breadcrumb_add('/forum', 'Forum');
		$this->layout->breadcrumb_add('/forum/'.$this->root->urlstrip($category['id'], $category['nom']), $category['nom']);
		$this->layout->breadcrumb_add(null, $subject['subject']);
		
		$this->layout->set('title', $subject['subject']);
		
		//
		// lists messages
		//
		$req = $this->root->sql->prepare('
			SELECT msg.*, m.nomreel, m.surnom, m.picture, m.id authorid, m.type
			FROM cbs_forum_messages msg, cbs_membres m
			WHERE msg.subject = ?
			  AND m.id = msg.author
			ORDER BY created ASC
		');
		
		$req->bind_param('i', $subject['id']);
		$data = $this->root->sql->exec($req);
		
		foreach($data as $message) {
			$this->layout->custom_add('CUSTOM_STATUS',
				(isset($this->unread['messages'][$message['id']]) ? 'active' : 'inactive').
				(($message['type'] == 0) ? ' bleus-forum' : '')
			);
			
			// hidden message ?
			if($message['hidden']) {
				$this->layout->custom_append('FORUM',
					$this->layout->parse_file_custom('layout/forum.message.hidden.layout.html')
				);
				continue;
			}
			
			$this->layout->custom_add('CUSTOM_ID', $message['id']);
			$this->layout->custom_add('CUSTOM_MESSAGE', $this->bbdecode($message['message']));
			$this->layout->custom_add('CUSTOM_DATE', $message['created']);
			$this->layout->custom_add('CUSTOM_PICTURE', $this->root->picture($message['picture']));
			
			$this->layout->custom_add('CUSTOM_AUTHOR', $this->root->shortname($message));
			$this->layout->custom_add('CUSTOM_AUTHOR_ID', $message['authorid']);
			$this->layout->custom_add('CUSTOM_AUTHOR_URL',
				$this->root->urlslash($message['authorid'],
				$this->root->shortname($message))
			);
			
			$this->layout->custom_add('CUSTOM_EDIT', $this->request('edit', $message));
			$this->layout->custom_add('CUSTOM_HIDE', $this->request('hide', $message));
			
			$this->layout->custom_append('FORUM',
				$this->layout->parse_file_custom('layout/forum.message.layout.html')
			);
		}
		
		if($this->root->connected()) {
			$this->layout->custom_add('CUSTOM_SUBJECT_ID', $subject['id']);
			$this->layout->custom_add('NEWITEM',
				$this->layout->parse_file_custom('layout/forum.reply.layout.html')
			);
			
			//
			// updating read flags
			//
			$req = $this->root->sql->prepare('
				INSERT INTO cbs_forum_read (uid, mid)
				SELECT ?, m.id
				FROM cbs_forum_messages m
				WHERE m.id NOT IN (SELECT mid FROM cbs_forum_read WHERE uid = ?)
				  AND m.subject = ?
			');
			
			$req->bind_param('iii', $_SESSION['uid'], $_SESSION['uid'], $subject['id']);
			$this->root->sql->exec($req);
			
		} else $this->noitem();
	}
	
	//
	// helpers
	//
	
	//
	// light bbcode support
	//
	function bbdecode($str) {
		$str = str_replace(array('<', '>'), array('&lt', '&gt'), $str);
		$str = preg_replace('#(\[b\])(.*?)(\[/b\])#is', '<strong>\2</strong>', $str);
		$str = preg_replace('#(\[u\])(.*?)(\[/u\])#is', '<ins>\2</ins>', $str);
		$str = preg_replace('#(\[i\])(.*?)(\[/i\])#is', '<em>\2</em>', $str);
		$str = preg_replace('#(\[code\])(.*?)(\[/code\])#is', '<code>\2</code>', $str);
		$str = preg_replace('#(\[img\])(.*?)(\[/img\])#is', '<img src="\2" class="img-responsive" />', $str);
		
		// neasted quote
		do {
			$str = preg_replace('#(\[cite\])(((?R)|.)*?)(\[/cite\])#is',
			                    '<blockquote>\2</blockquote>', $str, -1, $count);
			
		} while($count);
		
		// remove bbcode link and then auto-detect url
		$str = preg_replace('#(\[a\])(.*?)(\[/a\])#is', '\2', $str);
		$str = preg_replace('#(?<![\S"])(https?://\S+)#iS', '<a href="\1" target="_blank">\1</a>', $str);
		
		$str = nl2br($str);
		
		return $str;
	}
	
	
	//
	// creation
	//
	function create($category, $author, $subject) {
		$req = $this->root->sql->prepare('
			INSERT INTO cbs_forum_subjects (author, created, category, subject, postit)
			VALUES (?, NOW(), ?, ?, 0)
		');
		
		$req->bind_param('iis', $author, $category, $subject);
		$this->root->sql->exec($req);
		
		return $req->insert_id;
	}
	
	function reply($subject, $author, $message) {
		$req = $this->root->sql->prepare('
			INSERT INTO cbs_forum_messages (subject, author, created, message, hidden)
			VALUES (?, ?, NOW(), ?, 0)
		');
		
		$req->bind_param('iis', $subject, $author, $message);
		$this->root->sql->exec($req);
	}
	
	//
	// reader
	//
	function allread() {
		$req = $this->root->sql->prepare('
			INSERT INTO cbs_forum_read (uid, mid)
			SELECT u.id, m.id
			FROM cbs_forum_messages m, cbs_forum_subjects s, cbs_forum_acl a, cbs_membres u
			WHERE m.id NOT IN (SELECT mid FROM cbs_forum_read WHERE uid = ?)
			  AND u.id = ?
			  AND s.id = m.subject
			  AND s.category = a.cid
			  AND a.tid = u.type
		');
		
		$req->bind_param('ii', $_SESSION['uid'], $_SESSION['uid']);
		$this->root->sql->exec($req);
		
		// reset unread
		$this->unread = array(
			'categories' => array(),
			'subjects' => array(),
			'messages' => array()
		);
	}
	
	function unreads() {
		return count($this->unread['messages']);
	}
}
?>
