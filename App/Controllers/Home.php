<?php
namespace App\Controllers;

use \Core\View;

class Home extends \Core\Controller
{

    protected function before(){
        return true;
    }

    protected function after(){

    }

    public function indexAction (){
        View::renderTemplate('Home/index.html', [
            'name' => 'Raphael',
            'colours' => ['red', 'green', 'blue']
        ]);
    }
}