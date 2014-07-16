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
			preg_match("/\[wpfcNOT\]/", $content, $wpfcNOT);
			if(count($wpfcNOT) > 0){
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
			$ignored = array("robots.txt", "wp-login.php", "wp-cron.php", "wp-content", "wp-admin", "wp-includes");
			foreach ($ignored as $key => $value) {
				if (strpos($_SERVER["REQUEST_URI"], $value) === false) {
				}else{
					return true;
				}
			}
			return false;
		}

		public function callback($buffer){
			$buffer = $this->checkShortCode($buffer);

			if (is_user_logged_in() || $this->isCommenter()){
				return $buffer;
			}else if(defined('DONOTCACHEPAGE')){ // for Wordfence: not to cache 503 pages
				return $buffer."<!-- DONOTCACHEPAGE is defined as TRUE -->";
			}else if($this->isPasswordProtected()){
				return $buffer;
			}else if($this->isMobile()){
				return $buffer;
			}else if(is_404()){
				return $buffer;
			}else if($this->ignored()){
				return $buffer;
			}else if($this->blockCache === true){
				return $buffer."<!-- wpfcNOT has been detected -->";
			}else if(isset($_GET["preview"])){
				return $buffer."<!-- not cached -->";
			}else if($this->checkHtml($buffer)){
				return $buffer;
			}else if(preg_match("/\?/", $_SERVER["REQUEST_URI"])){
				return $buffer;
			}else{
				$cachFilePath = $this->getWpContentDir()."/cache/all".$_SERVER["REQUEST_URI"];

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
			preg_match('/<\/html>/', $buffer, $htmlTag);
			preg_match('/<\/body>/', $buffer, $bodyTag);
			if(count($htmlTag) > 0 && count($bodyTag) > 0){
				return 0;
			}else{
				return 1;
			}
		}

		public function cacheDate($buffer){
			return $buffer."<!-- WP Fastest Cache file was created in ".$this->creationTime()." seconds, on ".date("d-m-y G:i:s")." -->";
		}

		public function creationTime(){
			return microtime(true) - $this->startTime;
		}

		public function isCommenter(){
			$commenter = wp_get_current_commenter();
			return isset($commenter["comment_author_email"]) && $commenter["comment_author_email"] ? true : false;
		}
		public function isPasswordProtected(){
			if(count($_COOKIE) > 0){
				if(preg_match("/wp-postpass/", implode(" ",array_keys($_COOKIE)))){
					return true;
				}

			}
			return false;
		}

		public function createFolder($cachFilePath, $buffer, $extension = "html", $prefix = ""){
			$create = false;
			if($buffer && strlen($buffer) > 100){
				$create = true;
			}elseif(($extension == "css" || $extension == "js") && $buffer && strlen($buffer) > 5){
				$create = true;
			}

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
				$js = new JsUtilities($content);

				if(count($js->getJsLinks()) > 0){
					$prev = array("content" => "", "value" => array());
					foreach ($js->getJsLinks() as $key => $value) {
						if($href = $js->checkInternal($value)){
							if(strpos($js->getJsLinksExcept(), $href) === false){
								$minifiedJs = $js->minify($href, $minify);

								if($minifiedJs){
									if(!is_dir($minifiedJs["cachFilePath"])){
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
					}
					$content = $js->mergeJs($prev, $this);
				}
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
	}
?>