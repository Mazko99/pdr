<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
  /** @var array<string, array<string, callable|array{class-string, string}>> */
  private array $routes = ['GET' => [], 'POST' => []];

  public function get(string $path, callable|array $handler): void
  {
    $this->routes['GET'][$path] = $handler;
  }

  public function post(string $path, callable|array $handler): void
  {
    $this->routes['POST'][$path] = $handler;
  }

  public function dispatch(string $method, string $uri): void
  {
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $handler = $this->routes[$method][$path] ?? null;

    if ($handler === null) {
      http_response_code(404);
      header('Content-Type: text/plain; charset=utf-8');
      echo "404 Not Found";
      return;
    }

    if (is_array($handler)) {
      [$class, $action] = $handler;
      $controller = new $class();
      $controller->$action();
      return;
    }

    $handler();
  }
}
