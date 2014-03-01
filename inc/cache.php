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
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

			//for WP-Polls
			if(is_plugin_active('wp-polls/wp-polls.php')){ 
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
				$content = $this->minifyCss($content);

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
			if($buffer && strlen($buffer) > 100){
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


		public function isMobile(){
			if(preg_match("/.*".$this->getMobileUserAgents().".*/i", $_SERVER['HTTP_USER_AGENT'])){
				return true;
			}else{
				return false;
			}
		}
	}
?>