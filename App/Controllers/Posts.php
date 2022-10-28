<?php
namespace App\Controllers;

use \Core\View;

class Posts extends \Core\Controller
{

    public function indexAction(){
        View::renderTemplate('Posts/index.html');
    }

    public function addNewAction(){
        echo "hello from the addNew action in the Posts controller";
    }

    public function editAction(){

    }
}