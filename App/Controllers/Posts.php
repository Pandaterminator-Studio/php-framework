<?php
namespace App\Controllers;

use Core\Controller;
use \Core\View;
use App\Models\Post;

class Posts extends Controller
{

    public function indexAction(){
        $users = Post::getAll();
        View::renderTemplate('Posts/index.html',
        ['users' => $users]);
    }

    public function addNewAction(){
        echo "hello from the addNew action in the Posts controller";
    }

    public function editAction(){

    }
}