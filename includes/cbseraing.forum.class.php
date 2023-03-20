<?php
namespace CBSeraing;

class forum {
    private $root;
    private $layout;
    private $type;
    private $redis = null;

    // posts per page
    private $ppp = 15;

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
        $this->parsedown = new \Parsedown();

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
        $this->layout->custom_add('GOTONEWMESSAGE', '');

        $this->layout->custom_append('FORUM',
            $this->layout->parse_file_custom('layout/forum.edit.layout.html')
        );
    }

    //
    // dispatch notifications
    //
    private function push($payload) {
        // if notifications are not enabled, skipping
        if(!config::$notifications)
            return;

        // connect to redis if not already done
        if(!$this->redis) {
            $this->redis = new \Redis();
            $this->redis->connect('127.0.0.1');
        }

        // pushing notification
        try {
            $this->redis->publish('cbs-push', json_encode($payload));

        } catch (Exception $e) { }
    }

    //
    // access denied
    //
    function denied() {
        $this->error("Ce que vous avez demandé n'existe pas (ou vous n'y avez pas accès)", true);

        // hide leftover messages
        $this->layout->custom_add('GOTONEWMESSAGE', '');
    }

    function noitem() {
        $this->layout->custom_add('CUSTOM_CATEGORY_ID', '');
        $this->layout->custom_add('CUSTOM_SUBJECT_ID', '');
        $this->layout->custom_add('NEWITEM', '');
    }

    //
    // return annual date period (eg. "2016 - 2017" from "21 dec. 2016" date)
    //
    function annual($source) {
        if((int) date('n', $source) < 9)
            return (((int) date('Y', $source)) - 1).' - '.date('Y', $source);

        return date('Y', $source).' - '.(((int) date('Y', $source)) + 1);
    }

    //
    // lists forum categories
    //
    function categories() {
        //
        // empty breadcrumb
        //
        $this->layout->breadcrumb_add(null, 'Forum');
        $this->layout->custom_add('GOTONEWMESSAGE', '');

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
            // skipping categories where 'comite' flags is required
            // and user is not granted
            if($this->root->connected()) {
                if($category['comite'] == 1 && $this->root->userdata($_SESSION['uid'])['comite'] == 0)
                    continue;
            }

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

        // FIXME
        $this->layout->custom_add('NEWITEM', !$this->root->connected() ? '' : '
            <h5 class="text-right" style="margin-top: 0;">
            <a class="btn btn-primary" href="/forum/all-read">Marquer tous les messages comme lu</a>
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
        $acl = $this->root->sql->exec($req);

        if(count($acl) == 0)
            return $this->denied();

        $acl = $acl[0];

        // skipping categories where 'comite' flags is required
        // and user is not granted
        if($this->root->connected()) {
            if($acl['comite'] == 1 && $this->root->userdata($_SESSION['uid'])['comite'] == 0)
                return $this->denied();
        }

        //
        // breadcrumb
        //
        $this->layout->breadcrumb_add('/forum', 'Forum');
        $this->layout->breadcrumb_add(null, $acl['nom']);

        //
        // Build upper "add message" button
        //
        $newenabled = ($this->root->connected() && $acl['write']);
        $newmessage = '<div class="text-right" style="margin-top: 0;">
            <button class="btn btn-primary btn-newsubject" href="#subjectadd">Créer un nouveau sujet</button>
            </div>';

        $this->layout->custom_add('GOTONEWMESSAGE', $newenabled ? $newmessage : '');

        //
        // list subjects
        //
        $req = $this->root->sql->prepare('
            SELECT s.*, UNIX_TIMESTAMP(m.created) lastdate, u.nomreel, u.surnom
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

        $currentyear = $this->annual($data[0]['lastdate']);

        foreach($data as $subject) {
            // checking if we are still on the same time-period
            $postyear = $this->annual($subject['lastdate']);
            if($postyear != $currentyear) {
                // adding period-separation
                $currentyear = $postyear;

                $this->layout->custom_add('TIME_PERIOD', $currentyear);
                $this->layout->custom_append('FORUM',
                    $this->layout->parse_file_custom('layout/forum.period.layout.html')
                );
            }

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

        if($newenabled) {
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

        // skipping categories where 'comite' flags is required
        // and user is not granted
        if($this->root->connected()) {
            if($subject['comite'] == 1 && $this->root->userdata($_SESSION['uid'])['comite'] == 0)
                return $this->denied();
        }

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

        //
        // Build upper "add message" button
        //
        $newenabled = ($this->root->connected() && $subject['write']);
        $newmessage = '<div class="text-right" style="margin-top: 0;">
            <button class="btn btn-primary btn-newpost" href="#newpost">Ajouter un message</button>
            </div>';

        $this->layout->custom_add('GOTONEWMESSAGE', $newenabled ? $newmessage : '');

        $this->layout->set('title', $subject['subject']);

        //
        // checking if there is unread messages
        // if true, checking the first pages which contains unread messages
        // FIXME: poor way, seriously...
        //
        $req = $this->root->sql->prepare('
            SELECT m.id, r.mid
            FROM cbs_forum_messages m, cbs_forum_read r
            WHERE m.subject = ?
              AND r.mid = m.id
              AND r.uid = ?
            UNION SELECT m.id, NULL
            FROM cbs_forum_messages m
            WHERE m.subject = ?
              AND m.id NOT IN (SELECT mid FROM cbs_forum_read WHERE uid = ?)
        ');

        $req->bind_param('iiii', $subject['id'], $_SESSION['uid'], $subject['id'], $_SESSION['uid']);
        $data = $this->root->sql->exec($req);

        foreach($data as $key => $value) {
            if($value['mid'] == null) {
                $page = ((int)($key / $this->ppp)) + 1;
                break;
            }
        }

        //
        // if not the first page, reading first post as reminder
        //
        if($page > 1) {
            $req = $this->root->sql->prepare('
                SELECT msg.*, m.nomreel, m.surnom, m.picture, m.id authorid, m.type
                FROM cbs_forum_messages msg, cbs_membres m
                WHERE msg.subject = ?
                  AND m.id = msg.author
                ORDER BY created ASC
                LIMIT 1
            ');

            $initp = (($page - 1) * $this->ppp);
            $req->bind_param('i', $subject['id']);
            $data = $this->root->sql->exec($req);

            $unread = NULL;
            foreach($data as $message) {
                $this->layout->custom_add('CUSTOM_STATUS',
                    'reminder'.(($message['type'] == 0) ? ' bleus-forum' : '')
                );

                $this->layout->custom_add('CUSTOM_EXTRA_HEADER', 'Rappel du message original:');
                $this->layout->custom_add('CUSTOM_ID', $message['id']);
                $this->layout->custom_add('CUSTOM_PLAIN_TEXT', $message['message']);

                // original bbcode
                if($message['format'] == 'bbcode') {
                    $this->layout->custom_add('CUSTOM_MESSAGE', $this->bbdecode($message['message']));

                // markdown
                } else if($message['format'] == 'markdown') {
                    $this->layout->custom_add('CUSTOM_MESSAGE', $this->parsedown->text($message['message']));

                // plain/text
                } else $this->layout->custom_add('CUSTOM_MESSAGE', $message['message']);

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

                if($message['type'] != 0 && $category['id'] == 8) {
                    $seenby = $this->seenby($message['id'], 0);
                    $seenint = count($seenby);

                    $this->layout->custom_add('CUSTOM_SEENBY', 'Vu par '.$seenint.' Bleu'.(($seenint > 1) ? 's' : ''));

                } else $this->layout->custom_add('CUSTOM_SEENBY', '');

                $this->layout->custom_append('FORUM',
                    $this->layout->parse_file_custom(
                        'layout/forum.message.'.(($message['hidden']) ? 'hidden.' : '').'layout.html'
                    )
                );
            }
        }

        //
        // messages list
        //
        $req = $this->root->sql->prepare('
            SELECT msg.*, m.nomreel, m.surnom, m.picture, m.id authorid, m.type, m.comite
            FROM cbs_forum_messages msg, cbs_membres m
            WHERE msg.subject = ?
              AND m.id = msg.author
            ORDER BY created ASC
            LIMIT ?, ?
        ');

        $initp = (($page - 1) * $this->ppp);
        $req->bind_param('iii', $subject['id'], $initp, $this->ppp);
        $data = $this->root->sql->exec($req);

        $messages = array();
        $unread = NULL;

        foreach($data as $message) {
            $status = (isset($this->unread['messages'][$message['id']]) ? 'active' : 'inactive');

            if($message['type'] == 0)
                $status .= ' bleus-forum';

            if($message['type'] == 0 && $message['comite'] == 0)
                $status .= ' forum-wasted';

            $this->layout->custom_add('CUSTOM_STATUS', $status);

            $messages[] = $message['id'];

            if($unread == NULL && isset($this->unread['messages'][$message['id']]))
                $unread = $message['id'];

            $this->layout->custom_add('CUSTOM_EXTRA_HEADER', '');
            $this->layout->custom_add('CUSTOM_ID', $message['id']);
            $this->layout->custom_add('CUSTOM_PLAIN_TEXT', $message['message']);

            // original bbcode
            if($message['format'] == 'bbcode') {
                $this->layout->custom_add('CUSTOM_MESSAGE', $this->bbdecode($message['message']));

            // markdown
            } else if($message['format'] == 'markdown') {
                $this->layout->custom_add('CUSTOM_MESSAGE', $this->parsedown->text($message['message']));

            // plain/text
            } else $this->layout->custom_add('CUSTOM_MESSAGE', $message['message']);

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

            if($message['type'] != 0 && $category['id'] == 8) {
                $seenby = $this->seenby($message['id'], 0);
                $seenint = count($seenby);

                $this->layout->custom_add('CUSTOM_SEENBY', 'Vu par '.$seenint.' Bleu'.(($seenint > 1) ? 's' : ''));

            } else $this->layout->custom_add('CUSTOM_SEENBY', '');

            $layout = 'layout/forum.message.layout.html';

            if($message['hidden'])
                $layout = 'layout/forum.message.hidden.layout.html';

            $this->layout->custom_append('FORUM', $this->layout->parse_file_custom($layout));
        }

        $this->layout->custom_add('CUSTOM_ID', $unread);
        $this->layout->preload_append($this->layout->parse_file_custom('layout/forum.scripts.goto.layout.html'));

        //
        // pages
        //
        $req = $this->root->sql->prepare('
            SELECT COUNT(*) c FROM cbs_forum_messages msg
            WHERE msg.subject = ? AND msg.author IS NOT NULL
        ');
        $req->bind_param('i', $subject['id']);
        $data = $this->root->sql->exec($req);

        $total = $data[0]['c'];
        for($i = 0; $i < $total / $this->ppp; $i++)
            $this->layout->pages_add(
                $i + 1,
                '/forum/subject/'.$subject['id'].'-'.($i + 1).'-'.$this->root->strip($subject['subject']),
                ($i + 1) == $page
            );

        //
        // reply form and post-read actions
        //
        if($newenabled) {
            $this->layout->custom_add('CUSTOM_SUBJECT_ID', $subject['id']);
            $this->layout->custom_add('NEWITEM',
                $this->layout->parse_file_custom('layout/forum.reply.layout.html')
            );

        } else $this->noitem();

        if(!$this->root->connected())
            return;

        if(count($messages) == 0)
            return;

        //
        // updating read flags
        // note: select all messages from this page and remove already-read message
        //
        $mark = array();

        foreach($messages as $message)
            $mark[] = '('.$_SESSION['uid'].', '.$message.')';

        $req = $this->root->sql->prepare('INSERT IGNORE INTO cbs_forum_read (uid, mid) VALUES '.implode(', ', $mark));
        $this->root->sql->exec($req);
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

        $str = preg_replace('#(\[video\])(.*?)(\[/video\])#is',
            '<video width="480" height="320" controls><source src="$2"></video>',
            $str);

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

        //
        // dispatch event to redis
        //
        $notif = array(
            'event'    => 'subject',
            'uid'      => $author,
            'subject'  => $subject,
            'category' => $category,
            'rawid'    => $req->insert_id,
        );

        $this->push($notif);

        return $req->insert_id;
    }

    function reply($subject, $author, $content, $format) {
        $req = $this->root->sql->prepare('
            INSERT INTO cbs_forum_messages (subject, author, created, message, hidden, format)
            VALUES (?, ?, NOW(), ?, 0, ?)
        ');

        $req->bind_param('iiss', $subject, $author, $content, $format);
        $this->root->sql->exec($req);

        $mesreq = $this->root->sql->prepare('SELECT * FROM cbs_forum_subjects s WHERE s.id = ?');
        $mesreq->bind_param('i', $subject);
        $message = $this->root->sql->exec($mesreq);

        //
        // dispatch event to redis
        //
        $notif = array(
            'event'    => 'reply',
            'uid'      => $author,
            'subject'  => $subject,
            'message'  => $content,
            'category' => $message[0]['category'],
        );

        $this->push($notif);

        return $message[0];
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

    //
    // seen-by feature
    //
    function seenby($postid, $type) {
        $req = $this->root->sql->prepare('
            SELECT m.id, m.nomreel FROM cbs_forum_read r, cbs_membres m
            WHERE r.uid = m.id AND m.type = ? AND r.mid = ?
        ');

        $req->bind_param('ii', $type, $postid);
        return $this->root->sql->exec($req);
    }
}
?>
