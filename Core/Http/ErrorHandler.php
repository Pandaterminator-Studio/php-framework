<?php

namespace Core\Http;

use Core\HttpException;
use Core\Validation\ValidationException;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ErrorHandler
{
    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    public function response(Throwable $exception, ?Request $request = null): Response
    {
        $status = $exception instanceof HttpException ? $exception->getStatus() : 500;
        $message = $exception instanceof HttpException ? $exception->getMessage() : 'Internal server error.';
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];
        if (!($exception instanceof HttpException)) {
            $this->log($exception);
        }
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($request?->expectsJson()) {
            $payload = ['message' => $message];
            if ($exception instanceof ValidationException) {
                $payload['errors'] = $errors;
            }
            try {
                return Response::json($payload, $status, $headers);
            } catch (JsonException) {
                return Response::json(['message' => $status >= 500 ? 'Internal server error.' : 'Request failed.'], $status, $headers);
            }
        }
        $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><title>' . $status . '</title></head><body><h1>'
            . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
        if ($errors !== []) {
            $html .= '<ul>';
            foreach ($errors as $field => $messages) {
                foreach ($messages as $error) {
                    $html .= '<li>' . htmlspecialchars($field . ': ' . $error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                }
            }
            $html .= '</ul>';
        }
        return Response::html($html . '</body></html>', $status, $headers);
    }

    private function log(Throwable $exception): void
    {
        $context = ['exception_type' => get_class($exception), 'file' => basename($exception->getFile()), 'line' => $exception->getLine()];
        try {
            if ($this->logger !== null) {
                $this->logger->error('Unhandled application exception.', $context);
            } else {
                error_log(json_encode(['event' => 'iris.unhandled_exception'] + $context, JSON_INVALID_UTF8_SUBSTITUTE));
            }
        } catch (Throwable) {
        }
    }
}
