<?php
	class WpFastestCacheCreateCache extends WpFastestCache{
		private $options = array();
		private $startTime;
		private $blockCache = false;

		public function __construct(){
			$this->options = $this->getOptions();

			$this->checkActivePlugins();
		}

		public function checkActivePlugins(){
			//include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

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
				if(is_single() || is_page()){
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

			if(defined('DONOTCACHEPAGE')){ // for Wordfence: not to cache 503 pages
				return $buffer;
			}else if($this->isMobile()){
				return $buffer;
			}else if(is_404()){
				return $buffer;
			}else if($this->ignored()){
				return $buffer;
			}else if($this->blockCache === true){
				return $buffer."<!-- not cached -->";
			}else if(isset($_GET["preview"])){
				return $buffer."<!-- not cached -->";
			}else if($this->checkHtml($buffer)){
				return $buffer;
			}else{
				$cachFilePath = $this->getWpContentDir()."/cache/all".$_SERVER["REQUEST_URI"];

				$content = $this->cacheDate($buffer);
				$content = $this->minify($content);

				if(isset($this->options->wpFastestCacheCombineCss) && isset($this->options->wpFastestCacheMinifyCss)){
					$content = $this->combineCss($content, true);
				}else if(isset($this->options->wpFastestCacheCombineCss)){
					$content = $this->combineCss($content, false);
				}else if(isset($this->options->wpFastestCacheMinifyCss)){
					$content = $this->minifyCss($content);
				}
				
				$this->createFolder($cachFilePath, $content);

				return $buffer;
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
			return isset($commenter["comment_author_email"]) && $commenter["comment_author_email"] ? false : true;
		}

		public function createFolder($cachFilePath, $buffer, $extension = "html", $prefix = ""){
			$create = false;
			if($buffer && strlen($buffer) > 100){
				$create = true;
			}elseif($extension == "css" && $buffer && strlen($buffer) > 5){
				$create = true;
			}

			if($create){
				if (!is_user_logged_in() && $this->isCommenter()){
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
			}
		}

		public function minifyCss($content){
			if(isset($this->options->wpFastestCacheMinifyCss)){
				require_once "css-utilities.php";
				$css = new CssUtilities($content);

				if(count($css->getCssLinks()) > 0){
					foreach ($css->getCssLinks() as $key => $value) {
						if($href = $css->checkInternal($value)){
							$minifiedCss = $css->minify($href);

							if($minifiedCss){
								if(!is_dir($minifiedCss["cachFilePath"])){
									$prefix = time();
									$this->createFolder($minifiedCss["cachFilePath"], $minifiedCss["cssContent"], "css", $prefix);
								}

								if($cssFiles = @scandir($minifiedCss["cachFilePath"], 1)){
									$content = str_replace($href, $minifiedCss["url"]."/".$cssFiles[0], $content);	
								}
							}
						}
					}
				}
			}
			return $content;
		}

		public function combineCss($content, $minify = false){
			if(isset($this->options->wpFastestCacheCombineCss)){
				require_once "css-utilities.php";
				$css = new CssUtilities($content);

				if(count($css->getCssLinks()) > 0){
					$prev = array("content" => "", "value" => array());
					foreach ($css->getCssLinks() as $key => $value) {
						if($href = $css->checkInternal($value)){
							if(strpos($css->getCssLinksExcept(), $href) === false){

								$minifiedCss = $css->minify($href, $minify);

								if($minifiedCss){
									if(!is_dir($minifiedCss["cachFilePath"])){
										$prefix = time();
										$this->createFolder($minifiedCss["cachFilePath"], $minifiedCss["cssContent"], "css", $prefix);
									}

									if($cssFiles = @scandir($minifiedCss["cachFilePath"], 1)){
										if($cssContent = $css->file_get_contents_curl($minifiedCss["url"]."/".$cssFiles[0]."?v=".time())){
											$prev["content"] .= $cssContent;
											array_push($prev["value"], $value);
										}
									}
								}
							}else{
								$prev["content"] = $css->fixRules($prev["content"]);
								$content = $this->mergeCss($prev, $content);
								$prev = array("content" => "", "value" => array());
							}
						}else{
							$prev["content"] = $css->fixRules($prev["content"]);
							$content = $this->mergeCss($prev, $content);
							$prev = array("content" => "", "value" => array());
						}
					}
					$prev["content"] = $css->fixRules($prev["content"]);
					$content = $this->mergeCss($prev, $content);
				}
			}
			return $content;
		}

		public function mergeCss($prev, $content){
			if(count($prev["value"]) > 0){
				$name = "";
				foreach ($prev["value"] as $prevKey => $prevValue) {
					if($prevKey == count($prev["value"]) - 1){
						$name = md5($name);
						$cachFilePath = ABSPATH."wp-content"."/cache/wpfc-minified/".$name;

						if(!is_dir($cachFilePath)){
							$this->createFolder($cachFilePath, $prev["content"], "css", time());
						}

						if($cssFiles = @scandir($cachFilePath, 1)){
							$newLink = "<link rel='stylesheet' href='".content_url()."/cache/wpfc-minified/".$name."/".$cssFiles[0]."' type='text/css' media='all' />";
							$content = $this->replaceLink($prevValue, $newLink, $content);
						}
					}else{
						$name .= $prevValue;
						$content = $this->replaceLink($prevValue, "<!-- removed -->", $content);
					}
				}
			}
			return $content;
		}

		public function replaceLink($search, $replace, $content){
			$href = "";

			if(stripos($search, "<link") === false){
				$href = $search;
			}else{
				preg_match("/.+href=[\"\'](.+)[\"\'].+/", $search, $out);
			}

			if(count($out) > 0){
				// $out[1] = str_replace("/", "\/", $out[1]);
				// $out[1] = str_replace("?", "\?", $out[1]);
				// $out[1] = str_replace(".", "\.", $out[1]);

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
	}
?>