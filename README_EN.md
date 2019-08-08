# horrible-api-builder

An api builder for ThinkPHP5.1. It uses the route file and @arg annotations to build an api.js to send request.

## Example

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
     *
     * @param  int  $id
     * @return \think\Response
     * @args id CommentID
     * @args text Comment Content
     */
    public function editComment($id)
    {
        // ...
    }
}
```

Build Result

``` js
// DO NOT MODIFY, generate automatically.
import request from './request'

/**
 * Edit comment by ID
 * 
 * @param {*} id CommentID
 * @param {*} text Comment Content
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

## Usage

1. Copy `BuildApiJs.php` to `/application/command/BuildApiJs.php`
2. `php think util:build-apijs`
3. Copy `/api.js` to your front end project

### About the annotations

The standard format is: 
`@args [argument type] <argument name>[addtion] [argument description]`

The argument type is optional, default to `*`.
The argument type must in `int|integer|float|double|number|str|string|bool|boolean|obj|object`, or will be supposed to be the argument name.

The argument name should suit to `\w+`.

The addtion indicate the characteristic of the argument, see next section for more information.

The argument description is optional, default to argument name;

Tips: The URL params will always be in the argument list.

### About the addtion

The addtion indicate the characteristic of the argument.
There are three types: required, disordered, optional.
Use nothing for required, `+` for disordered, `?` for optional.
A full example are below.

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

Build Result

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

## Others

### About the request method

If the request method is in `post|put|patch`, the arguments will be assigned in data. Otherwise, in params.

### About the URL params

The URL params are forced change to required. If you don't like this, comment the 498 line in the source code.

### Why not publish to packagist

I love copy and paste.

## Custom

Because I am very lazy, I haven't set any command line parameters. Please modify the source code to achieve custom function.

### Modify the dependency name

If your dependency name is not `axios`, such as a copy from `vue add axios`, you can set the $axios at line 22 of the source code. Like this:

``` php
protected $axios = './request';
```

Then, the build file:

``` js
// DO NOT MODIFY, generate automatically.
import request from './request'
...
```

### Modify the output path

The output path is set to `/api.js` as default. Set the $outputPath at line 24 to whatever you like to change the output path. Like this:

``` php
protected $outputPath = 'public/js/api.js';
```

### I don't like the undefined default value for the optional arguments. Can I set it in the annotations?

Emmm... Maybe next time.
