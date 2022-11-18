<?php
namespace App\Controllers;

use \Core\View;
use \Core\cCurl;
use \Core\Security;
use \Core\Session;
use \Core\Cookie;

class Home extends \Core\Controller
{

    protected function before(): bool {
        return true;
    }

    protected function after(): bool {
        return true;
    }

    public function indexAction (){

        $cCurl = new cCurl();
        $Security = new Security();
        $Cookie = new Cookie();
        $Session = new Session();

        View::renderTemplate(template: 'Home/index.html', args:
            ['user' => ['username' => 'PandaTerminator007']]);

    }
}