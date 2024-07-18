<?php
namespace App\Controllers;

use \Core\View;
use \Core\cCurl;
use \Core\Security;
use \Core\Session;
use \Core\Cookie;

class User extends \Core\Controller
{
    $cCurl = new cCurl();
    $Security = new Security();
    $Cookie = new Cookie();
    $Session = new Session();

    protected function before(): bool {
        return true;
    }

    protected function after(): bool {
        return true;
    }

    public function loginAction (){
        View::renderTemplate(template: 'User/login.html', args:
            ['user' => ['username' => 'PandaTerminator007']]);

    }

      public function logoutAction (){
        View::renderTemplate(template: 'User/logout.html', args:
            ['user' => ['username' => 'PandaTerminator007']]);

    }

    public function accountAction(){
        View::renderTemplate(template: 'User/account.html', args:
            ['user' => ['username' => 'PandaTerminator007']]);
    }
}