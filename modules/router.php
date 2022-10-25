<?php

class router
{

    private $MySQLi;
    private $Session;
    private $cookie;
    private $debug;
    private $security;
    private $resizer;
    private $uploader;

    function __construct($mysql, $session,$cookie, $debug, $security, $resizer, $uploader) {
        $this->MySQLi = $mysql;
        $this->Session = $session;
        $this->cookie = $cookie;
        $this->debug = $debug;
        $this->security = $security;
        $this->resizer = $resizer;
        $this->uploader = $uploader;
        $this->Load();
    }

    private function Load(): void
    {
        if (enable_login_functions){
            if($this->Session->Check(login_var)){
                if(isset($_GET[page_var])){
                    $content = htmlentities($_GET[page_var]);
                    $this->GetContent($content);
                } else {
                    $this->GetContent(default_page);
                }
            } else {
                $this->GetContent(login_page);
            }
        } else {
            if(isset($_GET[page_var])){
                $content = htmlentities($_GET[page_var]);
                $this->GetContent($content);
            } else {
                $this->GetContent(default_page);
            }
        }
        $content = new content($this->MySQLi, $this->Session, $this->cookie, $this->security, $this->resizer, $this->uploader);
    }

    private function GetContent($content): void
    {
        $filename = 'content/'.$content.'.php';
        if (file_exists($filename)) {
            include $filename;
        } else {
            include 'content/404.php';
        }
    }
}