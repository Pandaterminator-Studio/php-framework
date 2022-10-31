<?php
namespace App\Controllers;

use \Core\View;
use \Core\cCurl;
use \Core\Security;
use \Core\Session;
use \Core\Cookie;

class Home extends \Core\Controller
{

    protected function before(){
        return true;
    }

    protected function after(){

    }

    public function indexAction (){

        $cCurl = new cCurl();
        $Security = new Security();
        $Cookie = new Cookie();
        $Session = new Session();

        /*View::renderTemplate('Home/index.html', [
            'name' => 'Raphael',
            'colours' => ['red', 'green', 'blue']
        ]);*/

    }

    public function newAction(){
        echo "subaction works";
    }
}