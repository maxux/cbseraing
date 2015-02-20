<?php
namespace CBSeraing;

class gallery {
	private $root;
	private $layout;
	
	private $folder = 'images/albums/';
	
	function __construct($root, $layout) {
		$this->root = $root;
		$this->layout = $layout;
		
		//
		// root breadcrumb
		//
		$this->layout->breadcrumb_add('/albums', 'Albums');
	}
	
	function error($str) {
		$this->layout->error_append($str);
		$this->layout->custom_append('DESCRIPTION', 'Rien à afficher.');
		$this->layout->custom_append('ALBUMS', '');
	}
	
	function album($id) {
		$req = $this->root->sql->prepare('SELECT * FROM cbs_albums WHERE id = ?');
		$req->bind_param('i', $id);
		$data = $this->root->sql->exec($req);
		
		if(count($data) > 0)
			return $data[0];
		
		return null;
	}
	
	function thumbnail($album) {
		$req = $this->root->sql->prepare('
			SELECT * FROM cbs_albums_content
			WHERE album = ?
			ORDER BY RAND()
			LIMIT 1
		');
		
		$req->bind_param('i', $album);
		$data = $this->root->sql->exec($req);
		
		if(count($data) > 0)
			return $data[0]['hash'];
		
		//
		// no image found, checking deeper
		//
		$req = $this->root->sql->prepare('
			SELECT * FROM cbs_albums WHERE parent = ?
			ORDER BY RAND()
			LIMIT 1
		');
		
		$req->bind_param('i', $album);
		$data = $this->root->sql->exec($req);
		
		if(count($data) > 0)
			return $this->thumbnail($data[0]['id']);
		
		return 'no-image';
	}
	
	function breadcrumb($last) {
		$reverse = array();
		
		//
		// reading backward categories
		//
		while($last) {
			$req = $this->root->sql->prepare('SELECT id, name, parent FROM cbs_albums WHERE id = ?');
			$req->bind_param('i', $last);
			$data = $this->root->sql->exec($req);
			
			if(count($data) == 0)
				return;
				
			$reverse[] = $data[0];
			$last = $data[0]['parent'];
		}
		
		//
		// reversing categories to get it on the right order
		// setting last item as current item (active)
		//
		$reverse = array_reverse($reverse);
		foreach($reverse as $id => $item) {
			$url = ($id == count($reverse) - 1) ? null : '/albums/'.$this->root->urlstrip($item['id'], $item['name']);
			$this->layout->breadcrumb_add($url, $item['name']);
		}
	}
	
	function append($item, $clear) {
		$this->layout->custom_add('CUSTOM_AUTHOR', $item['author']);
		$this->layout->custom_add('CUSTOM_NAME', $item['name']);
		$this->layout->custom_add('CUSTOM_DESCRIPTION', $item['description']);
		$this->layout->custom_add('CUSTOM_ID', $item['id']);
		$this->layout->custom_add('CUSTOM_URL', $this->root->urlstrip($item['id'], $item['name']));
		$this->layout->custom_add('CUSTOM_CREATED', $item['created']);
		$this->layout->custom_add('CUSTOM_IMAGE', $this->thumbnail($item['id']));
		$this->layout->custom_add('CUSTOM_CLEAR', $clear);
		
		return $this->layout->parse_file_custom('layout/albums.categories.layout.html');
	}
	
	function photo($item) {
		$this->layout->custom_add('CUSTOM_IMAGE', $item['hash']);
		$this->layout->custom_add('CUSTOM_ID', $item['hash']);
		$this->layout->custom_add('CUSTOM_DESCRIPTION', $item['description']);
		return $this->layout->parse_file_custom('layout/albums.album.layout.html');
	}
	
	//
	// lists forum categories
	//
	function albums($id) {
		$total = 0;
		
		$this->layout->set('header', 'Les photos:');
		$this->layout->file('layout/albums.layout.html');
		
		if(($check = $this->album($id))) {
			if($check['private'] == 1) {
				if(!$this->root->connected() || !$this->root->allowed('albums'))
					return $this->error('Non autorisé');
			}
		}
	
		$this->breadcrumb($id);
		
		//
		// getting information about the current album
		//
		if($id != 0 && !($album = $this->album($id))) {
			$id = 0;
			$this->layout->error_append("L'album demandé n'existe pas");
		}
		
		$this->layout->custom_add('DESCRIPTION',
			(isset($album) && $album['description'] != '') ?
				'<blockquote>'.$album['description'].'</blockquote>' : ''
		);
		
		//
		// reading album content (others albums)
		//
		if(!$this->root->allowed('albums')) {
			$req = $this->root->sql->prepare('
				SELECT * FROM cbs_albums
				WHERE private = 0 AND parent = ?
				ORDER BY created DESC
			');
			
		} else $req = $this->root->sql->prepare('
			SELECT * FROM cbs_albums
			WHERE parent = ?
			ORDER BY created DESC
		');
		
		$req->bind_param('i', $id);
		$data = $this->root->sql->exec($req);
		
		$container = null;
		
		foreach($data as $album) {
			$clear = (!(($total + 1) % 4)) ? '<div class="clearfix visible-lg-block"></div>' : '';
			$container .= $this->append($album, $clear);
			
			$total++;
		}
		
		//
		// reading album content (pictures)
		//
		$req = $this->root->sql->prepare('SELECT * FROM cbs_albums_content WHERE album = ?');
		$req->bind_param('i', $id);
		$data = $this->root->sql->exec($req);
		
		if(count($data) > 0) {
			foreach($data as $photo) {
				$container .= $this->photo($photo);
				$total++;
			}
		}
		
		if($total == 0) {
			$this->layout->error_append("Cet album est vide pour le moment, n'hésitez pas à le remplir !");
		}
		
		//
		// rendering whole shit
		//
		$this->layout->custom_add('ALBUMS', $container);
	}
	
	
	//
	// picture helper
	//
	function resize($img_src, $img_dest, $dst_w, $dst_h) {
		$size = GetImageSize($img_src);  
		$src_w = $size[0];
		$src_h = $size[1];  

		$test_h = round(($dst_w / $src_w) * $src_h);
		$test_w = round(($dst_h / $src_h) * $src_w);

		if(!$dst_h)
			$dst_h = $test_h;

		elseif(!$dst_w)
			$dst_w = $test_w;
		
		elseif($test_h > $dst_h)
			$dst_w = $test_w;
			
		else $dst_h = $test_h;

		$dst_im = imagecreatetruecolor($dst_w, $dst_h);
		$src_im = imagecreatefromstring(file_get_contents($img_src));

		imagecopyresampled($dst_im, $src_im, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
		imagejpeg($dst_im, $img_dest);

		imagedestroy($dst_im);  
		imagedestroy($src_im);
	}
}
?>
