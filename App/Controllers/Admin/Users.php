<?php

class Users
{
    protected function before(){
        echo "(before) ";
        return false;
    }

    protected function after(){
        echo " (after)";
    }

    public function indexAction (){
        echo "hello from the index action in the Admin controller";
    }
}