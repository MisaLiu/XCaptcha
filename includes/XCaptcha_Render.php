<?php

include_once "XCaptcha_Utils.php";

class XCaptcha_Render
{
    /**
     * 输出通用部分HTML和JS
     * @static
     * @param string  $cdnUrl        验证码渲染脚本CDN地址
     * @param string  $captchaScript 插入的JS脚本
     * @param string  $parg          哪个页面
     * @return {*}
     */    
    private static function echoContent($cdnUrl, $captchaScript, $page)
    {
        if($page == 'login'){
            echo <<<EOF
		    <script>
			    var jqForm = $("form");
			    var jqFormSubmit = jqForm.find(":submit");
			    jqFormSubmit.parent().before(`$captchaScript`);
		    </script>
        
EOF;
        }else if($page == 'comments'){
            echo $captchaScript;
        }

        echo<<<EOF
        <script src="$cdnUrl"></script>
EOF;

    }

    /**
     * 输出Geetest渲染的HTML JS
     * @param string $widgetSize 尺寸
     * @param XCaptcha_GeetestInfo $geetest 极验证信息类
     */    
    private static function echoGeetestContent($widgetSize, XCaptcha_GeetestInfo $geetest)
    {
        $sizeSelector = ["normal" => "300px", "flexible"=>"100%", "compact" => "150px"];
        $geetestSize = $sizeSelector[$widgetSize];
        $dismod = $geetest->dismod;

        $ajaxUri = '/index.php/action/xcaptcha?do=ajaxResponseCaptchaData';
        echo <<<EOF
        <script>
            const initializeGeetestCaptcha = ()=>{
                var ajaxUri = "{$ajaxUri}";
                var url = ajaxUri + '&t=' + (new Date()).getTime();
                
                fetch(url, {method: 'GET'})
               .then(function (response) {
                    return response.json();
                })
               .then(function (data) {
                    initGeetest({
                        gt: data.gt,
                        challenge: data.challenge,
                        new_captcha: data.new_captcha,
                        product: '$dismod',
                        offline:!data.success,
                        width: '$geetestSize'
                    }, function (captchaObj) {
                        var jqGtCaptcha = document.getElementById('gt-captcha');
                        captchaObj.appendTo(jqGtCaptcha);
                    });
                })
               .catch(function (error) {
                    console.log('请求出错：', error);
                });
            }
            initializeGeetestCaptcha();
            
		</script>

EOF;

    }

    /**
     * 渲染登录/注册页的验证码
     * @static
     * @param XCaptcha_Config $config 配置类
     */    
    public static function renderLoginPage(XCaptcha_Config $config)
    {
        $widgetOptions = Typecho_Widget::widget('Widget_Options');
        if (stripos($widgetOptions->request->getRequestUrl(), 'login.php') === false &&
			stripos($widgetOptions->request->getRequestUrl(), 'register.php') === false) {
            return;
        }

        // 是否启用login的验证码
        if(!$config->isCaptchaEnabledOnPage('login')){
            return;
        }
        // 密钥是否为空，如果为空则不渲染验证码
        if(!$config->checkKeys()){
            Typecho_Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys'), 'error');
			return;
        }

        list($captchaScript, $cdnUrl) = XCaptcha_Utils::getCaptchaScript(
            $config->getCaptchaChoosen(),
            $config->getCdnUrl(),
            $config->getWidgetColor(),
            $config->getwidgetSize(),
            $config->getCaptchaId()
        );
        if (!$captchaScript) return;    // 模板不存在则不渲染

        self::echoContent($cdnUrl, $captchaScript, 'login');    // 输出通用部分
        if($config->getCaptchaChoosen() == 'geetest'){
            // geetest需要额外配置
            self::echoGeetestContent($config->getWidgetSize(), $config->getGeetestConfig());
        }
    }

    /**
     * 渲染评论区的验证码
     * @static
     * @param XCaptcha_Config $config 配置类
     */  
    public static function renderCommentPage(XCaptcha_Config $config)
    {
		// 是否启用comments的验证码
        if(!$config->isCaptchaEnabledOnPage('comments')){
            return;
        }
        // 密钥是否为空，如果为空则不渲染验证码
        if(!$config->checkKeys()){
            Typecho_Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys'), 'error');
			return;
        }
        // 是否开启登录用户不校验 且 用户处于登录状态 且 为administrator，都符合则不渲染
        $user = Typecho_Widget::widget('Widget_User');
        if($config->isAuthorUncheck() && $user->hasLogin() && $user->pass('administrator', true))
            return;

        list($captchaScript, $cdnUrl) = XCaptcha_Utils::getCaptchaScript(
            $config->getCaptchaChoosen(),
            $config->getCdnUrl(),
            $config->getWidgetColor(),
            $config->getwidgetSize(),
            $config->getCaptchaId()
        );
        if (!$captchaScript) return;

        self::echoContent($cdnUrl, $captchaScript, 'comments');    // 输出通用部分
        if($config->getCaptchaChoosen() == 'geetest'){
            // geetest需要额外配置
            self::echoGeetestContent($config->getWidgetSize(), $config->getGeetestConfig());
        }
    }
    
}