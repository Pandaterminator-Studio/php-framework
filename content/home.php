<?php


class content
{
    private $MySQLi;
    private $Session;
    private $cookie;
    private $security;
    private $resizer;
    private $uploader;

    function __construct($mysql, $session, $cookie, $security, $resizer, $uploader) {
        $this->MySQLi = $mysql;
        $this->Session = $session;
        $this->cookie = $cookie;
        $this->security = $security;
        $this->resizer = $resizer;
        $this->uploader = $uploader;
        $this->Render();
    }

    private function Render(): void {

    }
}