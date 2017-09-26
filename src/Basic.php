<?php
namespace Bybzmt\Router;

/**
 * 路由基础核心
 */
class Basic
{
    protected $_routes;

    /**
     * 路由初史化
     * (可以将之前缓存的路由规则直接载入)
     */
    public function __construct(array $routes=[]) {
        $this->_routes = $routes;
    }

    /**
     * 路由匹配
     *
     * @param method 请求方法
     * @param uri 请求路径
     * @return 成功反回 array(func, params) 失败反回null
     */
    public function match(string $method, string $uri) {
        $uri = '/'.trim($uri, '/');

        $routes = isset($this->_routes[$method]) ? $this->_routes[$method] : array();

        if (isset($routes['#map#'][$uri])) {
            return [$routes['#map#'][$uri], []];
        }

        $i=1;
        while (true) {
            if (($x=strpos($uri, '/', $i)) !== false) {
                $slice = substr($uri, $i, $x - $i);

                if (isset($routes[$slice])) {
                    $routes = $routes[$slice];
                    $i = $x+1;
                    continue;
                }
            }
            break;
        }
        $tail = substr($uri, $i-1);

        if (isset($routes['#regex#'])) {
            foreach ($routes['#regex#'] as $pattern => $func) {
                if (preg_match('#^' . $pattern . '$#', $tail, $matches,  PREG_OFFSET_CAPTURE)) {

                    $params = [];
                    for ($i=1, $e=count($matches); $i<$e; $i++) {
                        if ($i+1 < $e) {
                            //将多个子组重复捕捉到的部分去除
                            //如：/blog(/\d+(/\d+(/\d+)?)?)?
                            //匹配：/blog/2017/9/23 得到 2017、9、23
                            $params[] = substr($matches[$i][0], 0, $matches[$i+1][1] - $matches[$i][1]);
                        } else {
                            $params[] = $matches[$i][0];
                        }
                    }

                    return [$func, $params];
                }
            }
        }

        return null;
    }

    /**
     * 注册路由规则
     *
     * @param methods 请求方法，多个方法以|分隔，如："GET|POST|PUT"
     * @param pattern 匹配规则，可以使用PCRE正则
     * @param func    注册的回调，路由匹配成功时它将原样返回
     */
    public function handle(string $methods, string $pattern, $func) {
        $pattern = '/'.trim($pattern, '/');

        $regex_meta_character = '.[](){}*+?\\';

        //判断是否是正则
        $sub = strpbrk($pattern, $regex_meta_character);
        if ($sub === false) {
            foreach (explode('|', $methods) as $method) {
                $this->_routes[$method]['#map#'][$pattern] = $func;
            }
        } else {
            $regex = strrchr(substr($pattern, 0, strlen($pattern) - strlen($sub)), '/') . $sub;
            $prefix = substr($pattern, 0, strlen($pattern) - strlen($regex));

            foreach (explode('|', $methods) as $method) {
                $routes = &$this->_routes;

                foreach (explode('/', $method . $prefix) as $part) {
                    $routes = &$routes[$part];
                }

                $routes['#regex#'][$regex] = $func;
            }
        }
    }

    public function getRoutes()
    {
        return $this->_routes;
    }
}
