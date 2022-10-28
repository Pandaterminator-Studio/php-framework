<?php
namespace App;

class Config
{

    //Mysql settings
    const use_mysql = true;
    const DB_HOST = "localhost";
    const DB_USER = 'root';
    const DB_PASS = 'root';
    const DB_NAME = 'panda';
    const DB_PORT = 3306;

    //debug settings
    const SHOW_ERRORS = false;
    const save_mysql = false; //not added yet..

    //
    const use_session = true;
    const use_cookie = true;

    //Security
    const use_security = true;

    //router settings
    const use_router = true;
    const enable_login_functions = false;
    const page_var = "p";
    const default_page = "home";
    const login_page = "login";
    const login_var = "isloggedin";

    //Extra features
    const use_resizer = true;
    const use_uploader = true;

    //Uploader
    const n_max_file_size = 100;
    const n_image_max_file_size = 200;
    const n_allowed_files  = array('pdf','txt','excel');
    const image_allowed_files = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp');
}