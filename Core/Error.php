<?php

namespace Core;

use ErrorException;
use Throwable;

class Error
{
    public static function errorHandler($level, $message, $file, $line): void
    {
        if ((error_reporting() & $level) !== 0) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }

    public static function exceptionHandler(Throwable $exception): void
    {
        $status = $exception instanceof HttpException ? $exception->getStatus() : 500;
        $message = $exception instanceof HttpException ? $exception->getMessage() : 'Internal server error.';
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        if (!($exception instanceof HttpException)) {
            error_log(json_encode([
                'event' => 'iris.unhandled_exception',
                'type' => get_class($exception),
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine()
            ], JSON_INVALID_UTF8_SUBSTITUTE));
        }
        echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (getenv('IRIS_DEBUG') === '1' && class_exists(\App\Config::class) && \App\Config::SHOW_ERRORS) {
            $details = get_class($exception) . ' in ' . $exception->getFile() . ':' . $exception->getLine();
            echo "\n\n" . htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}
