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

    //AI settings
    const ENABLE_OPEN_AI = false;
    const OPEN_AI_API_KEY = "";

    const ENABLE_CLAUDE_AI = false;
    const CLAUDE_AI_API_KEY = "";


    //debug settings
    const SHOW_ERRORS = true;
    const save_mysql = false; //not added yet..

    //SESSION && COOKIES
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