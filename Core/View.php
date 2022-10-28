<?php
namespace Core;
class View
{

    public static function render($view, $args = []): void
    {
        extract($args, EXTR_SKIP);
        $file = "../App/Views/$view";
        if(is_readable($file)){
            require $file;
        } else {
            throw new Exception("$file not found");
        }
    }

    public static function renderTemplate($template, $args = []){
        static $twig = null;
        if($twig === null){
            $loader = new \Twig\Loader\FilesystemLoader('../App/Views');
            $twig = new \Twig\Environment($loader, array('auto_reload' => true, 'cache' => false));
        }
        echo $twig->render($template, $args);
    }
}