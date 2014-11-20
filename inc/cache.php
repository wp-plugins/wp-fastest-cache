<?php
	class WpFastestCacheCreateCache extends WpFastestCache{
		private $options = array();
		private $startTime;
		private $blockCache = false;
		private $err = "";

		public function __construct(){
			$this->options = $this->getOptions();

			$this->checkActivePlugins();
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
				ob_start(array($this, "callback"));
			}
		}

		public function ignored(){
			$list = array(
						"wp\-comments\-post\.php",
						"sitemap\.xml",
						"wp\-login\.php",
						"robots\.txt",
						"wp\-cron\.php",
						"wp\-content",
						"wp\-admin",
						"wp\-includes",
						"index\.php" 
					);

			if(preg_match("/\/".implode("|", $list)."/", $_SERVER["REQUEST_URI"])){
				return true;
			}

			return false;
		}

		public function callback($buffer){
			$buffer = $this->checkShortCode($buffer);

			if (is_user_logged_in() || $this->isCommenter()){
				return $buffer;
			} else if(preg_match("/json/i", $_SERVER["HTTP_ACCEPT"])){
				return $buffer;
			}else if($this->checkWoocommerceSession()){
				return $buffer."<!-- \$_COOKIE['wp_woocommerce_session'] has been set -->";
			}else if(defined('DONOTCACHEPAGE') && $this->isPluginActive('wordfence/wordfence.php')){ // for Wordfence: not to cache 503 pages
				return $buffer."<!-- DONOTCACHEPAGE is defined as TRUE -->";
			}else if($this->isPasswordProtected($buffer)){
				return $buffer."<!-- Password protected content has been detected -->";
			}else if($this->isWpLogin($buffer)){
				return $buffer;
			}else if($this->hasContactForm7WithCaptcha($buffer)){
				return $buffer."<!-- This page was not cached because ContactForm7's captcha -->";
			}else if($this->isMobile() && !class_exists("WpFcMobileCache")){
				return $buffer;
			}else if(is_404()){
				return $buffer;
			}else if($this->ignored()){
				return $buffer;
			}else if($this->blockCache === true){
				return $buffer."<!-- wpfcNOT has been detected -->";
			}else if(isset($_GET["preview"])){
				return $buffer."<!-- not cached -->";
			}else if(preg_match("/\?/", $_SERVER["REQUEST_URI"])){
				return $buffer;
			}else if($this->checkHtml($buffer)){
				return $buffer."<!-- html is corrupted -->";
			}else{
				if($this->isMobile() && class_exists("WpFcMobileCache")){
					$wpfc_mobile = new WpFcMobileCache();
					$cachFilePath = $this->getWpContentDir()."/cache/".$wpfc_mobile->get_folder_name()."".$_SERVER["REQUEST_URI"];
				}else{
					$cachFilePath = $this->getWpContentDir()."/cache/all".$_SERVER["REQUEST_URI"];
				}

				$content = $this->cacheDate($buffer);

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

				if(isset($this->options->wpFastestCacheCombineJs)){
					$content = $this->combineJs($content, false);
				}

				if(class_exists("WpFastestCachePowerfulHtml")){
					$powerful_html = new WpFastestCachePowerfulHtml();
					$powerful_html->set_html($content);
					
					if(isset($this->options->wpFastestCacheRemoveComments)){
						$content = $powerful_html->remove_head_comments();
					}

					if(isset($this->options->wpFastestCacheMinifyHtmlPowerFul)){
						$content = $powerful_html->minify_html();
					}
				}

				if($this->err){
					return $buffer."<!-- ".$this->err." -->";
				}else{
					$content = $this->minify($content);
					$this->createFolder($cachFilePath, $content);
					return $buffer."<!-- need to refresh to see cached version -->";
				}
			}
		}

		public function minify($content){
			return isset($this->options->wpFastestCacheMinifyHtml) ? preg_replace("/^\s+/m", "", ((string) $content)) : $content;
		}

		public function checkHtml($buffer){
			if(preg_match('/<\/html>/si', $buffer) && preg_match('/<\/body>/si', $buffer)){
				return false;
			}

			return true;
		}

		public function cacheDate($buffer){
			if($this->isMobile() && class_exists("WpFcMobileCache")){
				return $buffer."<!-- Mobile: WP Fastest Cache file was created in ".$this->creationTime()." seconds, on ".date("d-m-y G:i:s")." -->";
			}else{
				return $buffer."<!-- WP Fastest Cache file was created in ".$this->creationTime()." seconds, on ".date("d-m-y G:i:s")." -->";
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
			if($buffer && strlen($buffer) > 100){
				$create = true;
			}elseif(($extension == "css" || $extension == "js") && $buffer && strlen($buffer) > 5){
				$create = true;
			}

			$cachFilePath = urldecode($cachFilePath);

			if($create){
				if (!is_user_logged_in() && !$this->isCommenter()){
					if(!is_dir($cachFilePath)){
						if(is_writable($this->getWpContentDir()) || ((is_dir($this->getWpContentDir()."/cache")) && (is_writable($this->getWpContentDir()."/cache")))){
							if (@mkdir($cachFilePath, 0755, true)){
								file_put_contents($cachFilePath."/".$prefix."index.".$extension, $buffer);
							}else{
							}
						}else{

						}
					}else{
						if(file_exists($cachFilePath."/".$prefix."index.".$extension)){

						}else{
							file_put_contents($cachFilePath."/".$prefix."index.".$extension, $buffer);
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

		public function combineJs($content, $minify = false){
			$minify = true;
			if(isset($this->options->wpFastestCacheCombineJs)){
				require_once "js-utilities.php";
				$js = new JsUtilities($this, $content);

				if(count($js->getJsLinks()) > 0){
					$prev = array("content" => "", "value" => array());
					foreach ($js->getJsLinks() as $key => $value) {
						if($href = $js->checkInternal($value)){
							if(strpos($js->getJsLinksExcept(), $href) === false){
								if(!preg_match("/<script[^>]+json[^>]+>.+/", $value)){
									$minifiedJs = $js->minify($href, $minify);

									if($minifiedJs){
										if(!is_dir($minifiedJs["cachFilePath"])){

											if(isset($this->options->wpFastestCacheCombineJsPowerFul)){
												$powerful_html = new WpFastestCachePowerfulHtml();
												$minifiedJs["jsContent"] = $powerful_html->minify_js($minifiedJs["jsContent"]);
											}


											$prefix = time();
											$this->createFolder($minifiedJs["cachFilePath"], $minifiedJs["jsContent"], "js", $prefix);
										}

										if($jsFiles = @scandir($minifiedJs["cachFilePath"], 1)){
											if($jsContent = $js->file_get_contents_curl($minifiedJs["url"]."/".$jsFiles[0]."?v=".time())){
												$prev["content"] .= $jsContent;
												array_push($prev["value"], $value);
											}
										}
									}
								}else{
									$content = $js->mergeJs($prev, $this);
									$prev = array("content" => "", "value" => array());
								}
							}else{
								$content = $js->mergeJs($prev, $this);
								$prev = array("content" => "", "value" => array());
							}
						}else{
							$content = $js->mergeJs($prev, $this);
							$prev = array("content" => "", "value" => array());
						}
					}
					$content = $js->mergeJs($prev, $this);
				}
			}

			$content = preg_replace("/(<!-- )+/","<!-- ", $content);
			$content = preg_replace("/( -->)+/"," -->", $content);

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
			if(preg_match("/<form[^\>]+loginform[^\>]+>((?:(?!<\/form).)+)user_login((?:(?!<\/form).)+)user_pass((?:(?!<\/form).)+)<\/form>/si", $buffer)){
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