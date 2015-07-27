<?php
	class WpFastestCacheCreateCache extends WpFastestCache{
		public $options = array();
		public $cdn;
		private $startTime;
		private $blockCache = false;
		private $err = "";

		public function __construct(){
			//to fix: PHP Notice: Undefined index: HTTP_USER_AGENT
			$_SERVER['HTTP_USER_AGENT'] = isset($_SERVER['HTTP_USER_AGENT']) && $_SERVER['HTTP_USER_AGENT'] ? $_SERVER['HTTP_USER_AGENT'] : "Empty User Agent";
			
			$this->options = $this->getOptions();

			$this->checkActivePlugins();

			$this->set_cdn();
		}

		public function set_cdn(){
			$cdn_values = get_option("WpFastestCacheCDN");
			if($cdn_values){
				$std = json_decode($cdn_values);

				$std->originurl = trim($std->originurl);
				$std->originurl = trim($std->originurl, "/");
				$std->originurl = preg_replace("/http(s?)\:\/\/(www\.)?/i", "", $std->originurl);

				$std->cdnurl = trim($std->cdnurl);
				$std->cdnurl = trim($std->cdnurl, "/");
				$std->cdnurl = preg_replace("/http(s?)\:\/\/(www\.)?/i", "", $std->cdnurl);
				$this->cdn = $std;
			}
		}

		public function checkActivePlugins(){
			//for WP-Polls
			if($this->isPluginActive('wp-polls/wp-polls.php')){
				require_once "wp-polls.php";
				$wp_polls = new WpPollsForWpFc();
				$wp_polls->execute();
			}
		}

		public function checkShortCode($content){
			if(preg_match("/\[wpfcNOT\]/", $content)){
				if(!is_home() || !is_archive()){
					$this->blockCache = true;
				}
				$content = str_replace("[wpfcNOT]", "", $content);
			}
			return $content;
		}

		public function createCache(){
			if(isset($this->options->wpFastestCacheStatus)){
				$this->startTime = microtime(true);
				add_action( 'get_footer', array($this, "wp_print_scripts_action"));
				ob_start(array($this, "callback"));
			}
		}

		public function wp_print_scripts_action(){
			echo "<!--WPFC_FOOTER_START-->";
		}

		public function ignored(){
			$list = array(
						"\/wp\-comments\-post\.php",
						"\/sitemap\.xml",
						"\/wp\-login\.php",
						"\/robots\.txt",
						"\/wp\-cron\.php",
						"\/wp\-content",
						"\/wp\-admin",
						"\/wp\-includes",
						"\/index\.php",
						"\/xmlrpc\.php",
						"\/wp\-api\/",
						"leaflet\-geojson\.php",
						"\/clientarea\.php"
					);
			if($this->isPluginActive('woocommerce/woocommerce.php')){
				array_push($list, "\/cart", "\/checkout", "\/receipt", "\/confirmation", "\/product");
			}

			if(preg_match("/".implode("|", $list)."/i", $_SERVER["REQUEST_URI"])){
				return true;
			}

			return false;
		}

		public function exclude_page(){
			$preg_match_rule = "";
			$request_url = trim($_SERVER["REQUEST_URI"], "/");

			if($json_data = get_option("WpFastestCacheExclude")){
				$std = json_decode($json_data);

				foreach($std as $key => $value){
					if(isset($value->prefix) && $value->prefix){
						$value->content = trim($value->content);
						$value->content = trim($value->content, "/");

						if($value->prefix == "exact"){
							if(strtolower($value->content) == strtolower($request_url)){
								return true;	
							}
						}else{
							if($value->prefix == "startwith"){
								$preg_match_rule = "^".preg_quote($value->content, "/");
							}else if($value->prefix == "contain"){
								$preg_match_rule = preg_quote($value->content, "/");
							}

							if(preg_match("/".$preg_match_rule."/i", $request_url)){
								return true;
							}
						}
					}
				}

			}
			return false;
		}

		public function is_xml($buffer){
			if(preg_match("/\<\?xml/i", $buffer)){
				return true;
			}
			return false;
		}

		public function callback($buffer){
			$buffer = $this->checkShortCode($buffer);

			if(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == "POST"){
				return $buffer;
			}else if(preg_match("/Mediapartners-Google/i", $_SERVER['HTTP_USER_AGENT'])){
				return $buffer;
			}else if($this->exclude_page()){
				return $buffer."<!-- Wp Fastest Cache: Exclude Page -->";
			}else if($this->is_xml($buffer)){
				return $buffer."<!-- Wp Fastest Cache: XML Content -->";
			}else if (is_user_logged_in() || $this->isCommenter()){
				return $buffer;
			} else if(preg_match("/json/i", $_SERVER["HTTP_ACCEPT"])){
				return $buffer;
			}else if($this->checkWoocommerceSession()){
				if($this->checkHtml($buffer)){
					return $buffer;
				}else{
					return $buffer."<!-- \$_COOKIE['wp_woocommerce_session'] has been set -->";
				}
			}else if(defined('DONOTCACHEPAGE') && $this->isPluginActive('wordfence/wordfence.php')){ // for Wordfence: not to cache 503 pages
				return $buffer."<!-- DONOTCACHEPAGE is defined as TRUE -->";
			}else if($this->isPasswordProtected($buffer)){
				return $buffer."<!-- Password protected content has been detected -->";
			}else if($this->isWpLogin($buffer)){
				return $buffer."<!-- wp-login.php -->";
			}else if($this->hasContactForm7WithCaptcha($buffer)){
				return $buffer."<!-- This page was not cached because ContactForm7's captcha -->";
			}else if(is_404()){
				return $buffer;
			}else if($this->ignored()){
				return $buffer;
			}else if($this->blockCache === true){
				return $buffer."<!-- wpfcNOT has been detected -->";
			}else if(isset($_GET["preview"])){
				return $buffer."<!-- not cached -->";
			}else if(preg_match("/\?/", $_SERVER["REQUEST_URI"]) && !preg_match("/\/\?fdx\_switcher\=true/", $_SERVER["REQUEST_URI"])){ // for WP Mobile Edition
				return $buffer;
			}else if($this->checkHtml($buffer)){
				return $buffer."<!-- html is corrupted -->";
			}else{				
				if($this->isMobile()){
					if(class_exists("WpFcMobileCache") && isset($this->options->wpFastestCacheMobileTheme)){
						
						// wptouch: ipad is accepted as a desktop so no need to create cache if user agent is ipad 
						// https://wordpress.org/support/topic/plugin-wptouch-wptouch-wont-display-mobile-version-on-ipad?replies=12
						if($this->isPluginActive('wptouch/wptouch.php')){
							if(preg_match("/ipad/i", $_SERVER['HTTP_USER_AGENT'])){
								return $buffer."<!-- ipad user -->";
							}
						}

						$wpfc_mobile = new WpFcMobileCache();
						$cachFilePath = $this->getWpContentDir()."/cache/".$wpfc_mobile->get_folder_name()."".$_SERVER["REQUEST_URI"];
					}else{
						return $buffer."<!-- mobile user -->";
					}
				}else{
					$cachFilePath = $this->getWpContentDir()."/cache/all".$_SERVER["REQUEST_URI"];
				}

				//to show cache version of home page via php if htaccess rewrite rule does not work
				if($_SERVER["REQUEST_URI"] == "/"){
					if(file_exists($cachFilePath."index.html")){
						if($content = @file_get_contents($cachFilePath."index.html")){
							return $content."<!-- via php -->";
						}
					}
				}

				$content = $buffer;

				if(isset($this->options->wpFastestCacheCombineCss) && isset($this->options->wpFastestCacheMinifyCss)){
					require_once "css-utilities.php";
					$css = new CssUtilities($this, $content);
					$content = $css->combineCss($this, true);
					//to minify css files which are NOT "media='all'"
					$content = $css->minifyCss($this, true);
					$this->err = $css->getError();
				}else if(isset($this->options->wpFastestCacheCombineCss)){
					require_once "css-utilities.php";
					$css = new CssUtilities($this, $content);
					$content = $css->combineCss($this, false);
				}else if(isset($this->options->wpFastestCacheMinifyCss)){
					require_once "css-utilities.php";
					$css = new CssUtilities($this, $content);
					$content = $css->minifyCss($this, false);
					$this->err = $css->getError();
				}

				if(isset($this->options->wpFastestCacheCombineJs) || isset($this->options->wpFastestCacheMinifyJs) || isset($this->options->wpFastestCacheCombineJsPowerFul)){
					require_once "js-utilities.php";
				}

				if(isset($this->options->wpFastestCacheCombineJs)){
					preg_match("/<head(.*?)<\/head>/si", $content, $head);

					if(isset($this->options->wpFastestCacheMinifyJs) && $this->options->wpFastestCacheMinifyJs){
						$js = new JsUtilities($this, $head[1], true);
					}else{
						$js = new JsUtilities($this, $head[1]);
					}

					$tmp_head = $js->combine_js();

					$content = str_replace($head[1], $tmp_head, $content);
				}

				if(class_exists("WpFastestCachePowerfulHtml")){
					$powerful_html = new WpFastestCachePowerfulHtml();
					$powerful_html->set_html($content);

					if(isset($this->options->wpFastestCacheCombineJsPowerFul) && method_exists("WpFastestCachePowerfulHtml", "combine_js_in_footer")){
						if(isset($this->options->wpFastestCacheMinifyJs) && $this->options->wpFastestCacheMinifyJs){
							$content = $powerful_html->combine_js_in_footer($this, true);
						}else{
							$content = $powerful_html->combine_js_in_footer($this);
						}
					}
					
					if(isset($this->options->wpFastestCacheRemoveComments)){
						$content = $powerful_html->remove_head_comments();
					}

					if(isset($this->options->wpFastestCacheMinifyHtmlPowerFul)){
						$content = $powerful_html->minify_html();
					}

					if(isset($this->options->wpFastestCacheMinifyJs) && method_exists("WpFastestCachePowerfulHtml", "minify_js_in_body")){
						$content = $powerful_html->minify_js_in_body($this);
					}
				}

				if($this->err){
					return $buffer."<!-- ".$this->err." -->";
				}else{
					$content = $this->cacheDate($content);
					$content = $this->minify($content);
					if($this->cdn){
						$content = preg_replace_callback("/[\'\"][^\'\"]+".preg_quote($this->cdn->originurl, "/")."[^\'\"]+[\'\"]/i", array($this, 'cdn_replace_urls'), $content);

						// url()
						$content = preg_replace_callback("/url\([^\)]+\)/i", array($this, 'cdn_replace_urls'), $content);
					}

					if(isset($this->options->wpFastestCacheDeferCss) && method_exists("WpFastestCachePowerfulHtml", "defer_css")){
						$content = $powerful_html->defer_css($content);
					}

					
					$content = str_replace("<!--WPFC_FOOTER_START-->", "", $content);

					$this->createFolder($cachFilePath, $content);
					return $buffer."<!-- need to refresh to see cached version -->";
				}
			}
		}

		public function cdn_replace_urls($matches){
			if(preg_match("/".preg_quote($this->cdn->originurl, "/")."/", $matches[0])){
				$extension = $this->get_extension($matches[0]);
				if($extension){
					if(preg_match("/".$extension."/i", $this->cdn->file_types)){
						$matches[0] = preg_replace("/(http(s?)\:)?\/\/(www\.)?".preg_quote($this->cdn->originurl, "/")."/i", "//".$this->cdn->cdnurl, $matches[0]);
					}
				}
			}
			return $matches[0];
		}

		public function get_extension($url){
			$url = str_replace(array("'",'"',")"), "", $url);
			$file_name = preg_replace("/\?.*/", "", basename($url));
			return $file_name ? substr(strrchr($file_name,'.'),1) : "";
		}

		public function minify($content){
			return isset($this->options->wpFastestCacheMinifyHtml) ? preg_replace("/^\s+/m", "", ((string) $content)) : $content;
		}

		public function checkHtml($buffer){
			if(preg_match('/<html[^\>]*>/si', $buffer) && preg_match('/<body[^\>]*>/si', $buffer)){
				return false;
			}
			// if(strlen($buffer) > 10){
			// 	return false;
			// }

			return true;
		}

		public function cacheDate($buffer){
			if($this->isMobile() && class_exists("WpFcMobileCache")){
				return $buffer."<!-- Mobile: WP Fastest Cache file was created in ".$this->creationTime()." seconds, on ".date("d-m-y G:i:s")." ".$_SERVER['HTTP_USER_AGENT']."-->";
			}else{
				return $buffer."<!-- WP Fastest Cache file was created in ".$this->creationTime()." seconds, on ".date("d-m-y G:i:s")." ".$_SERVER['HTTP_USER_AGENT']."-->";
			}
		}

		public function creationTime(){
			return microtime(true) - $this->startTime;
		}

		public function isCommenter(){
			$commenter = wp_get_current_commenter();
			return isset($commenter["comment_author_email"]) && $commenter["comment_author_email"] ? true : false;
		}
		public function isPasswordProtected($buffer){

			if(preg_match("/action\=[\'\"].+postpass.*[\'\"]/", $buffer)){
				return true;
			}

			return false;


			// if(count($_COOKIE) > 0){
			// 	if(preg_match("/wp-postpass/", implode(" ",array_keys($_COOKIE)))){
			// 		return true;
			// 	}

			// }
			// return false;
		}

		public function createFolder($cachFilePath, $buffer, $extension = "html", $prefix = ""){
			$create = false;
			
			if($buffer && strlen($buffer) > 100 && $extension == "html"){
				$create = true;
			}

			if(($extension == "css" || $extension == "js") && $buffer && strlen($buffer) > 5){
				$create = true;
				$buffer = trim($buffer);
				if($extension == "js"){
					if(substr($buffer, -1) != ";"){
						$buffer .= ";";
					}
				}
			}

			$cachFilePath = urldecode($cachFilePath);

			if($create){
				if (!is_user_logged_in() && !$this->isCommenter()){
					if(!is_dir($cachFilePath)){
						if(is_writable($this->getWpContentDir()) || ((is_dir($this->getWpContentDir()."/cache")) && (is_writable($this->getWpContentDir()."/cache")))){
							if (@mkdir($cachFilePath, 0755, true)){

								file_put_contents($cachFilePath."/".$prefix."index.".$extension, $buffer);
								
								if(class_exists("WpFastestCacheStatics")){

									if(preg_match("/wpfc\-mobile\-cache/", $cachFilePath)){
										$extension = "mobile";
									}
									
					   				$cache_statics = new WpFastestCacheStatics($extension, strlen($buffer));
					   				$cache_statics->update_db();
				   				}

							}else{
							}
						}else{

						}
					}else{
						if(file_exists($cachFilePath."/".$prefix."index.".$extension)){

						}else{

							file_put_contents($cachFilePath."/".$prefix."index.".$extension, $buffer);
							
							if(class_exists("WpFastestCacheStatics")){
								
								if(preg_match("/wpfc\-mobile\-cache/", $cachFilePath)){
									$extension = "mobile";
								}

				   				$cache_statics = new WpFastestCacheStatics($extension, strlen($buffer));
				   				$cache_statics->update_db();
			   				}
						}
					}
				}
			}elseif($extension == "html"){
				$this->err = "Buffer is empty so the cache cannot be created";
			}
		}

		public function replaceLink($search, $replace, $content){
			$href = "";

			if(stripos($search, "<link") === false){
				$href = $search;
			}else{
				preg_match("/.+href=[\"\'](.+)[\"\'].+/", $search, $out);
			}

			if(count($out) > 0){
				$content = preg_replace("/<link[^>]+".preg_quote($out[1], "/")."[^>]+>/", $replace, $content);
			}

			return $content;
		}

		public function isMobile(){
			if(preg_match("/.*".$this->getMobileUserAgents().".*/i", $_SERVER['HTTP_USER_AGENT'])){
				return true;
			}else{
				return false;
			}
		}
		public function isPluginActive( $plugin ) {
			return in_array( $plugin, (array) get_option( 'active_plugins', array() ) ) || $this->isPluginActiveForNetwork( $plugin );
		}
		public function isPluginActiveForNetwork( $plugin ) {
			if ( !is_multisite() )
				return false;

			$plugins = get_site_option( 'active_sitewide_plugins');
			if ( isset($plugins[$plugin]) )
				return true;

			return false;
		}

		public function checkWoocommerceSession(){
			foreach($_COOKIE as $key => $value){
			  if(preg_match("/^wp\_woocommerce\_session/", $key)){
			  	return true;
			  }
			}

			return false;
		}

		public function isWpLogin($buffer){
			// if(preg_match("/<form[^\>]+loginform[^\>]+>((?:(?!<\/form).)+)user_login((?:(?!<\/form).)+)user_pass((?:(?!<\/form).)+)<\/form>/si", $buffer)){
			// 	return true;
			// }
			if($GLOBALS["pagenow"] == "wp-login.php"){
				return true;
			}

			return false;
		}

		public function hasContactForm7WithCaptcha($buffer){
			if(is_single() || is_page()){
				if(preg_match("/<input[^\>]+_wpcf7_captcha[^\>]+>/i", $buffer)){
					return true;
				}
			}
			
			return false;
		}
	}
?>