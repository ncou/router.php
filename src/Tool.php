<?php
namespace Bybzmt\Router;

/**
 * 工具
 */
class Tool
{
    //匹配时正则开头
    protected $_regex_left = '#^';

    //匹配时正则结尾
    protected $_regex_right = '$#';

    //导出代码时排版缩进
    protected $_indent = '    ';

    //回调函数分隔符
    protected $_func_separator = ':';

    //key映射前缀分隔符
    protected $_key_prefix_separator = ' ';

    //路由规则
    protected $_routes;

    public function __construct(array $routes=[]) {
        $this->_routes = $routes;
    }

    /**
     * 导出路由规则缓存文件代码
     */
    public function exportRoutes()
    {
        $code = "<"."?php\n".
            "// This file is automatically generated. Please do not edit it!\n".
            "// 这个文件是自动生成的，请不要编辑它!\n" .
            "// 这个路由规则缓存文件!\n" .
            "return " . $this->_export($this->_routes) . ";\n";

        return $code;
    }

    /**
     * 导出反向路由规则缓存文件代码
     */
    public function exportReverse(array $map=null)
    {
        $code = "<"."?php\n".
            "// This file is automatically generated. Please do not edit it!\n".
            "// 这个文件是自动生成的，请不要编辑它!\n" .
            "// 这是反向路由规则缓存文件!\n" .
            "return " . $this->_export($map ? $map : $this->convertReverse()) . ";\n";

        return $code;
    }

    /**
     * 通过现有路由规则编译出反向路由规则
     */
    public function convertReverse()
    {
        $map = array();

        foreach ($this->_routes as $route) {
            if (isset($route['#map#'])) {
                foreach ($route['#map#'] as $uri => $func) {
                    if (is_string($func) && $func[0] === $this->_func_separator) {
                        list(, $p1, $p2) = explode($this->_func_separator, $func);
                        $action = ltrim($p1, '\\') . $this->_func_separator . $p2;

                        $map[$action][] = [null, $uri, []];
                    } else {
                        //忽略掉其它格式的
                    }
                }

                unset($route['#map#']);
            }

            $this->_convertRegex($map, $route, []);
        }

        //排下序尽量把要求最严的放在最前面
        foreach ($map as $func => $datas) {
            usort($datas, function($aa, $bb){
                $a = count($aa[2]);
                $b = count($bb[2]);
                if ($a==$b) {
                    $a = strlen($aa[0]);
                    $b = strlen($bb[0]);
                }

                return ($a==$b) ? 0 : ($a<$b) ? 1 : -1;
            });

            //去掉重复的规则
            $hashs = [];
            $new_data = [];
            foreach ($datas as $tmp) {
                $hash = md5($this->_export($tmp));

                if (isset($hashs[$hash])) {
                    continue;
                }

                $hashs[$hash] = 1;
                $new_data[] = $tmp;
            }

            $map[$func] = $new_data;
        }

        return $map;
    }

    //转换正则规则的路由
    protected function _convertRegex(array &$map, array $root, array $stack)
    {
        foreach ($root as $key => $routes) {
            if ($key == '#regex#') {
                foreach ($routes as $regex => $hold) {
                    if (is_string($hold) && $hold[0] === $this->_func_separator) {
                        $sub_num = 0;

                        //解析出sprintf函数格式
                        $format = '/'.implode('/', $stack) . $this->_parseRegex($regex, $sub_num);

                        //拆解hold字符串
                        $tmp = explode($this->_func_separator, $hold);

                        //回调函数名
                        $func = ltrim($tmp[1], '\\') . $this->_func_separator . $tmp[2];

                        //找出可选参数
                        $tips = [];
                        for ($i=3, $m=count($tmp); $i<$m; $i++) {
                            if (strpos($tmp[$i], $this->_key_prefix_separator) != false) {
                                list($prefix, $kname) = explode($this->_key_prefix_separator, $tmp[$i], 2);
                                $tips[] = [false, $prefix, $kname];
                            } else {
                                $tips[] = [true, "", $tmp[$i]];
                            }
                        }

                        $full_regex = '/'.implode('/', $stack) . $regex;

                        //验证参数量
                        if (count($tmp) < 4 || (count($tmp)-3) != $sub_num) {
                            throw new Exception("您注册的路由:'$full_regex' 与回调:'$hold' 不相符");
                        }

                        $full_regex = $this->_regex_left . $full_regex . $this->_regex_right;

                        $map[$func][] = [$full_regex, $format, $tips];
                    } else {
                        //忽略掉其它格式的
                    }
                }
            } else {
                $this->_convertRegex($map, $routes, array_merge($stack, [$key]));
            }
        }
    }

    //转换正则规则到sprintf规则
    protected function _parseRegex($regex, &$sub_num)
    {
        $suffix = "";
        $sub = 0;

        for ($i=0, $m=strlen($regex); $i<$m; $i++) {
            $maybeMeta = true;

            if ($regex[$i] == '\\') {
                $i++;
                $maybeMeta = false;
            }

            //忽略部分正则元字符
            if ($maybeMeta) {
                if (strpos('?+*{}[]', $regex[$i]) !== false) {
                    continue;
                }
            }

            //我们只关心'()'
            if ($maybeMeta) {
                if ($regex[$i] == '(') {
                    $suffix .= '%s';
                    $sub++;
                    $sub_num++;
                    continue;
                } elseif ($regex[$i] == ')') {
                    $sub--;
                    continue;
                }
            }

            if ($sub<1) {
                $suffix .= $regex[$i];
            }
        }

        return $suffix;
    }

    //将数据转换为PHP代码
    protected function _export($var, $dep=0)
    {
        if ($var instanceof \Closure){
            $ref = new \ReflectionFunction($var);
            $file = new \SplFileObject($ref->getFileName());

            $file->seek($ref->getStartLine()-1);
            $result = '';

            while ($file->key() < $ref->getEndLine()){
                $result .= str_repeat($this->_indent, $dep) . $file->current();
                $file->next();
            }

            $begin = strpos($result, 'function');
            $end = strrpos($result, '}');

            $result = substr($result, $begin, $end - $begin + 1);
        } elseif (is_object($var)) {
            /* dump object with construct function. */
            $result = 'new '. get_class($var). '('. $this->_export(get_object_vars($var), $dep+1). ')';
        } elseif (is_array($var)) {
            /* dump array in plain array.*/
            $array = array ();
            foreach($var as $k=>$v) {
                $array[] = str_repeat($this->_indent, $dep+1) . var_export($k, true).' => '. $this->_export($v, $dep+1) . ",\n";
            }
            $result = "array(\n" .  implode($array) .  str_repeat($this->_indent, $dep).")";
        } else {
            $result = var_export($var, true);
        }

        return $result;
    }

}
