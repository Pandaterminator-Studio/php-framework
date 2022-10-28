<?php

namespace Core;

use ErrorException;

class Error
{
    /**
     * @throws ErrorException
     */
    public static function errorHandler($level, $message, $file, $line): void
    {
        if(error_reporting() !== 0){
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }

    public static function exceptionHandler($exception){
        echo "<h1>Fatal error</h1>";
        echo "<p>Uncaught exception: '". get_class($exception). "'</p>";
        echo "<p>Message: '". $exception->getMessage(). "'</p>";
        echo "<p>Stack trace: <pre>". $exception->getTraceAsString(). "</pre></p>";
        echo "<p>Thrown in '". $exception->getFile() . "' on line ". $exception->getLine(). "</p>";
    }
}