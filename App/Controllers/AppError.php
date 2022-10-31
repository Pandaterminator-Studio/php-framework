<?php
namespace App\Controllers;

use \Core\View;

class AppError extends \Core\Controller
{

    protected function before(){
        return true;
    }

    protected function after(){

    }

    public function indexAction (){
        View::renderTemplate('AppError/index.html');
    }

    public function errorAction(){
        View::renderTemplate("AppError/error.html");
    }

}