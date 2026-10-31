<?php
declare(strict_types=1);
final class Router {
    private array $routes = [], $before = [];
    public function get(string $path, callable $handler): void { $this->routes[] = ['GET',$path,$handler]; }
    public function post(string $path, callable $handler): void { $this->routes[] = ['POST',$path,$handler]; }
    public function before(callable $fn): void { $this->before[] = $fn; }
    public function dispatch(string $method, string $path): void {
        foreach ($this->before as $fn) $fn($method,$path);
        foreach ($this->routes as [$m,$pattern,$handler]) {
            if ($m !== $method) continue;
            $a = explode('/',trim($pattern,'/')); $b = explode('/',trim($path,'/')); $params = [];
            if (count($a) !== count($b)) continue;
            foreach ($a as $i => $segment) {
                if (preg_match('/^\{([a-z_]+)\}$/', $segment, $match)) $params[$match[1]] = $b[$i];
                elseif ($segment !== $b[$i]) continue 2;
            }
            echo $handler($params); return;
        }
        http_response_code(404); echo View::render(ROOT . '/app/Core/views/error.php', ['title'=>'Page not found','message'=>'Choose a page from the sidebar to continue.']);
    }
}
