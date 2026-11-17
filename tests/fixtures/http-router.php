<?php

namespace {
    require dirname(__DIR__, 2) . '/.iris/vendor/autoload.php';
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    putenv('IRIS_DEBUG=0');
    set_error_handler('Core\\Error::errorHandler');
    set_exception_handler('Core\\Error::exceptionHandler');
}

namespace App\Controllers {
    final class HttpFixture extends \Core\Controller
    {
        protected function requestPolicies(): array
        {
            return [
                'save' => [
                    'methods' => ['POST'],
                    'editable' => ['note'],
                    'protected' => ['id', 'price_cents', 'owner_id', 'role'],
                ],
            ];
        }

        public function editAction(): void
        {
            $values = ['id' => 42, 'price_cents' => 1995, 'owner_id' => 7, 'role' => 'customer'];
            $this->respond(array_merge($values, [
                '_csrf_token' => \Core\Security::generateCSRFToken(),
                '_integrity_token' => $this->integrityToken('save', $values, 'POST', ['id' => '42']),
                'note' => 'Synthetic note',
            ]));
        }

        public function saveAction(): void
        {
            $_SESSION['fixture_executions'] = ($_SESSION['fixture_executions'] ?? 0) + 1;
            header('X-Fixture-Action: executed');
            $this->respond($this->input());
        }

        public function statusAction(): void
        {
            $this->respond(['executions' => $_SESSION['fixture_executions'] ?? 0]);
        }

        private function respond(array $payload): void
        {
            header('Content-Type: application/json');
            echo json_encode($payload, JSON_THROW_ON_ERROR);
        }
    }
}

namespace {
    \Core\Session::start();
    $router = new \Core\Router();
    $router->add('{controller}/{action}');
    $router->add('{controller}/{action}/{id:\d+}');
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $router->dispatch($path, \Core\RequestGuard::fromGlobals());
}
