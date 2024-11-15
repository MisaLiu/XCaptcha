# XCaptcha-for-Typecho

用于Typecho博客系统的插件，为你的博客评论、注册、登陆添加验证码。


## 支持

目前支持的验证码如下：

* [hCaptcha](https://www.hcaptcha.com)
* [Cloudflare Turnstile](https://www.cloudflare.com)
* [Google reCaptcha v2](https://developers.google.cn/recaptcha/docs/display?hl=zh-cn)
* [极验证Geetest v3](https://www2.geetest.com)


## 使用方式

### 下载本项目

通过git命令来获取本项目：

```sh
git clone https://github.com/CairBin/XCaptcha.git
```

或者通过下载的方式获取压缩包，解压后的文件夹名称修改为`XCaptcha`。

### 配置插件

将`XCaptcha`文件夹上传到你的Typecho博客的`usr/plugins/`目录下，在Typecho后台的插件面板里激活即可。

从上述所支持验证码的官网注册账户，然后获取你站点的`Captcha ID`和`Secret Key`，有的也叫`Site Key`和`Secret Key`，本质上都一样，对应公钥和私钥。前者用于前端标识可以公开，后者用于服务端向验证码服务器校验需要保密。

修改你主题模板的`comments.php`文件，主题目录是`usr/themes/your_them/`，在评论提交按钮之前或者表单最后添加一行代码:

```php
<?php if (array_key_exists('XCaptcha', Typecho_Plugin::export()['activated'])) : XCaptcha_Plugin::showCaptcha(); endif; ?>
```

回到博客后台配置插件，将获取到的ID/Key填写进去，以及配置其他参数即可。

这里需要特别说明一下，“引入JS的CDN加速地址”取决于网络情况，它会替代原本引入的验证初始化脚本（不是二次验证的接口），如果遇到某些JS加载缓慢可以使用，**如果不明白留空即可**。

另外，如果勾选了开启登陆页面验证码则，注册页面也会跟着开启，前提是你启动了注册功能。


## 项目展示

![登陆界面](/img/login_page.png)

![插件配置](/img/plugin_config.png)

![评论区](/img/comment_area.png)


## 特别感谢

本项目在编写时参考了其他插件项目：

* [noisky/typecho-plugin-geetest](https://github.com/noisky/typecho-plugin-geetest)
* [scenery/typecho-plugins/CaptchPlus](https://github.com/scenery/typecho-plugins)