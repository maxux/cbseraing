<?php
namespace CBSeraing;

class config {
    // MySQL configuration
    static public $sql_serv = '';
    static public $sql_user = '';
    static public $sql_pass = '';
    static public $sql_db   = '';

    // Production environment (disable debug)
    static public $prod = true;

    // Base URL used for site-base include
    static public $base = 'http://exemple.com';

    // iOS Push Notification support
    static public $notifications = false;
}
?>
