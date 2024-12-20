<?php
namespace CBSeraing;

// checking if config class is set, otherwise this is probably
// a fresh cloned repository, you should check sample config
if(!file_exists(dirname(__FILE__).'/cbseraing.config.class.php'))
    die(trigger_error("Configuration file missing", E_USER_ERROR));

include('cbseraing.config.class.php');
include('cbseraing.sql.class.php');
include('cbseraing.forum.class.php');
include('cbseraing.gallery.class.php');
include('cbseraing.ajax.class.php');
include('external.parsedown.php');
include('external.bbcode.php');
include('external.bbcode.tag.php');

class cbseraing {
    private $profile  = 'photos/profile/';
    private $guest    = 99;

    private $type = array();
    private $user = null;

    private $layout;
    public $sql;
    public $forum;
    public $acl = array();

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
        5 => true,
        8 => true,
        50 => true,
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
        $string = str_replace(array('?', '!', '#', ',', '.'), '', $url);
        $string = str_replace(array('---', '--'), '-', $string);

        $a = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿŔŕ /\'';
        $b = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyRr---';

        // $string = utf8_decode($string);
        // $string = strtr($string, utf8_decode($a), $b);
        $string = strtr($string, $a, $b);
        $string = strtolower($string);

        // $string = utf8_encode($string);
        return trim(preg_replace("![^a-z0-9]+!i", "-", $string), '-'); // remove leading and trailing dash
    }

    function urlstrip($id, $name) {
        return $id.'/'.$this->strip($name);
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
        $_SESSION['email'] = $user['email'];

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
        unset($_SESSION['email']);

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
                $allowed = array(1, 2, 3, 4, 50);
                return in_array($this->user['type'], $allowed);
            break;

            case 'comite-header':
                $allowed = array(0 => true, 1 => true, 2 => true, 3 => true, 4 => true, 6 => true, 7 => true);
                return isset($allowed[$option]);
            break;

            case 'comite-acl':
                // disallow 'blue' page for guests
                if($option == 0 && !$this->connected())
                    return false;

                // allows all the others
                return true;
            break;
        }
    }

    function isadmin($uid) {
        $req = $this->sql->prepare('SELECT * FROM cbs_admins WHERE uid = ?');
        $req->bind_param('i', $uid);
        $data = $this->sql->exec($req);

        if(count($data) == 0)
            return false;

        return true;
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
    function types($plurial = false) {
        $types = array();

        $req = $this->sql->query('SELECT * FROM cbs_types');
        while($data = $this->sql->fetch($req))
            $types[$data['id']] = $data[($plurial ? 'plurial' : 'name')];

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
    // return all 'fonction' of a user by year
    //
    function fonctions($id) {
        $fonctions = array();

        $query = "SELECT mf.year, CASE WHEN m.sexe = 'M' THEN GROUP_CONCAT(f.fonction SEPARATOR ', ') ELSE GROUP_CONCAT(f.feminin SEPARATOR ', ') END as fonction
            FROM cbs_member_fonction mf, cbs_fonctions f, cbs_membres m
            WHERE mf.id_member = m.id
            AND mf.id_fonction = f.id
            AND m.id = ".$id."
            GROUP BY mf.year
            ORDER BY mf.year DESC
        ";

        if ($result = $this->sql->query($query)) {
            while ($row = $this->sql->fetch($result)) {
                $fonctions[$row['year']] = $row['fonction'];
            }

            $result->free();
        }

        return $fonctions;
    }

    //
    // return all old 'fonction' to display them in a list
    //
    function oldfonctions($fonctions) {
        if(isset($fonctions)){
            $output = implode('<br/>', array_map(
                function ($v, $k) { return sprintf("%s - %s : %s", $k, $k+1, $v); },
                $fonctions,
                array_keys($fonctions)
            ));
        } else {
            $output = '';
        }

        return $output;
    }

    function school_year(){
        if(date("m") >= 9)
            return date("Y");
        else
            return date("Y")-1;
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
    // return an array of uploaded file (html5 compatible)
    //
    function files($input) {
        $files = array();
        $fdata = $_FILES[$input];

        if(is_array($fdata['name'])) {
            for($i = 0; $i < count($fdata['name']); $i++) {
                $files[] = array(
                    'name' => $fdata['name'][$i],
                    'type' => $fdata['type'][$i],
                    'tmp_name'=> $fdata['tmp_name'][$i],
                    'error' => $fdata['error'][$i],
                    'size'  => $fdata['size'][$i]
                );
            }

        } else $files[] = $fdata;

        return $files;
    }

    //
    // special pages
    //

    //
    // format layout and display a user information block
    //
    function user($user, $suffix = '') {
        if(!$this->allowed('comite-header', $user['type']))
            $this->layout->set('header', 'Nos amis:');

        $fonctions = $this->fonctions($user['id']);

        //
        // Save current "fonction" and remove it from the array
        //
        if(isset($fonctions[$this->school_year()])){
            $current_fonction = $fonctions[$this->school_year()];
            unset($fonctions[$this->school_year()]);
        }
        else
            $current_fonction = '';

        $oldfonctions = $this->oldfonctions($fonctions);

        $this->layout->custom_add('CUSTOM_MEMBER_ID', $user['id']);
        $this->layout->custom_add('CUSTOM_MEMBER_URL', $this->urlslash($user['id'], $this->shortname($user)));
        $this->layout->custom_add('CUSTOM_NAME', $this->username($user));
        $this->layout->custom_add('CUSTOM_PICTURE', $this->picture($user['picture']));
        $this->layout->custom_add('CUSTOM_BAPTISE', $this->baptise($user['anbapt'], $user['type']));
        $this->layout->custom_add('CUSTOM_ACTUELLEMENT', $user['actu']);
        $this->layout->custom_add('CUSTOM_TITRES', nl2br($user['titres']));
        $this->layout->custom_add('CUSTOM_ETUDES', nl2br($user['etudes']));
        $this->layout->custom_add('CUSTOM_RANG', $current_fonction);
        $this->layout->custom_add('CUSTOM_ETOILES', $this->stars($user['etoiles']));
        $this->layout->custom_add('CUSTOM_FONCTIONS', $oldfonctions);
        $this->layout->custom_add('CUSTOM_OLD', is_array($fonctions) && count($fonctions) > 0 ? '' : 'hidden');

        $this->layout->container_append(
            $this->layout->parse_file_custom('layout/comite.list'.$suffix.'.layout.html')
        );
    }

    //
    // list all old committees
    //
    function oldcomite() {
        $req = $this->sql->prepare('
            SELECT year
            FROM cbs_member_fonction
            WHERE year < ?
            GROUP BY year
            ORDER BY year DESC
        ');

        $current_school_year = $this->school_year();
        $req->bind_param('i',$current_school_year);

        $years = $this->sql->exec($req);

        //
        // Display year by year each comittee, member.type is used to separate registered and unregistered members
        //
        foreach($years as $year) {
            $req = $this->sql->prepare('
                SELECT m.id, m.type, m.nomreel, m.surnom, CASE WHEN m.sexe = \'M\' THEN f.fonction ELSE f.feminin END as fonction
                FROM cbs_member_fonction mf, cbs_fonctions f, cbs_membres m
                WHERE mf.id_member = m.id
                AND mf.id_fonction = f.id
                AND mf.year = ?
                ORDER BY mf.id_fonction ASC
            ');

            $req->bind_param('i', $year['year']);
            $oldcommittee = $this->sql->exec($req);

            $table_line = '';
            foreach($oldcommittee as $person) {
                if($person['type'] != 8) { // != ancien_non_inscrit
                    $url = $this->urlslash($person['id'], $this->shortname($person));
                    $table_line .= '<tr><td>'.$person['fonction'].'</td><td><a href="/membre/'.$url.'">'.$this->shortname($person).'</a></td></tr>';

                } else {
                    $table_line .= '<tr><td>'.$person['fonction'].'</td><td>'.$this->shortname($person).'</td></tr>';
                }
            }

            $this->layout->custom_add('CUSTOM_TABLE', $table_line);
            $this->layout->custom_add('CUSTOM_YEAR', $year['year']);

            $this->layout->container_append(
                $this->layout->parse_file_custom('layout/comite.old.layout.html')
            );
        }
    }

    //
    // list all members with a specific type
    //
    function comite($type, $comite = 1, $silent = false) {
        if(!$this->allowed('comite-header', $type)) {
            $this->layout->set('header', 'Nos amis:');
            $comite = 0;
        }

        if(!$this->allowed('comite-acl', $type))
            return $this->layout->error_append('Vous devez être connecté pour voir ces membres');

        //
        // The sub selected is "Anciens comités"
        //
        if($type == 7)
            return $this->oldcomite();

        //
        // easter egg: fake sql injection
        //
        if(is_string($type) && $type[0] == "'")
            die("Bravo, tu as trouvé la faille d'injection SQL, j't'affone !");

        //
        // grabbing users from their type
        //
        $req = $this->sql->prepare('
            SELECT *
            FROM cbs_membres WHERE type = ? AND comite = ?
            ORDER BY ordre DESC, anbapt DESC
        ');

        $req->bind_param('ii', $type, $comite);
        $data1 = $this->sql->exec($req);

        //
        // grabbing users from additional type
        //
        $req = $this->sql->prepare('
            SELECT m.*, t.type type
            FROM cbs_membres m, cbs_add_types t
            WHERE t.type = ? AND m.id = t.mid
            ORDER BY m.ordre DESC, m.anbapt DESC
        ');

        $req->bind_param('i', $type);
        $data2 = $this->sql->exec($req);

        $final = array_merge($data1, $data2);

        if(!$silent && (count($final) == 0 || isset($this->skiptypes[$type])))
            return $this->layout->error_append('Personne pour le moment');

        $suffix = ($type == 0 && $comite == 0) ? '.wasted' : '';

        foreach($final as $user)
            $this->user($user, $suffix);

        // adding wasted blue
        if($type == 0 && $comite == 1)
            $this->comite($type, 0, true);
    }

    //
    // display a single member data
    //
    function membre($id) {
        if(!($user = $this->userdata($id)))
            return $this->layout->error_append('Membre introuvable');

        if($user['type'] == 0 && !$this->connected())
            return $this->layout->error_append('Vous devez être connecté pour voir ce membre');

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
            'b' => 'bourgeois',
            '/' => 'palme-doree',
            '!' => 'palme-argent'
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

        $fonctions = $this->fonctions($user['id']);

        //
        //Remove current "fonction" from the array
        //
        if(isset($fonctions[$this->school_year()]))
            unset($fonctions[$this->school_year()]);

        $oldfonctions = $this->oldfonctions($fonctions);

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
        $this->layout->custom_add('CUSTOM_FONCTIONS', $oldfonctions);
        $this->layout->custom_add('CUSTOM_OLD', count($fonctions) > 0 ? '' : 'hidden');

        if($edit) {
            if($user['sexe'] == 'M') {
                $this->layout->custom_add('CUSTOM_HOMME', 'selected="selected"');
                $this->layout->custom_add('CUSTOM_FEMME', '');
            } else {
                $this->layout->custom_add('CUSTOM_FEMME', 'selected="selected"');
                $this->layout->custom_add('CUSTOM_HOMME', '');
            }

            $this->layout->file('layout/profile.edit.layout.html');
        }
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
        $localUserType = $this->usertype();

        if(!$this->connected()) {
            $this->layout->container_append('{{ERRORS}}');
            return $this->layout->error_append('L\'agenda n\'est actuellement pas public.');
        }

        if($localUserType == 0) {
            $this->layout->container_append('{{ERRORS}}');
            return $this->layout->error_append('Bien tenté bleu, mais ton avenir te restera inconnu...');
        }

        $req = $this->sql->query('SELECT *, UNIX_TIMESTAMP(date_ev) udate FROM cbs_agenda WHERE date_ev >= DATE(NOW())');

        if($req->num_rows == 0) {
            $this->layout->container_append('{{ERRORS}}');
            return $this->layout->error_append("Rien de prévu pour l'instant");
        }

        while(($event = $this->sql->fetch($req))) {
            $fmt = datefmt_create('fr_BE', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, "'Le ' cccc d LLLL yyyy 'à' HH'h'mm");
            $when = datefmt_format($fmt, $event['udate']);

            $this->layout->custom_add('CUSTOM_TITLE', $event['descri']);
            $this->layout->custom_add('CUSTOM_LOCATION', $event['lieu']);
            $this->layout->custom_add('CUSTOM_DATE', $when);
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

        $fmt = datefmt_create('fr_BE', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, "cccc d LLLL yyyy");

        foreach($events as $event) {
            $when = ucfirst(datefmt_format($fmt, $event['event']['uts']));

            $this->layout->custom_add('CUSTOM_NAME', $event['event']['name']);
            $this->layout->custom_add('CUSTOM_DATE', $when);
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

        // counter
        $was = $this->sql->count('SELECT id FROM cbs_membres WHERE type = 0');
        $this->layout->custom_add('CUSTOM_COUNTER_WAS', $was);

        $now = $this->sql->count('SELECT id FROM cbs_membres WHERE type = 0 AND comite = 1');
        $this->layout->custom_add('CUSTOM_COUNTER_NOW', $now);

        $this->layout->file('layout/home.counter.layout.html');

        // home-page
        $intro = ($news['utime'] < time()) ? 'Dernier évènement' : 'Prochain évènement';

        $fmt = datefmt_create('fr_BE', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, "'Le ' cccc d LLLL yyyy 'à' HH'h'mm");
        $when = datefmt_format($fmt, $news['utime']);

        $this->layout->custom_add('EVENT_ID', $news['id']);
        $this->layout->custom_add('EVENT_INTRO', $intro);
        $this->layout->custom_add('EVENT_NAME', $news['name']);
        $this->layout->custom_add('EVENT_COVER', $news['cover']);
        $this->layout->custom_add('EVENT_DESCRIPTION', nl2br($news['description']));
        $this->layout->custom_add('EVENT_WHERE', $news['where']);
        $this->layout->custom_add('EVENT_WHEN', $when);

        $title = ($news['oripeaux']) ? 'Oripeaux ?' : '';
        $this->layout->custom_add('EVENT_TITLE_ORIPEAUX', $title);
        $this->layout->custom_add('EVENT_ORIPEAUX', $news['oripeaux']);

        $title = ($news['link']) ? 'Lien Facebook' : '';
        $this->layout->custom_add('EVENT_TITLE_LINK', $title);
        $this->layout->custom_add('EVENT_LINK', $news['link']);

        $this->layout->file('layout/home.layout.html');
    }

    function history() {
        $newslist = $this->news(20);

        foreach($newslist as $news) {
            $fmt = datefmt_create('fr_BE', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, "'Le ' cccc d LLLL yyyy 'à' HH'h'mm");
            $when = datefmt_format($fmt, $news['utime']);

            $this->layout->custom_add('EVENT_ID', $news['id']);
            $this->layout->custom_add('EVENT_NAME', $news['name']);
            $this->layout->custom_add('EVENT_COVER', $news['cover']);
            $this->layout->custom_add('EVENT_DESCRIPTION', nl2br($news['description']));
            $this->layout->custom_add('EVENT_WHERE', $news['where']);
            $this->layout->custom_add('EVENT_WHEN', $when);

            $title = ($news['oripeaux']) ? 'Oripeaux ?' : '';
            $this->layout->custom_add('EVENT_TITLE_ORIPEAUX', $title);
            $this->layout->custom_add('EVENT_ORIPEAUX', $news['oripeaux']);

            $title = ($news['link']) ? 'Lien Facebook' : '';
            $this->layout->custom_add('EVENT_TITLE_LINK', $title);
            $this->layout->custom_add('EVENT_LINK', $news['link']);

            $this->layout->container_append(
                $this->layout->parse_file_custom('layout/home.history.layout.html')
            );
        }
    }

    function contact() {
        $this->layout->custom_add('CONTACT_NUMBER', $this->variable('contact'));
        $this->layout->custom_add('EMAIL_PRESI', $this->variable('email_presidence'));
        $this->layout->custom_add('EMAIL_WEB', $this->variable('email_webmaster'));
        $this->layout->custom_add('HREF_EMAIL_PRESI', "mailto:".$this->variable('email_presidence'));
        $this->layout->custom_add('HREF_EMAIL_WEB', "mailto:".$this->variable('email_webmaster'));
        $this->layout->file('layout/contact.layout.html');
    }

    //
    // variable getter
    //
    function variable($key) {
        $req = $this->sql->prepare("SELECT `value` FROM cbs_vars WHERE `key` = ?");
        $req->bind_param('s', $key);
        $rows = $this->sql->exec($req);

        if(count($rows) == 1)
            return $rows[0]['value'];

        return null;
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

    function addnews() {
        $when = $_POST['when'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        $where = $_POST['where'];
        $link = $_POST['facebook'];
        $oripeaux = $_POST['oripeaux'];
        $cover = NULL;

        if(isset($_FILES['cover'])) {
            $gallery = new gallery($this, $this->layout);
            $hash = sha1(file_get_contents($_FILES['cover']['tmp_name']));

            $cover = $hash.'.jpg';
            $target = 'images/covers/'.$cover;
            $gallery->optimize($_FILES['cover']['tmp_name'], $target);

            var_dump($target);
        }

        $req = $this->sql->prepare('
            INSERT INTO cbs_news (`when`, `name`, `description`, `where`, `link`, `oripeaux`, `cover`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $req->bind_param('sssssss', $when, $name, $description, $where, $link, $oripeaux, $cover);
        $this->sql->exec($req);
    }

    //
    // check if file is an image
    //
    function image($filename) {
        if($filename == "")
            return false;

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

        // set username (email) in http header for custom logs
        // this header should be hidden by webserver
        if($this->connected()) {
            // ensure email is set, backward compatible with already connected user
            if(isset($_SESSION['email'])) {
                header("X-Username: ".$_SESSION['email']);
            }
        }

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
                surnom = ?, nomreel = ?, sexe = ?, actu = ?, etoiles = ?, anbapt = ?, titres = ?, etudes = ?
                WHERE id = ?
            ');

            if($_POST['surnom'] == '' && $_POST['nomreel'] == '') {
                $this->layout->error_append('Vous devez spécifier au moins un surnom ou votre nom réel');

                $_GET['page'] = 'settings';
                return;
            }

            $req->bind_param('sssssissi',
                $_POST['surnom'],
                $_POST['nomreel'],
                $_POST['sexe'],
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
            $this->forum->reply($subject, $_SESSION['uid'], $_POST['message'], $_POST['format']);

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

            if(strlen($_POST['message']) > 65000)
                return $this->layout->error_append("Arrête un peu de poster pour ne rien dire.");

            if(!isset($_POST['format']) || $_POST['format'] == '')
                $_POST['format'] = 'bbcode';

            // post reply
            $subject = $this->forum->reply($_POST['subject'], $_SESSION['uid'], $_POST['message'], $_POST['format']);

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

            case 'admin-news':
                if($this->connected() && $this->isadmin($_SESSION['uid'])) {
                    if($_SERVER['REQUEST_METHOD'] == 'POST') {
                        $this->addnews();
                        header('Location: /history');
                        exit(0);
                    }

                    $this->layout->set('header', 'Créer un évement:');
                    $this->layout->file('layout/admin.news.layout.html');

                } else {
                    $this->layout->set('header', '');
                    $this->layout->error_append("Vous n'avez pas accès à cette page.");
                    $this->layout->file('layout/denied.layout.html');
                }
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

                if($this->connected()) {
                    if(isset($_GET['create']))
                        $_GET['album'] = $gallery->create();

                    if(isset($_GET['upload']))
                        $_GET['album'] = $gallery->upload();
                }

                if(!isset($_GET['album']))
                    $_GET['album'] = 0;

                $gallery->albums($_GET['album']);
            break;

            case 'contact':
                $this->layout->set('header', 'Nous contacter:');
                $this->contact();
            break;

            case 'comite':
                if(!isset($_GET['sub']))
                    $_GET['sub'] = null;

                //
                // get types from database
                //
                $types = $this->types(true);
                unset($types[$this->guest]);

                //
                // building submenu
                //
                $url = array();
                foreach($types as $id => $plurial) {
                    if(isset($this->skiptypes[$id]))
                        continue;

                    $url['/comite/'.$this->urlstrip($id, $plurial)] = $plurial;
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

            case 'history':
                $this->layout->set('header', 'Nos dernières activitées:');
                $this->history();
            break;

            case 'report':
                $this->layout->set('header', 'Signaler un lien');
                $this->layout->custom_add('REPORT_URL', $_POST['url']);
                $this->layout->file('layout/report.layout.html');
            break;


            default:
                $this->layout->set('header', 'Accueil:');
                $this->homepage();
            break;
        }
    }
}
?>
