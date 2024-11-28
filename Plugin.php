<?php

/**
 * 设置评论验证码
 *
 * @package XCaptcha
 * @author CairBin
 * @version 1.0.2
 * @link https://cairbin.top
 */

include 'lib/class.geetestlib.php';

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Widget\Options;
use Typecho\Widget\Exception;
use Typecho\Widget;
use Typecho\Widget\Helper\Form\Element\Select;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class XCaptcha_Plugin implements Typecho_Plugin_Interface
{
    /**
     * Activate the plugin
     */
    public static function activate()
    {
		// comments hook
        Typecho_Plugin::factory('Widget_Feedback')->comment 	= [__CLASS__, 'filter'];
		Typecho_Plugin::factory('Widget_Feedback')->trackback 	= [__CLASS__, 'filter'];
        Typecho_Plugin::factory('Widget_XmlRpc')->pingback 		= [__CLASS__, 'filter'];

		// Login page hook
        Typecho_Plugin::factory('admin/footer.php')->end = [__CLASS__, 'renderLoginCaptcha'];
        Typecho_Plugin::factory('Widget_User')->loginSucceed = [__CLASS__, 'verifyLoginCaptcha'];

		Typecho_Plugin::factory('XCaptcha')->responseGeetest = [__CLASS__, 'responseGeetest'];
        
        Helper::addAction('xcaptcha', 'XCaptcha_Action');
    }

    /**
     * Deactivate the plugin
     */
    public static function deactivate() 
	{
		Helper::removeAction('xcaptcha');
	}

	public static function responseGeetest()
	{
		@session_start();
		$filter = Helper::options()->plugin('XCaptcha');
		$geetestSdk = new GeetestLib($filter->captchaId, $filter->secretKey);
		$widgetRequest = Widget::widget('Widget_Options')->request;
		$agent = $widgetRequest->getAgent();

		$data = [
			'user_id' => rand(1000, 9999),
			'client_type' => self::isMobile($agent) ? 'h5' : 'web',
			'ip_address'=> $widgetRequest->getIp()
		];

		$_SESSION['gt_server_ok'] = $geetestSdk->pre_process($data, 1);
        $_SESSION['gt_user_id'] = $data['user_id'];

        echo $geetestSdk->get_response_str();
	}

	/**
	 * Checking keys not empty
	 */
	private static function checkKeys($filter)
	{
		$captchaId = $filter->captchaId;
		$secretKey = $filter->secretKey;
		if($captchaId == "" || $secretKey == "") return false;

		return true;
	}

    /**
     * Verify login captcha
     */
    public static function verifyLoginCaptcha()
    {
        $filter = Options::alloc()->plugin("XCaptcha");
        if (!in_array("login", $filter->pages)) return; // Skip if login page captcha is disabled

		if(!self::checkKeys($filter)){
			Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys'), 'error');
			return;
		}

        if($filter->captchaChoosen == 'geetest'){
            if(!self::verifyGeetest($filter)){
                Widget::widget('Widget_Notice')->set(_t('Captcha verification failed.'), 'error');
                Widget::widget('Widget_User')->logout();
                Widget::widget('Widget_Options')->response->goBack();
                exit();
            }
            return;
        }


        list($postToken, $urlPath) = self::getCaptchaTokenAndUrl($filter);

        $responseData = self::makeCaptchaRequest($urlPath, $filter->secretKey, $postToken);
        if ($responseData->success == true) return;

        Widget::widget('Widget_Notice')->set(_t('Captcha verification failed.'), 'error');
        Widget::widget('Widget_User')->logout();
        Widget::widget('Widget_Options')->response->goBack();
    }

    /**
     * Render captcha on login page
     */
    public static function renderLoginCaptcha()
    {
        $widgetOptions = Widget::widget('Widget_Options');
        if (stripos($widgetOptions->request->getRequestUrl(), 'login.php') === false &&
			stripos($widgetOptions->request->getRequestUrl(), 'register.php') === false) {
            return; // Skip if not login page
        }

        $filter = Options::alloc()->plugin("XCaptcha");
		if(!self::checkKeys($filter)){
			Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys'), 'error');
			return;
		}

        if (!in_array("login", $filter->pages ?? [])) return; // Skip if login page captcha is disabled

        list($captchaScript, $cdnUrl) = self::getCaptchaScript($filter);
        if (!$captchaScript) return;

        echo "<script src='{$cdnUrl}'></script>";
        echo <<<EOF
		<script>
			var jqForm = $("form");
			var jqFormSubmit = jqForm.find(":submit");
			jqFormSubmit.parent().before(`$captchaScript`);
		</script>
		EOF;

        if($filter->captchaChoosen != "geetest") return;

        $sizeSelector = ["normal" => "200px", "flexible"=>"100%", "compact" => "150px"];
        $geetestSize = $sizeSelector[$filter->widgetSize];
        $dismod = $filter->dismod;

        $ajaxUri = '/index.php/action/xcaptcha?do=ajaxResponseCaptchaData';
        echo <<<EOF
        <script>
            var jqGtCaptcha = $("#gt-captcha");
		    $.ajax({
		        url:"{$ajaxUri}&t=" + (new Date()).getTime(),
		        type: "get",
                dataType: "json",
                success: function (data) {
		            initGeetest({
		                gt:data.gt,
		                challenge:data.challenge,
                        new_captcha: data.new_captcha,
                        product: '$dismod',
	                    offline:!data.success,
                        width: '$geetestSize'
		        }, function(captchaObj){
	                    captchaObj.appendTo(jqGtCaptcha);
		            })
    	        }
	        })
		</script>
EOF;

    }

    /**
     * Config panel
     */
    public static function config(Form $form)
    {
        $form->addInput(new Checkbox('pages', ["comments" => "评论", "login" => "登陆页"], [], _t('应用到'), _t('在哪些页面应用验证码')));
        $form->addInput(new Text('captchaId', NULL, '', _t('Captcha ID'), _t('公钥(ID)')));
        $form->addInput(new Text('secretKey', NULL, '', _t('Secret Key'), _t('私钥(Key)')));
        $form->addInput(new Radio('widgetColor', ["auto"=>"自动", "light" => "浅色", "dark" => "深色"], "auto", _t('颜色'), _t('设置验证工具主题颜色，默认为浅色<br/>- hCaptcha不支持自动<br/>- reCaptcha v2不支持自动<br/>- 极验证v3不支持颜色')));
        $form->addInput(new Radio('widgetSize', ["normal" => "常规", "flexible"=>"灵活", "compact" => "紧凑"], "normal", _t('样式'), _t('设置验证框布局样式，默认为常规<br/>- hCaptcha不支持灵活<br/>- reCaptcha v2不支持灵活')));
        $form->addInput(new Radio('captchaChoosen', ["hcaptcha" => "hCaptcha", "cloudflare" => "Cloudflare", "recaptcha" => "Google reCaptcha v2", "geetest" => "极验证 v3"], "hcaptcha", _t('验证工具'), _t('选择验证工具')));
        $form->addInput(new Text('cdnUrl', NULL, '', _t('验证码JS地址:'), _t('用于CDN加速加载验证码, 留空引入默认JS</br>注意使用 https 协议')));
        $form->addInput(new Text('verifyUrl', NULL, '', _t('校验地址:'), _t('用于设置验证码校验接口, 留空使用默认, 此项不支持极验证')));
        $form->addInput(new Radio('enableJquery', ["enable"=>"启用", "disable"=>"不启用"], "enable", _t('启用JQuery'), _t('根据你的主题判断是否使用JQuery, 默认启用<br/>如果主题自带就不用了, 如果没有需要启用<br/>此选项针对极验证<br/>如果你不懂此选项先保持默认, 根据极验证是否能加载判断')));
        $form->addInput(new Text('jqueryUrl', NULL, 'https://cdn.jsdelivr.net/npm/jquery@2.2.4/dist/jquery.min.js', _t('JQuery CDN URL'), _t('jQuery的地址, 可根据网络情况更换CDN, 只有勾选了启用此项才有效')));
        $form->addInput(new Select('dismod', array('float' => '浮动式（float）', 'embed' => '嵌入式（embed）', 'popup' => '弹出框（popup）'), 'float', _t('极验证展现形式：')));
            
    }

    public static function personalConfig(Form $form) {}

    /**
     * Show captcha
     */
    public static function showCaptcha()
    {
        $filter = Options::alloc()->plugin("XCaptcha");
		if(!in_array("comments", $filter->pages)) return;
		if(!self::checkKeys($filter)){
			Widget::widget('Widget_Notice')->set(_t('XCaptcha: No keys'), 'error');
			return;
		}
        list($captchaScript, $cdnUrl) = self::getCaptchaScript($filter);
        if (!$captchaScript) return;

		echo $captchaScript;
		echo "<script src='{$cdnUrl}'></script>";

		if($filter->captchaChoosen == 'geetest'){
			$ajaxUri = '/index.php/action/xcaptcha?do=ajaxResponseCaptchaData';
            if($filter->enableJquery == 'enable'){
                echo '<script src="' . $filter->jqueryUrl .'"></script>';
            }

            $sizeSelector = ["normal" => "200px", "flexible"=>"100%", "compact" => "150px"];
            $geetestSize = $sizeSelector[$filter->widgetSize];
            $dismod = $filter->dismod;
			echo <<<EOF
			<script>
                var jqGtCaptcha = $("#gt-captcha");
				$.ajax({
					url:"{$ajaxUri}&t=" + (new Date()).getTime(),
					type: "get",
            		dataType: "json",
            		success: function (data) {
						initGeetest({
							gt:data.gt,
							challenge:data.challenge,
                            new_captcha: data.new_captcha,
                            product: '$dismod',
							offline:!data.success,
                            width: '$geetestSize'
						}, function(captchaObj){
							captchaObj.appendTo(jqGtCaptcha);
						})
					}
				})
			</script>
EOF;
		}
		
    }

    /**
     * Filter captcha on comments
     */
    public static function filter($comment)
    {
        $filter = Options::alloc()->plugin('XCaptcha');

        if(!in_array("comments", $filter->pages)) return $comment;

		if(!in_array("comments", $filter->pages)) return $comment;
		if(!self::checkKeys($filter)){
			throw new Exception(_t('XCaptcha: No keys.'));
		}

        
        if($filter->captchaChoosen == 'geetest'){
            if(!self::verifyGeetest($filter)){
                echo "<script language=\"JavaScript\">alert(\"Captcha verification failed. \");window.history.go(-1);</script>";
                exit();
            }

            return $comment;
        }


		list($postToken, $urlPath) = self::getCaptchaTokenAndUrl($filter);
        $responseData = self::makeCaptchaRequest($urlPath, $filter->secretKey, $postToken);
		if ($responseData->success == false) {
			echo "<script language=\"JavaScript\">alert(\"Captcha verification failed. \");window.history.go(-1);</script>";
            exit();
        }
        return $comment;
    }

    public static function verifyGeetest($filter)
    {
        if (!isset($_POST['geetest_challenge']) || !isset($_POST['geetest_validate']) || !isset($_POST['geetest_seccode'])) {
            return 0;
        }

        @session_start();
        $geetestSdk = new GeetestLib($filter->captchaId, $filter->secretKey);
        if (!empty($_SESSION['gt_server_ok'])) {

            $widgetRequest = Widget::widget('Widget_Options')->request;
            $agent = $widgetRequest->getAgent();
            $clientType = self::isMobile($agent) ? 'h5' : 'web';
            $ipAddress = $widgetRequest->getIp();

            $data = array(
                'user_id' => $_SESSION['gt_user_id'],
                'client_type' => $clientType,
                'ip_address' => $ipAddress
            );

            return $geetestSdk->success_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode'], $data);
        }
        return $geetestSdk->fail_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode']);

    }

    public static function isMobile($userAgent)
    {
        return preg_match('/android.+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $userAgent) || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(di|rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($userAgent, 0, 4));
    }


    /**
     * Get token and URL for captcha validation
     */
    private static function getCaptchaTokenAndUrl($filter)
    {
        $captchaMap = [
            "hcaptcha" => ["h-captcha-response", "https://hcaptcha.com/siteverify"],
            "cloudflare" => ["cf-turnstile-response", "https://challenges.cloudflare.com/turnstile/v0/siteverify"],
            "recaptcha" => ["g-recaptcha-response", "https://recaptcha.net/recaptcha/api/siteverify"]
        ];
        $captchaType = $filter->captchaChoosen;
        $verifyUrl = $filter->verifyUrl;

        $postToken = $_POST[$captchaMap[$captchaType][0]] ?? '';
        $urlPath = $verifyUrl == '' ? $captchaMap[$captchaType][1] : $verifyUrl;

        return [$postToken, $urlPath];
    }

    /**
     * Make captcha request
     */
    private static function makeCaptchaRequest($urlPath, $secretKey, $postToken)
    {
		$postData = http_build_query([
			'secret' => $secretKey,
			'response' => $postToken
		]);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $urlPath);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 secs timeout
		
		$response = curl_exec($ch);
		
		if (curl_errno($ch)) {
			echo '请求错误: ' . curl_error($ch);
		}
		
		curl_close($ch);
        return json_decode($response);
    }

    /**
     * Get captcha script and CDN URL
     */
    private static function getCaptchaScript($filter)
    {
        $cdnUrls = [
            "hcaptcha" => "https://hcaptcha.com/1/api.js",
            "cloudflare" => "https://challenges.cloudflare.com/turnstile/v0/api.js",
            "recaptcha" => "https://recaptcha.net/recaptcha/api.js",
            "geetest" => "https://static.geetest.com/static/js/gt.0.4.9.js"
        ];
        $captchaType = $filter->captchaChoosen;
        $cdnUrl = $filter->cdnUrl ?: $cdnUrls[$captchaType] ?? '';
        $captchaId = $filter->captchaId;
        $widgetColor = $filter->widgetColor;
        $widgetSize = $filter->widgetSize;

		if($captchaType == 'hCaptcha' || $captchaType == 'recaptcha'){
			if($widgetColor == 'auto') 		$widgetColor = 'light';
			if($widgetSize == 'flexible') 	$widgetSize = 'normal';
		}

        $scriptTemplates = [
            "hcaptcha" => "<div class='h-captcha' data-sitekey='{$captchaId}' data-theme='{$widgetColor}' data-size='{$widgetSize}'></div>",
            "cloudflare" => "<div class='cf-turnstile' data-sitekey='{$captchaId}' data-theme='{$widgetColor}' data-size='{$widgetSize}'></div>",
            "recaptcha" => "<div class='g-recaptcha' data-sitekey='{$captchaId}' data-theme='{$widgetColor}' data-size='{$widgetSize}'></div>",
            "geetest" => "<div id='gt-captcha'></div>"
        ];

        return [$scriptTemplates[$captchaType] ?? '', $cdnUrl];
    }


}