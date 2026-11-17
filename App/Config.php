<?php
namespace App;

class Config
{
    const use_mysql = true;
    const DB_HOST = 'localhost';
    const DB_USER = 'root';
    const DB_PASS = '';
    const DB_NAME = 'panda';
    const DB_PORT = 3306;

    const OPEN_AI_API_KEY = '';
    const OPEN_AI_ENDPOINT = 'https://api.openai.com/v1/';
    const OPEN_AI_DEFAULT_MODEL = 'gpt-3.5-turbo';
    const CLAUDE_AI_API_KEY = '';
    const CLAUD_AI_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const GOOGLE_API_KEY = '';

    const SHOW_ERRORS = false;
    const save_mysql = false;
    const use_session = true;
    const use_cookie = true;
    const use_security = true;
    const use_router = true;
    const enable_login_functions = false;
    const page_var = 'p';
    const default_page = 'home';
    const login_page = 'login';
    const login_var = 'isloggedin';
    const use_resizer = true;
    const use_uploader = true;
    const n_max_file_size = 100;
    const n_image_max_file_size = 200;
    const n_allowed_files = ['pdf', 'txt', 'excel'];
    const image_allowed_files = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

    public static function database(): array
    {
        $password = getenv('IRIS_DB_PASSWORD');
        if ($password === false) {
            throw new \RuntimeException('Database credentials must be configured in the server environment.');
        }
        return [
            'host' => getenv('IRIS_DB_HOST') !== false ? getenv('IRIS_DB_HOST') : self::DB_HOST,
            'port' => getenv('IRIS_DB_PORT') !== false ? getenv('IRIS_DB_PORT') : self::DB_PORT,
            'name' => getenv('IRIS_DB_NAME') !== false ? getenv('IRIS_DB_NAME') : self::DB_NAME,
            'user' => getenv('IRIS_DB_USER') !== false ? getenv('IRIS_DB_USER') : self::DB_USER,
            'password' => $password,
        ];
    }
}
