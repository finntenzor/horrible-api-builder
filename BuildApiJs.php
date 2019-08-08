<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Route;
use think\route\RuleItem;
use RuntimeException;
use think\exception\ClassNotFoundException;
use ReflectionMethod;
use ReflectionException;

class BuildApiJs extends Command
{
    /**
     * @var \think\App
     */
    protected $app;

    protected $axios = 'axios';

    protected $outputPath = 'api.js';

    protected function configure()
    {
        $this->app = app();
        // 指令配置
        $this->setName('util:build-apijs')
            ->setDescription('Build Api.js 生成API接口文件');
        // 设置参数
    }

    protected function execute(Input $input, Output $output)
    {
        /** @var \think\Route $route */
        // 获取所有的路由规则
        $route = app(Route::class);
        $allRuleItems = [];
        foreach ($route->getDomains() as $domain) {
            $this->findRuleItems($allRuleItems, $domain);
        }

        // 将所有的路由规则转换成路由描述格式，格式如下：
        // [
        //     'method' => '字符串，请求方法，小写',
        //     'url'    => '字符串，url路径',
        //     'dist'   => 'ReflectionMethod实例，这个请求实际调用的方法',
        // ]
        $allDescriptions = $this->ruleItemsToApiDescriptions($allRuleItems);

        $script = "// DO NOT MODIFY, generate automatically.\nimport request from '{$this->axios}'\n\n";
        $script .= implode(
            "\n",
            array_map(
                function ($description) {
                    return $this->buildFunctionScript($description) . "\n";
                },
                $allDescriptions
            )
        );

        $outputPath = env('root_path') . $this->outputPath;
        file_put_contents($outputPath, $script);
        $output->writeln('已经成功输出至：' . $outputPath);
    }

    /**
     * 递归查找路由规则
     */
    private function findRuleItems(&$saved, $item)
    {
        if ($item instanceof \think\route\RuleGroup) {
            $groups = $item->getRules();
            foreach ($groups as $method => $arr) {
                foreach ($arr as $it) {
                    $this->findRuleItems($saved, $it);
                }
            }
        } else {
            /** @var ReflectionMethod $item */
            if (!in_array($item, $saved)) {
                $saved[] = $item;
            }
        }
    }

    /**
     * 将所有的路由规则转换为路由描述格式，无法处理则输出日志并跳过，重复的函数只取第一个
     */
    private function ruleItemsToApiDescriptions($items)
    {
        $descriptions = [];
        // 全部收集
        foreach ($items as $item) {
            $result = $this->ruleItemToApiDescription($item);
            $dist = $result['dist'];
            if ($dist instanceof \RuntimeException) {
                $this->output->writeln('[忽略]' . $result['url'] . '，' . $dist->getMessage());
            } else {
                $descriptions[] = $result;
            }
        }

        // 查重
        $finalDescriptions = [];
        foreach ($descriptions as $description) {
            $dist = $description['dist'];
            if (!$this->distInDescriptions($dist, $finalDescriptions)) {
                $finalDescriptions[] = $description;
            }
        }
        return $finalDescriptions;
    }

    /**
     * 判断某个dist是否已经存在于descriptions中
     */
    private function distInDescriptions(ReflectionMethod $dist, $descriptions)
    {
        foreach ($descriptions as $description) {
            /** @var ReflectionMethod $currDist */
            $currDist = $description['dist'];
            if ($dist->class === $currDist->class && $dist->name === $currDist->name) {
                return true;
            }
        }
        return false;
    }

    /**
     * 将一条RuleItem转换为路由描述格式
     */
    private function ruleItemToApiDescription(RuleItem $item)
    {
        $route = $item->getRoute();
        $option = $item->getOption();
        if ($route instanceof \Closure) {
            $type = 'closure';
            $dist = new RuntimeException('不支持的路由类型：' . $type);
        } elseif ($route instanceof Response) {
            $type = 'response';
            $dist = new RuntimeException('不支持的路由类型：' . $type);
        } elseif (isset($option['view']) && false !== $option['view']) {
            $type = 'view';
            $dist = new RuntimeException('不支持的路由类型：' . $type);
        } elseif (!empty($option['redirect']) || 0 === strpos($route, '/') || strpos($route, '://')) {
            $type = 'redirect';
            $dist = new RuntimeException('不支持的路由类型：' . $type);
        } elseif (false !== strpos($route, '\\')) {
            $type = 'method';
            $dist = $this->parseMethod($item);
        } elseif (0 === strpos($route, '@')) {
            $type = 'controller';
            $dist = $this->parseController($item);
        } else {
            $type = 'module';
            $dist = $this->parseModule($item);
        }

        return [
            'method' => $item->getMethod(),
            'url' => '/' . $item->getRule(),
            'dist' => $dist,
        ];
    }

