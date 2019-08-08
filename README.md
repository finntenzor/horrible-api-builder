# horrible-api-builder

基于ThinkPHP5.1的API生成器，根据route文件和代码中的@args注释生成api.js

[English Edition Document](README_EN.md)

## 例子

/route/route.php

``` php
Route::put('comment/:id', 'index/Index/editComment');
```

/application/index/controller/Index.php

``` php
namespace app\index\controller;

use think\Controller;

class Index extends Controller
{
    /**
     * Edit comment by ID
     * 根据ID编辑留言
     *
     * @param  int  $id
     * @return \think\Response
     * @args id 留言ID
     * @args text 留言内容
     */
    public function editComment($id)
    {
        // ...
    }
}
```

生成结果

``` js
// DO NOT MODIFY, generate automatically.
import request from 'axios'

/**
 * Edit comment by ID
 * 根据ID编辑留言
 * 
 * @param {*} id 留言ID
 * @param {*} text 留言内容
 */
export function editComment(id, text) {
  return request({
    url: `/comment/${id}`,
    method: 'put',
    data: {
      text
    }
  })
}

```

## 用法

1. 将 `BuildApiJs.php` 复制到 `/application/command/BuildApiJs.php`
2. 执行 `php think util:build-apijs`
3. 将 `/api.js` 复制到你的前端项目中

### 注解写法

注解格式为
`@args [参数类型] <参数名>[附加描述] [参数描述]`

参数类型可以省略，默认为`*`。注意类型必须为`int|integer|float|double|number|str|string|bool|boolean|obj|object`之中，否则将会认为是参数名

参数名应该是合法标识符

参数描述可以省略，省略时生成代码使用参数名填写描述

注：URL中绑定的参数即使不写也会自动添加上这个参数的

### 附加描述

附加描述表示了参数的特征，有三种：必选、无序、可选。使用（空白）、+、?表示，例如下面这个例子使用了所有的三种附加描述。

``` php
/**
 * Some Function
 *
 * @return \think\Response
 * @args arg_a
 * @args arg_b+
 * @args arg_c+
 * @args arg_d?
 */
public function someFunc()
{
    // ...
}
```

生成结果

``` js
// DO NOT MODIFY, generate automatically.
import request from 'axios'

/**
 * Some Function
 * 
 * @param {*} arg_a arg_a
 * @param {*} arg_b arg_b
 * @param {*} arg_c arg_c
 * @param {*} arg_d arg_d
 * @param {*} id id
 */
export function someFunc(arg_a, id, { arg_b, arg_c }, arg_d = undefined) {
  return request({
    url: `/comment/${id}`,
    method: 'put',
    data: {
      arg_a,
      arg_b,
      arg_c,
      arg_d
    }
  })
}

```

## 其他说明

### 关于请求方法

如果定义的请求方法在`post|put|patch`之中，请求参数将附加在data中，否则请求参数附加在params中

### 关于URL参数

URL参数将会强制改为必选参数，以防止手误。如果不需要这个功能，注释掉第498行即可。

### 为什么没有写成第三方库

一时Copy一时爽，一直Copy一直爽。

## 自定义

因为作者比较懒的原因，没有设置命令行参数，请直接修改源代码以实现自定义功能。

### 修改依赖名

如果你添加了拦截器等（比如`vue add axios`生成的代码文件），那么可以将源代码第22行的成员变量axios改为其他值，例如：

``` php
protected $axios = './request';
```

那么生成出来的代码是：

``` js
// DO NOT MODIFY, generate automatically.
import request from './request'
...
```

### 修改输出路径

默认输出路径为根目录下的api.js，如果希望输出到其他目录，可以将源代码地24行成员变量outputPath改为其他值，例如：

``` php
protected $outputPath = 'public/js/api.js';
```

### 将可选参数默认值从undefined换成其他值，最好在注释中声明默认值

AWSL，下次再写吧。
