<?php
namespace App\Controllers;

class Posts extends \Core\Controller
{

    public function index(){
        echo "hello from the index action in the Posts controller <br >";
        echo "<p>". htmlspecialchars((print_r($_GET, true)))."<p/>";
    }

    public function addNew(){
        echo "hello from the addNew action in the Posts controller";
    }

    public function edit(){
        echo "hello from edit action in post controller";
        echo '<p>Route parameters: <pre>'.htmlspecialchars(print_r($this->route_params, true)).'</pre></p>';
    }
}