    private function buildFunctionScript($description)
    {
        /** @var ReflectionMethod $reflectMethod */
        $reflectMethod = $description['dist'];
        $funcName = $reflectMethod->getName();
        $url = $description['url'];
        $method = $description['method'];

        $originDocs = $reflectMethod->getDocComment();
        $pureDocs = $this->getPureDocumentComment($originDocs);
        $splitedDocs = $this->splitDocumentComments($pureDocs);
        $normalDocs = $splitedDocs['normal'];
        $annotationDocs = $splitedDocs['annotation'];
        $argsDocs = $this->findArgsDocumentComments($annotationDocs);
        $docArgDescriptions = $this->buildArgDescriptionsFromArgsDocumentComments($argsDocs);
        $urlArgDescriptions = $this->buildArgDescriptionsFromUrlAndConverUrl($url);
        $argDescriptions = $this->mergeArgDescriptions($urlArgDescriptions, $docArgDescriptions);
        $inputDescription = $this->buildInputDescriptionFromArgDescriptions($argDescriptions);

        $args = $inputDescription['args_string'];
        $append = $this->buildAppend($method, $inputDescription['payload']);
        $comments = $this->buildJsCommentsFromNormalDocumentCommentsAndArgDescriptions($normalDocs, $argDescriptions);

        return <<<EOF
$comments
export function $funcName($args) {
  return request({
    url: `$url`,
    method: '$method'$append
  })
}
EOF;
    }

    private function parseMethod(RuleItem $item)
    {
        // 路由到方法
        [$path, $var] = $item->parseUrlPath($item->getRoute());

        $route  = str_replace('/', '@', implode('/', $path));
        // dispatch[0]为类名 dispatch[1]索引为方法名
        $dispatch = strpos($route, '@') ? explode('@', $route) : $route;

        $instance = $this->app->make($dispatch[0], true);
        $action = $dispatch[1];

        try {
            return new ReflectionMethod($instance, $action);
        } catch (ReflectionException $e) {
            return new RuntimeException('方法不存在：' . $dispatch[0] . '->' . $action);
        }
    }

    private function parseController(RuleItem $item)
    {
        $route = $item->getRoute();
        [$dispatch, $vars] = $item->parseUrlPath(substr($route, 1));
        $url = implode('/', $dispatch);
        $layer = $item->getConfig('url_controller_layer');
        $appendSuffix = $item->getConfig('controller_suffix');

        $info   = pathinfo($url);
        $action = $info['basename'];
        $module = $info['dirname'];
        if ($module == '.') {
            return new RuntimeException('不支持的路由类型：controller');
        }
        try {
            $class  = $this->app->controller($module, $layer, $appendSuffix);
        } catch (ClassNotFoundException $e) {
            return new RuntimeException('控制器不存在：' . $e->getClass());
        }

        if (is_scalar($vars)) {
            if (strpos($vars, '=')) {
                parse_str($vars, $vars);
            } else {
                $vars = [$vars];
            }
        }

        return new ReflectionMethod($class, $action);
    }

