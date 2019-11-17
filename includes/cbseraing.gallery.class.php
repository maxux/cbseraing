<?php
namespace CBSeraing;

class gallery {
    private $root;
    private $layout;

    private $folder = 'photos/albums/';

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
        $this->layout->custom_add('CUSTOM_AUTHOR', $item['author']);
        $this->layout->custom_add('CUSTOM_IMAGE', $item['hash']);
        $this->layout->custom_add('CUSTOM_ID', $item['hash']);
        $this->layout->custom_add('CUSTOM_DESCRIPTION', $item['description']);
        $this->layout->custom_add('CUSTOM_PATH', $this->folder);
        return $this->layout->parse_file_custom('layout/albums.album.layout.html');
    }

    //
    // lists all albums
    //
    function albums($id) {
        $total = 0;
        $isalbum = true;

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
                '<blockquote class="gallery">'.$album['description'].'</blockquote>' : ''
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

            $isalbum = false;
        }

        if($total == 0)
            $this->layout->error_append("Cet album est vide pour le moment, n'hésitez pas à le remplir !");

        //
        // album footer (manager)
        //
        if($this->root->connected()) {
            $this->layout->custom_add('MANAGER_PARENT', $id);
            $this->layout->custom_add(
                'ALBUM_MANAGER',
                $this->layout->parse_file_custom('layout/albums.uploader.layout.html')
            );

        } else $this->layout->custom_add('ALBUM_MANAGER', '');

        //
        // rendering whole shit
        //
        $this->layout->custom_add('ALBUMS', $container);
    }


    //
    // picture helper
    //
    function resizecrop($source, $destination, $width, $height) {
        $source_gdim = imagecreatefromstring(file_get_contents($source));
        list($source_width, $source_height) = getimagesize($source);

        $source_aspect_ratio = $source_width / $source_height;
        $desired_aspect_ratio = $width / $height;

        if($source_aspect_ratio > $desired_aspect_ratio) {
            $temp_height = $height;
            $temp_width  = (int) ($height * $source_aspect_ratio);
        } else {
            $temp_width  = $width;
            $temp_height = (int) ($width / $source_aspect_ratio);
        }

        $temp_gdim = imagecreatetruecolor($temp_width, $temp_height);
        imagecopyresampled(
            $temp_gdim,
            $source_gdim,
            0, 0, 0, 0,
            $temp_width, $temp_height,
            $source_width, $source_height
        );

        $x0 = ($temp_width - $width) / 2;
        $y0 = ($temp_height - $height) / 2;

        $desired_gdim = imagecreatetruecolor($width, $height);
        imagecopy(
            $desired_gdim,
            $temp_gdim,
            0, 0,
            $x0, $y0,
            $width, $height
        );

        imagejpeg($desired_gdim, $destination);
        imagedestroy($temp_gdim);
        imagedestroy($desired_gdim);
    }

    function resize($source, $destination, $maxwidth, $maxheight) {
        $source_gdim = imagecreatefromstring(file_get_contents($source));
        list($width, $height) = getimagesize($source);

        $xRatio = $maxwidth / $width;
        $yRatio = $maxheight / $height;

        if($width <= $maxwidth && $height <= $maxheight) {
            $toWidth  = $width;
            $toHeight = $height;

        } else if($xRatio * $height < $maxheight) {
            $toHeight = round($xRatio * $height);
            $toWidth  = $maxwidth;
        } else {
            $toWidth  = round($yRatio * $width);
            $toHeight = $maxheight;
        }

        $desired_gdim = imagecreatetruecolor($toWidth, $toHeight);
        imagecopyresampled(
            $desired_gdim, $source_gdim,
            0, 0, 0, 0,
            $toWidth, $toHeight,
            $width, $height
        );

        imagejpeg($desired_gdim, $destination);
        imagedestroy($source_gdim);
        imagedestroy($desired_gdim);
    }

    function thumb($source, $destination) {
        $this->resizecrop($source, $destination, 512, 341);
    }

    function optimize($source, $destination) {
        $this->resize($source, $destination, 1280, 720);
    }

    //
    // gallery manager
    //

    //
    // upload photos
    //
    function upload() {
        set_time_limit(600);

        // html5 files array
        $files = $this->root->files('files');

        if(count($files) < 1) {
            $this->layout->error_append("Aucun fichiers trouvé");
            return 0;
        }

        if(!isset($files[0]['tmp_name'])) {
            $this->layout->error_append("L'upload a échoué, vous avez envoyé trop d'un coup ?");
            return 0;
        }

        foreach($files as $file) {
            $hash = sha1(file_get_contents($file['tmp_name']));

            // thumbnail
            $this->thumb($file['tmp_name'], $this->folder.$hash.'_r.jpg');
            $this->optimize($file['tmp_name'], $this->folder.$hash.'.jpg');

            $req = $this->root->sql->prepare('
                INSERT IGNORE INTO cbs_albums_content (hash, album, description, author)
                VALUES (?, ?, NULL, ?)
            ');
            $req->bind_param('sis', $hash, $_POST['parent'], $_SESSION['uid']);
            $this->root->sql->exec($req);
        }

        return $_POST['parent'];
    }

    //
    // create a new album
    //
    private function insert($name, $description, $parent, $private) {
        $req = $this->root->sql->prepare('
            INSERT INTO cbs_albums (name, description, created, parent, author, private)
            VALUES (?, ?, NOW(), ?, ?, ?)
        ');

        $req->bind_param('ssiii', $name, $description, $parent, $_SESSION['uid'], $private);
        $this->root->sql->exec($req);

        return $req->insert_id;
    }

    function create() {
        if($_POST['name'] == '') {
            $this->layout->error_append("Vous devez spécifier un nom d'album");
            return 0;
        }

        return $this->insert(
            $_POST['name'],
            $_POST['description'],
            $_POST['parent'],
            isset($_POST['private'])
        );
    }
}
?>
