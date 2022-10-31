<?php
namespace App\Controllers;

use \Core\View;
use \Core\cCurl;

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

        $cCurl = new cCurl();
        echo 'Randomly generate name: '.$cCurl::callAPI("GET", "https://dawn2k-random-german-profiles-and-names-generator-v1.p.rapidapi.com/", "", "X-RapidAPI-Key", "6467780cf6msh006840052350706p1642f7jsn9ff5b6d6ce85");
    }

    public function newAction(){
        echo "subaction works";
    }
}