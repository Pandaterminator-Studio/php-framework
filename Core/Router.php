<?php

namespace Core;

use InvalidArgumentException;
use ReflectionClass;

class Router
{
    use \Core\Routing\FrameworkRoutes;
    protected array $routes = [];
    protected array $params = [];

    public function add(string $route, array $params = []): void {
        $this->routes[$this->compilePattern($route, '[a-z-]+')] = $params;
    }

    private function compilePattern(string $route, string $parameterPattern): string
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/i', $route, $placeholders, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $expression = '';
        $offset = 0;
        $names = [];
        foreach ($placeholders as $placeholder) {
            $name = $placeholder[1][0];
            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException('Route parameter names must be unique.');
            }
            $names[] = $name;
            $expression .= preg_quote(substr($route, $offset, $placeholder[0][1] - $offset), '~');
            $pattern = isset($placeholder[2]) && $placeholder[2][0] !== '' ? $placeholder[2][0] : $parameterPattern;
            $expression .= '(?P<' . $name . '>' . str_replace('~', '\\~', $pattern) . ')';
            $offset = $placeholder[0][1] + strlen($placeholder[0][0]);
        }
        $expression .= preg_quote(substr($route, $offset), '~');
        $expression = '~^' . $expression . '$~iD';
        if (@preg_match($expression, '') === false) {
            throw new InvalidArgumentException('Invalid route pattern.');
        }
        return $expression;
    }
    public function getRoutes() : array {
        return $this->routes;
    }
    public function match(string $url) : bool {
        $this->params = [];
        foreach($this->routes as $route => $params){
            if(preg_match($route, $url, $matches)) {
                foreach ($matches as $key => $match){
                    if(is_string($key)){
                        $params[$key] = $match;
                    }
                }
                $this->params = $params;
                return true;
            }
        }
        return false;
    }
    public function getParams() : array {
        return $this->params;
    }
    public function dispatch(string $url, ?RequestGuard $guard = null) :void {
        $guard = $guard ?? RequestGuard::fromGlobals();
        $url = $this->removeQueryStringVariable($url);

        if($this->match($url)){
            if (!isset($this->params['controller'], $this->params['action'])
                || !is_string($this->params['controller']) || !is_string($this->params['action'])
                || !preg_match('/^[a-z][a-z0-9-]*$/iD', $this->params['controller'])
                || !preg_match('/^[a-z][a-z0-9-]*$/iD', $this->params['action'])) {
                throw new HttpException(404, 'Page not found.');
            }
            $controller = $this->params['controller'];
            $controller = $this->convertToStudlyCaps($controller);
            $controller = $this->getNamespace(). $controller;

            if(class_exists($controller) && is_subclass_of($controller, Controller::class)){
                if (!(new ReflectionClass($controller))->isInstantiable()) {
                    throw new HttpException(404, 'Controller not found.');
                }
                $controller_object = new $controller($this->params);
                $action = $this->params['action'];
                $action = $this->convertToCamelCase($action);

                if($controller_object instanceof Controller){
                    $response = $controller_object->executeAction($action, $guard);
                    if ($response instanceof \Core\Http\Response) {
                        $response->send($guard->method() === 'HEAD');
                    }
                } else {
                    throw new HttpException(404, 'Controller not found.');
                }
            } else {
                throw new HttpException(404, 'Controller not found.');
            }
        } else {
            throw new HttpException(404, 'Page not found.');
        }
    }
    protected function convertToStudlyCaps(string $string): string
    {
        return str_replace(' ','',ucwords(str_replace('-',' ', $string)));
    }
    protected function convertToCamelCase(string $string): string
    {
        return lcfirst($this->convertToStudlyCaps($string));
    }
    protected function removeQueryStringVariable(string $url) : string {
        return explode('?', $url, 2)[0];
    }
    protected function getNamespace() : string {
        $namespace = 'App\Controllers\\';
        if(array_key_exists('namespace', $this->params)){
            $segments = is_string($this->params['namespace']) ? explode('\\', $this->params['namespace']) : [];
            if ($segments === [] || array_filter($segments, static fn($segment) => !preg_match('/^[a-z][a-z0-9_]*$/iD', $segment))) {
                throw new HttpException(404, 'Controller not found.');
            }
            $namespace .= implode('\\', $segments) . '\\';
        }
        return $namespace;
    }

}
