<?php

use Core\modules\cookie;
use Core\modules\debugger;
use Core\modules\mysql;
use Core\modules\resizer;
use Core\modules\router;
use Core\modules\security;
use Core\modules\session;
use Core\modules\uploader;

header("Content-Type: text/html; charset=[UTF-8]");

require 'config/config.php';
require 'modules/debugger.php';
require 'modules/mysql.php';
require 'modules/session.php';
require 'modules/cookie.php';
require 'modules/resizer.php';
require 'modules/security.php';
require 'modules/uploader.php';
require 'modules/router.php';

class index
{
    protected $debug;
    protected $mysql;
    protected $session;
    protected $cookie;
    protected $resizer;
    protected $security;
    protected $uploader;
    protected $router;

    public function Render(): void
    {
        if(debug) $this->debug = new debugger();
        if(use_security) $this->security = new security();
        if(use_resizer) $this->resizer = new resizer();
        if(use_uploader) $this->uploader = new uploader($this->resizer,$this->security);
        if(use_session) $this->session = new session();
        if(use_cookie) $this->cookie = new cookie();

        if(use_mysql)
            $this->mysql = new mysql(db_host, db_user, db_password, db_db, db_port, $this->debug);
            $this->mysql->Connect();
        if(use_router) $this->router = new router($this->mysql, $this->session, $this->cookie, $this->debug, $this->security, $this->resizer, $this->uploader);

    }
}

$index = new index();
$index->Render();