<?php

//debug settings
const debug = true;
const save_mysql = false; //not added yet..
const use_session = true;
const use_cookie = true;

//Security
const use_security = true;

//database settings
const use_mysql = true;
const db_host = "localhost";
const db_user = 'root';
const db_password = 'root';
const db_db = 'information_schema';
const db_port = 3306;

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

//Uplaoder

const n_max_file_size = 100;
const n_image_max_file_size = 200;
const n_allowed_files  = array('pdf','txt','excel');
const image_allowed_files = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp');