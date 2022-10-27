<?php
namespace App\Controllers;

class Posts
{

    public function index(){
        echo "hello from the index action in the Posts controller <br >";
        echo "<p>". htmlspecialchars((print_r($_GET, true)))."<p/>";
    }

    public function addNew(){
        echo "hello from the addNew action in the Posts controller";
    }
}