    private function parseModule(RuleItem $item)
    {
        if ($item->getConfig('use_action_prefix')) {
            return new RuntimeException('路由生成不支持操作方法前缀');
        }

        [$path, $var] = $item->parseUrlPath($item->getRoute());

        $action     = array_pop($path);
        $controller = !empty($path) ? array_pop($path) : null;
        $module     = $item->getConfig('app_multi_module') && !empty($path) ? array_pop($path) : null;

        // 获取控制器名
        $controller = strip_tags($controller ?: $item->getConfig('default_controller'));
        $controller = $item->getConfig('url_convert') ? strtolower($controller) : $controller;

        // 获取操作名
        $action = strip_tags($action ?: $item->getConfig('default_action'));
        $action = $action . $item->getConfig('action_suffix');

        try {
            // 实例化控制器
            $instance = $this->controller($controller,
                $item->getConfig('url_controller_layer'),
                $module,
                $item->getConfig('controller_suffix'),
                $item->getConfig('empty_controller'));
        } catch (ClassNotFoundException $e) {
            return new RuntimeException('控制器不存在：' . $e->getClass());
        }

        if (is_callable([$instance, $action])) {
            // 严格获取当前操作方法名
            return new ReflectionMethod($instance, $action);
        } else {
            // 操作不存在
            return new RuntimeException('方法不存在：' . get_class($instance) . '->' . $action . '()');
        }
    }

    /**
     * 实例化（分层）控制器 格式：[模块名/]控制器名
     * @access public
     * @param  string $name              资源地址
     * @param  string $layer             控制层名称
     * @param  string $requestModule     $request->module，我也不知道没有请求的时候这个量是啥
     * @param  bool   $appendSuffix      是否添加类名后缀
     * @param  string $empty             空控制器名称
     * @return object
     * @throws ClassNotFoundException
     */
    private function controller($name, $layer = 'controller', $requestModule, $appendSuffix = false, $empty = '')
    {
        // 复制自ThinkPHP5.1源代码

        list($module, $class) = $this->parseModuleAndClass($name, $layer, $appendSuffix, $requestModule);

        if (class_exists($class)) {
            return $this->app->make($class, true);
        } elseif ($empty && class_exists($emptyClass = $this->app->parseClass($module, $layer, $empty, $appendSuffix))) {
            return $this->app->make($emptyClass, true);
        }

        throw new ClassNotFoundException('class not exists:' . $class, $class);
    }

    /**
     * 解析模块和类名
     * @access protected
     * @param  string $name         资源地址
     * @param  string $layer        验证层名称
     * @param  bool   $appendSuffix 是否添加类名后缀
     * @return array
     */
    private function parseModuleAndClass($name, $layer, $appendSuffix, $requestModule)
    {
        // 复制自ThinkPHP5.1源代码

        if (false !== strpos($name, '\\')) {
            $class  = $name;
            $module = $requestModule;
        } else {
            if (strpos($name, '/')) {
                list($module, $name) = explode('/', $name, 2);
            } else {
                $module = $requestModule;
            }

            $class = $this->app->parseClass($module, $layer, $name, $appendSuffix);
        }

        return [$module, $class];
    }


    /**
     * 基于字符串，删除注释的标记
     *
     * @return array
     */
    private function getPureDocumentComment($comment)
    {
        $lines = explode("\n", $comment);
        array_pop($lines);
        array_shift($lines);
        foreach ($lines as &$line) {
            $line = \preg_replace('/\s*\*\s?/', '', $line);
        }
        return $lines;
    }

    /**
     * 将注释区分为开头的普通注释和末尾的注解注释
     */
    private function splitDocumentComments($commentLines)
    {
        $normalCommentLines = [];
        $annotationCommentLines = [];

        while (count($commentLines) > 0 && preg_match('/^\s*\@\w+/', $commentLines[0]) === 0) {
            $normalCommentLines[] = array_shift($commentLines);
        }
        $annotationCommentLines = $commentLines;

        $normalCommentLines = $this->removeHeadFootEmptyLines($normalCommentLines);
        $annotationCommentLines = $this->removeHeadFootEmptyLines($annotationCommentLines);

        return [
            'normal' => $normalCommentLines,
            'annotation' => $annotationCommentLines,
        ];
    }

    /**
     * 移除开头结尾的空行
     */
    private function removeHeadFootEmptyLines($lines)
    {
        while (($n = count($lines)) > 0) {
            if (preg_match('/^\s*$/', $lines[0]) !== 0) {
                array_shift($lines);
            } elseif (preg_match('/^\s*$/', $lines[$n - 1]) !== 0) {
                array_pop($lines);
            } else {
                break;
            }
        }
        return $lines;
    }

    /**
     * 筛选出所有的@args行
     */
    private function findArgsDocumentComments($lines)
    {
        $lines = array_filter($lines, function ($line) {
            return \preg_match('/@args /', $line) === 1;
        });
        return array_values($lines);
    }

    /**
     * 从给定的@args行生成参数描述列表
     */
    private function buildArgDescriptionsFromArgsDocumentComments($lines)
    {
        $descriptionList = [];
        foreach ($lines as $line) {
            $flag = preg_match('/@args(\s+(int|integer|float|double|number|str|string|bool|boolean|obj|object))?\s+(\w+)(\?|\+)?(\s+(.+))?$/', $line, $matches);
            if ($flag === 0) {
                continue;
            }

            // 默认类型描述符
            $type = '*';
            if (isset($matches[1]) && $this->isValidArgType($matches[2])) {
                // 有类型描述符
                $type = $this->convertTypeToJsType($matches[2]);
            }

            $name = $matches[3]; // 参数名称

            $spec = 'required'; // 特征，默认是必须
            if (isset($matches[4])) {
                if ($matches[4] === '?') {
                    $spec = 'optional'; // 参数名后带?表示可选
                } else if ($matches[4] === '+') {
                    $spec = 'disordered'; // 参数名后带+表示无序参数（对象式参数）
                }
            }

            $comment = $name; // 注释默认是参数名本身
            if (isset($matches[5])) {
                $comment = $matches[6]; // 如果有额外注释则替换
            }

            $description = [
                'name' => $name, // 参数名称
                'type' => $type, // 参数类型
                'spec' => $spec, // 参数特征，必须/可选/无序的
                'comment' => $comment, // 注释
            ];
            $descriptionList[] = $description;
        }
        return $descriptionList;
    }

    /**
     * 从URL中生成URL参数，同时将URL转换为JS内联字符串
     */
    private function buildArgDescriptionsFromUrlAndConverUrl(&$url)
    {
        $descriptionList = [];
        $resultUrl = preg_replace_callback(
            '/<(\w+)>/',
            function ($matches) use (&$descriptionList) {
                $descriptionList[] = [
                    'name' => $matches[1], // 参数名称
                    'type' => '*', // 参数类型
                    'spec' => 'required', // 参数特征，必须/可选/无序的
                    'comment' => '', // 注释
                ];
                return '${' . $matches[1] . '}';
            },
            $url
        );

        $url = $resultUrl;
        return $descriptionList;
    }

    /**
     * 将URL参数中的参数描述和文档中的参数描述合并
     */
    private function mergeArgDescriptions($urlArgDescriptions, $docArgDescriptions)
    {
        $nameToDesp = [];
        foreach ($docArgDescriptions as $desp) {
            $desp['position'] = 'payload';
            $nameToDesp[$desp['name']] = $desp;
        }
        foreach ($urlArgDescriptions as $desp) {
            if (isset($nameToDesp[$desp['name']])) {
                // 如果doc中已经描述，只需要强制为必选参数即可
                $nameToDesp[$desp['name']]['position'] = 'url';
                $nameToDesp[$desp['name']]['spec'] = 'required';
            } else {
                $desp['position'] = 'url';
                $nameToDesp[$desp['name']] = $desp;
            }
            if (empty($nameToDesp[$desp['name']]['comment'])) {
                $nameToDesp[$desp['name']]['comment'] = $desp['name'];
            }
        }
        return array_values($nameToDesp);
    }

    /**
     * 从参数描述列表生成参数描述
     */
    private function buildInputDescriptionFromArgDescriptions($descriptions)
    {
        $descriptions = array_map(function ($item) {
            $item['type'] = $this->convertTypeToJsType($item['type']);
            $item['default'] = $this->getDefaultValue($item['type'], '');
            return $item;
        }, $descriptions);

        $left = array_values(array_filter($descriptions, function ($item) {
            return $item['spec'] === 'required';
        }));
        $middle = array_values(array_filter($descriptions, function ($item) {
            return $item['spec'] === 'disordered';
        }));
        $right = array_values(array_filter($descriptions, function ($item) {
            return $item['spec'] === 'optional';
        }));
        $payloadDescriptions = array_values(array_filter($descriptions, function ($item) {
            return $item['position'] === 'payload';
        }));

        $leftList = array_map(function ($item) {
            return $item['name'];
        }, $left);
        $middleStr = '{ ' . implode(', ', array_map(function ($item) {
            return $item['name'];
        }, $middle)) . ' }';
        $rightList = array_map(function ($item) {
            return $item['name'] . ' = ' . $item['default'];
        }, $right);

        $argList = [];
        $argList = \array_merge($argList, $leftList);
        if (count($middle) > 0) {
            $argList[] = $middleStr;
        }
        $argList = \array_merge($argList, $rightList);
        $argListStr = implode(', ', $argList);

        $payloadStr = "{\n" . \implode(",\n", array_map(function ($item) {
            return '      ' . $item['name'];
        }, $payloadDescriptions)) . "\n    }";
        $payload = count($payloadDescriptions) > 0 ? $payloadStr : null;

        return [
            'arg_count' => count($descriptions),
            'required_args' => $left,
            'disordered_args' => $middle,
            'optional_args' => $right,
            'args_string' => $argListStr,
            'payload' => $payload,
        ];
    }

    /**
     * 根据请求方法和payload数据构建append字符串
     */
    private function buildAppend($method, $payload)
    {
        if ($payload !== null) {
            if (in_array($method, ['post', 'put', 'patch'])) {
                return ",\n    data: " . $payload;
            } else {
                return ",\n    params: " . $payload;
            }
        } else {
            return '';
        }
    }

    /**
     * 判断@args注释中，给定的单词$type是否是合法的类型
     */
    private function isValidArgType($type)
    {
        return in_array($type, [
            'int',
            'integer',
            'float',
            'double',
            'number',
            'str',
            'string',
            'bool',
            'boolean',
            'obj',
            'object',
        ]);
    }

    /**
     * 将注释中描述的类型转为JS注释类型
     */
    private function convertTypeToJsType($type)
    {
        switch ($type) {
            case 'int':
            case 'integer':
            case 'float':
            case 'double':
                $type = 'number';
                break;
            case 'str':
                $type = 'string';
                break;
            case 'bool':
                $type = 'boolean';
                break;
            case 'obj':
                $type = 'object';
                break;
        }
        return $type;
    }

    /**
     * 根据已转换的JS注释类型，获取对应值的默认值，若已经给出则返回原始值或者转化为合适的值
     */
    private function getDefaultValue($type, $default)
    {
        switch ($type) {
            case 'number':
                if (!is_numeric($default)) {
                    $default = '0';
                }
                break;
            case 'string':
                $n = strlen($default);
                if ($n > 0) {
                    $l = \substr($default, 0, 1);
                    $r = \substr($default, $n - 1, 1);
                    if ($l !== $r || !($l === '"' || $l === '\'')) {
                        $default = '\'' . $default . '\'';
                    }
                } else {
                    $default = '\'\'';
                }
                break;
            case 'boolean':
                $default = strtolower($default);
                if ($default !== 'true' && $default !== 'false') {
                    $default = 'false';
                }
                break;
            case 'object':
                if (empty($default)) {
                    $default = 'null';
                }
                break;
            case '*':
            case 'undefined':
                $default = 'undefined';
                break;
            default:
                if (empty($default)) {
                    $default = 'null';
                }
                break;
        }

        return $default;
    }

    /**
     * 从PHP文档注释和@args描述中生成JS注释
     */
    private function buildJsCommentsFromNormalDocumentCommentsAndArgDescriptions($normalDocs, $argDescriptions)
    {
        $lines = $normalDocs;

        if (count($argDescriptions) > 0) {
            $lines[] = '';
            foreach ($argDescriptions as $desp) {
                // 'name' => $name, // 参数名称
                // 'type' => $type, // 参数类型
                // 'spec' => $spec, // 参数特征，必须/可选/无序的
                // 'comment' => $comment, // 注释
                extract($desp);

                $paramLine = "@param {{$type}} $name $comment";
                $lines[] = $paramLine;
            }
        }

        $lines = array_map(function ($line) {
            return ' * ' . $line;
        }, $lines);

        array_unshift($lines, '/**');
        array_push($lines, ' */');

        return implode("\n", $lines);
    }
}
