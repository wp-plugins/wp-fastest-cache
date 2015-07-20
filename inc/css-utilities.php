<?php
	class CssUtilities{
		private $html = "";
		private $cssLinks = array();
		private $cssLinksExcept = "";
		private $url = "";
		private $err = "";
		private $wpfc;

		public function __construct($wpfc, $html){
			//$this->html = preg_replace("/\s+/", " ", ((string) $html));
			$this->wpfc = $wpfc;
			$this->html = $html;

			$ini = 0;

			if(function_exists("ini_set") && function_exists("ini_get")){
				$ini = ini_get("pcre.recursion_limit");
				ini_set("pcre.recursion_limit", "2777");
			}

			$this->setCssLinksExcept();

			$this->inlineToLink($wpfc);
			$this->setCssLinks();
			$this->setCssLinksExcept();

			if($ini){
				ini_set("pcre.recursion_limit", $ini);
			}
		}

		public function inlineToLink($wpfc){
			preg_match("/<head(.*?)<\/head>/si", $this->html, $head);
			//preg_match_all("/<style([^><]*)>([^<]+)<\/style>/is",$head[1],$out);


			$data = $head[1];
			$style_list = array();
			$style_start_index = false;
			$style_middle_index = false;

			for($i = 0; $i < strlen( $data ); $i++) {
				if(isset($data[$i-5])){
				    if(substr($data, $i-5, 6) == "<style"){
				    	$style_start_index = $i-5;
					}
				}

				if($style_start_index && !$style_middle_index){
					if($data[$i] == ">"){
						$style_middle_index = $i;
					}
				}

				if(isset($data[$i-7])){
					if($style_start_index){
						if(substr($data, $i-7, 8) == "</style>"){
							array_push($style_list, array("start" => $style_start_index, "middle" => $style_middle_index, "end" => $i));
							$style_start_index = false;
							$style_middle_index = false;
						}
					}
				}
			}

			if(!empty($style_list)){
				foreach (array_reverse($style_list) as $key => $value) {
					$inline_style_data = substr($data, $value["middle"]+1, ($value["end"] - $value["middle"] + 1 - 9));
					$inline_style_prefix = substr($data, $value["start"]+6, ($value["middle"] - $value["start"] + 1 - 7));
					
					$cachFilePath = WPFC_WP_CONTENT_DIR."/cache/wpfc-minified/".md5($inline_style_data);
					$cssLink = content_url()."/cache/wpfc-minified/".md5($inline_style_data);

					if($inline_style_data && (strpos($this->getCssLinksExcept(), $inline_style_data) === false)){
						$inline_style_data = preg_replace("/<!--((?:(?!-->).)+)-->/si", '', $inline_style_data);

						if(!is_dir($cachFilePath)){
							$prefix = time();
							$wpfc->createFolder($cachFilePath, $inline_style_data, "css", $prefix);
						}

						if($cssFiles = @scandir($cachFilePath, 1)){
							$cssLink = str_replace(array("http://", "https://"), "//", $cssLink);
							$link_tag = "<link rel='stylesheet' href='".$cssLink."/".$cssFiles[0]."' wpfc-inline='true' ".$inline_style_prefix." />";

							// $data = substr_replace($data, " -->\n".$link_tag."\n", $value["end"]+1, 0);
							// $data = substr_replace($data, "<!-- ", $value["start"], 0);

							$data = substr_replace($data, $link_tag, $value["start"], ($value["end"] - $value["start"] + 1));

						}
					}
				}

				$this->html = str_replace($head[1], $data, $this->html);
			}
















			// if(count($out) > 0){

			// 	$countStyle = array_count_values($out[2]);

			// 	$i = 0;

			// 	$out[2] = array_unique($out[2]);

			// 	foreach ($out[2] as $key => $value) {

			// 		$value = trim($value);

			// 		// to prevent inline to external if the style is used in the javascript
			// 		if(in_array($value[0], array(";","'",'"'))){
			// 			continue;
			// 		}


			// 		$cachFilePath = WPFC_WP_CONTENT_DIR."/cache/wpfc-minified/".md5($value);
			// 		$cssLink = content_url()."/cache/wpfc-minified/".md5($value);

			// 		preg_match("/media=[\"\']([^\"\']+)[\"\']/", $out[1][$i], $tmpMedia);
			// 		$media = (isset($tmpMedia[1]) && $tmpMedia[1]) ? $tmpMedia[1] : "all";

			// 		if(strpos($this->getCssLinksExcept(), $out[0][$i]) === false){
			// 			if(!is_dir($cachFilePath)){
			// 				$prefix = time();
			// 				$wpfc->createFolder($cachFilePath, $value, "css", $prefix);
			// 			}

			// 			if($cssFiles = @scandir($cachFilePath, 1)){
			// 				if($countStyle[$value] == 1){
			// 					$link = "<!-- <style".$out[1][$i].">".$value."</style> -->"."\n<link rel='stylesheet' href='".$cssLink."/".$cssFiles[0]."' type='text/css' media='".$media."' />";
			// 					if($tmpHtml = @preg_replace("/<style[^><]*>\s*".preg_quote($value, "/")."\s*<\/style>/", $link, $this->html)){
			// 						if($this->_process($value)){
			// 							$this->html = $tmpHtml;
			// 						}
			// 					}else{
			// 						$this->err = "inline css is too large. it is a mistake for optimization. save it as a file and call in the html.".$value;
			// 					}
			// 				}else{
			// 					$link = "<!-- <style".$out[1][$i].">".$value."</style> -->"."\n<link rel='stylesheet' href='".$cssLink."/".$cssFiles[0]."' type='text/css' media='".$media."' />";
			// 					if($tmpHtml = @preg_replace("/<style[^><]*>\s*".preg_quote($value, "/")."\s*<\/style>/", $link, $this->html)){
			// 						if($this->_process($value)){
			// 							$this->html = $tmpHtml;
			// 						}
			// 					}else{
			// 						$this->err = "inline css is too large. it is a mistake for optimization. save it as a file and call in the html.".$value;
			// 					}
			// 					$countStyle[$value] = $countStyle[$value] - 1;
			// 				}
			// 			}
			// 		}

			// 		$i++;

			// 	}
			// }
		}

		public function minify($url, $minify = true){
			$this->url = $url;

			$cachFilePath = WPFC_WP_CONTENT_DIR."/cache/wpfc-minified/".md5($url);
			$cssLink = content_url()."/cache/wpfc-minified/".md5($url);

			if(is_dir($cachFilePath)){
				return array("cachFilePath" => $cachFilePath, "cssContent" => "", "url" => $cssLink, "realUrl" => $url);
			}else{
				if($css = $this->file_get_contents_curl($url."?v=".time())){
					
					if($minify){
						$cssContent = $this->_process($css);
					}else{
						$cssContent = $css;
					}

					if($cssContent){
						$cssContent = $this->fixPathsInCssContent($cssContent);
						return array("cachFilePath" => $cachFilePath, "cssContent" => $cssContent, "url" => $cssLink, "realUrl" => $url);
					}
				}
			}
			return false;
		}

		public function file_get_contents_curl($url) {

			if(preg_match("/^\/[^\/]/", $url)){
				$url = home_url().$url;
			}

			$url = preg_replace("/^\/\//", "http://", $url);
			
			// $ch = curl_init();
		 
			// curl_setopt($ch, CURLOPT_HEADER, 0);
			// curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Set curl to return the data instead of printing it to the browser.
			// curl_setopt($ch, CURLOPT_URL, $url);
		 
			// $data = curl_exec($ch);
			// curl_close($ch);

			// if(preg_match("/<\/\s*html\s*>\s*$/i", $data)){
			// 	return false;
			// }else{
			// 	return $data;	
			// }

			$response = wp_remote_get($url, array('timeout' => 10 ) );

			if ( !$response || is_wp_error( $response ) ) {
				return false;
			}else{
				if(wp_remote_retrieve_response_code($response) == 200){
					$data = wp_remote_retrieve_body( $response );

					if(preg_match("/<\/\s*html\s*>\s*$/i", $data)){
						return false;
					}else{
						return $data;	
					}
				}
			}
		}

		public function fixPathsInCssContent($css){
			$css = preg_replace("/@import\s+[\"\']([^\;\"\'\)]+)[\"\'];/", "@import url($1);", $css);
			return preg_replace_callback("/url\(([^\)]*)\)/", array($this, 'newImgPath'), $css);
			//return preg_replace_callback("/url\((?P<path>[^\)]*)\)/", array($this, 'newImgPath'), $css);
		}

		public function fixRules($css){
			$css = $this->fixImportRules($css);
			$css = preg_replace_callback('/@import\s+url\(([^\)]+)\);/i', array($this, 'fix_import_rules'), $css);
			$css = $this->fixCharset($css);
			//$css = preg_replace("/@media/i","\n@media",$css);
			return $css;
		}

		public function fixImportRules($css){
			preg_match_all('/@import\s+url\(([^\)]+)\);/i', $css, $imports);

			if(count($imports[0]) > 0){
				for ($i = count($imports[0])-1; $i >= 0; $i--) {
					
					if(!$this->is_internal_css($imports[1][$i])){
						$css = $imports[0][$i]."\n".$css;
					}
				}
			}
			return $css;
		}

		public function fix_import_rules($matches){
			if($this->is_internal_css($matches[1])){
				if($cssContent = $this->file_get_contents_curl($matches[1]."?v=".time())){
					$tmp_url = $this->url;
					$this->url = $matches[1];
					$cssContent = $this->fixPathsInCssContent($cssContent);
					$cssContent = $this->fixRules($cssContent); 
					$this->url = $tmp_url;
					return "/* ".$matches[0]." */"."\n".$cssContent;
				}
			}

			return $matches[0];
		}

		public function is_internal_css($url){
			$http_host = trim($_SERVER["HTTP_HOST"], "www.");

			$url = trim($url);
			$url = trim($url, "'");
			$url = trim($url, '"');
			$url = trim($url, 'https://');
			$url = trim($url, 'http://');
			$url = trim($url, '//');
			$url = trim($url, 'www.');

			if($url && preg_match("/".$http_host."/i", $url)){
				return true;
			}

			return false;
		}

		public function fixCharset($css){
			preg_match_all('/@charset[^\;]+\;/i', $css, $charsets);
			if(count($charsets[0]) > 0){
				$css = preg_replace('/@charset[^\;]+\;/i', "/* @charset is moved to the top */", $css);
				foreach($charsets[0] as $charset){
					$css = $charset."\n".$css;
				}
			}
			return $css;
		}

		public function newImgPath($matches){

			if(preg_match("/data\:image\/svg\+xml/", $matches[1])){
				$matches[1] = $matches[1];
			}else{
				$matches[1] = str_replace(array("\"","'"), "", $matches[1]);
				if(!$matches[1]){
					$matches[1] = "";
				}else if(preg_match("/^(\/\/|http|\/\/fonts|data:image|data:application)/", $matches[1])){
					if(preg_match("/fonts\.googleapis\.com/", $matches[1])){ // for safari browser
						$matches[1] = '"'.$matches[1].'"';
					}else{
						$matches[1] = $matches[1];
					}
				}else if(preg_match("/^\//", $matches[1])){
					$homeUrl = str_replace(array("http:", "https:"), "", home_url());
					$matches[1] = $homeUrl.$matches[1];
				}else if(preg_match("/^(?P<up>(\.\.\/)+)(?P<name>.+)/", $matches[1], $out)){
					$count = strlen($out["up"])/3;
					$url = dirname($this->url);
					for($i = 1; $i <= $count; $i++){
						$url = substr($url, 0, strrpos($url, "/"));
					}
					$url = str_replace(array("http:", "https:"), "", $url);
					$matches[1] = $url."/".$out["name"];
				}else{
					$url = str_replace(array("http:", "https:"), "", dirname($this->url));
					$matches[1] = $url."/".$matches[1];
				}
			}




			return "url(".$matches[1].")";
		}

		public function setCssLinks(){
			preg_match("/<head(.*?)<\/head>/si", $this->html, $head);
			preg_match_all("/<link[^<>]*rel=[\"\']stylesheet[\"\'][^<>]*>/", $head[1], $this->cssLinks);
			$this->cssLinks = array_unique($this->cssLinks[0]);
		}

		public function setCssLinksExcept(){
			preg_match("/<head(.*?)<\/head>/si", $this->html, $head);

			preg_match_all("/<\!--\s*\[\s*if[^>]+>(.*?)<\!\s*\[\s*endif\s*\]\s*-->/si", $head[1], $cssLinksInIf);

			preg_match_all("/<\!--(?!\[if)(.*?)(?!<\!\s*\[\s*endif\s*\]\s*)-->/si", $head[1], $cssLinksCommentOut);

			preg_match_all("/<script((?:(?!<\/script).)+)<\/style>((?:(?!<\/script).)+)<\/script>/si", $head[1], $cssLinksInScripts);
			
			preg_match_all("/<noscript((?:(?!<\/noscript|<noscript).)+)<\/noscript>/si", $head[1], $cssLinksInNoscripts);

			$this->cssLinksExcept = implode(" ", array_merge($cssLinksInIf[0], $cssLinksCommentOut[0], $cssLinksInScripts[0], $cssLinksInNoscripts[0]));
		}

		public function getCssLinks(){
			return $this->cssLinks;
		}

		public function getCssLinksExcept(){
			return $this->cssLinksExcept;
		}

		public function checkInternal($link){
			$httpHost = str_replace("www.", "", $_SERVER["HTTP_HOST"]); 
			if(preg_match("/href=[\"\'](.*?)[\"\']/", $link, $href)){

				if(preg_match("/^\/[^\/]/", $href[1])){
					return $href[1];
				}

				if(@strpos($href[1], $httpHost)){
					return $href[1];
				}
			}
			return false;
		}

		public function minifyCss($wpfc, $exceptMediaAll){
			if(count($this->getCssLinks()) > 0){
				foreach ($this->getCssLinks() as $key => $value) {
					if($href = $this->checkInternal($value)){

						if($exceptMediaAll && preg_match("/media=[\'\"]all[\'\"]/", $value)){
							continue;
						}

						$minifiedCss = $this->minify($href);

						if($minifiedCss){
							if(isset($wpfc->options->wpFastestCacheMinifyCssPowerFul)){
								$powerful_html = new WpFastestCachePowerfulHtml();
								$minifiedCss["cssContent"] = $powerful_html->minify_css($minifiedCss["cssContent"]);
							}

							if(preg_match("/wpfc\-inline/", $value)){
								//$prev["content"] = $this->fixRules($prev["content"]);
								$this->mergeCss($wpfc, $prev);
								$prev = array("content" => "", "value" => array(), "name" => "");

								$attributes = preg_replace("/.+wpfc\-inline\=\'true\'([^\>]+)\/>/", "$1", $value);
								$attributes = $attributes ? " ".trim($attributes) : "";
								$this->html = $wpfc->replaceLink($value, "<style".$attributes.">".$minifiedCss["cssContent"]."</style>", $this->html);

								continue;
							}

							$minifiedCss["cssContent"] = $this->fixRules($minifiedCss["cssContent"]);

							if(isset($this->wpfc->options->wpFastestCacheMinifyCss) && $this->wpfc->options->wpFastestCacheMinifyCss){
								$minifiedCss["cssContent"] = $this->_process($minifiedCss["cssContent"]);
							}

							if(isset($this->wpfc->options->wpFastestCacheMinifyCssPowerFul) && $this->wpfc->options->wpFastestCacheMinifyCssPowerFul){
								$powerful_html = new WpFastestCachePowerfulHtml();
								$minifiedCss["cssContent"] = $powerful_html->minify_css($minifiedCss["cssContent"]);
							}


							if(!is_dir($minifiedCss["cachFilePath"])){
								$prefix = time();
								$wpfc->createFolder($minifiedCss["cachFilePath"], $minifiedCss["cssContent"], "css", $prefix);
							}

							if($cssFiles = @scandir($minifiedCss["cachFilePath"], 1)){
								$prefixLink = str_replace(array("http:", "https:"), "", $minifiedCss["url"]);
								
								//$this->html = str_replace($href, $prefixLink."/".$cssFiles[0], $this->html);

								$newLink = str_replace($href, $prefixLink."/".$cssFiles[0], $value);
								$this->html = $wpfc->replaceLink($value, $newLink, $this->html);
							}
						}
					}
				}
			}

			return $this->html;
		}

		public function combineCss($wpfc, $minify = false){
			if(count($this->getCssLinks()) > 0){
				$prev = array("content" => "", "value" => array(), "name" => "");
				foreach ($this->getCssLinks() as $key => $value) {
					if($href = $this->checkInternal($value)){
						
						if(preg_match("/\.ttf/", $href)){
							continue;
						}

						if(strpos($this->getCssLinksExcept(), $href) === false && (preg_match("/media=[\'\"]all[\'\"]/", $value) || !preg_match("/media=/", $value))){

							$minifiedCss = $this->minify($href, $minify);

							if($minifiedCss){

								if($minify && isset($wpfc->options->wpFastestCacheMinifyCssPowerFul)){
									$powerful_html = new WpFastestCachePowerfulHtml();
									$minifiedCss["cssContent"] = $powerful_html->minify_css($minifiedCss["cssContent"]);
								}

								if(preg_match("/wpfc\-inline/", $value)){
									//$prev["content"] = $this->fixRules($prev["content"]);
									$this->mergeCss($wpfc, $prev);
									$prev = array("content" => "", "value" => array(), "name" => "");

									$attributes = preg_replace("/.+wpfc\-inline\=\'true\'([^\>]+)\/>/", "$1", $value);
									$attributes = $attributes ? " ".trim($attributes) : "";
									$this->html = $wpfc->replaceLink($value, "<style".$attributes.">".$minifiedCss["cssContent"]."</style>", $this->html);

									continue;
								}



								if(!is_dir($minifiedCss["cachFilePath"])){
									$prefix = time();
									$wpfc->createFolder($minifiedCss["cachFilePath"], $minifiedCss["cssContent"], "css", $prefix);
								}

								if($cssFiles = @scandir($minifiedCss["cachFilePath"], 1)){
									if($cssContent = $this->file_get_contents_curl($minifiedCss["url"]."/".$cssFiles[0]."?v=".time())){
										$prev["name"] .= $minifiedCss["realUrl"];
										$prev["content"] .= $cssContent."\n";
										array_push($prev["value"], $value);
									}
								}
							}
						}else{
							//$prev["content"] = $this->fixRules($prev["content"]);
							$this->mergeCss($wpfc, $prev);
							$prev = array("content" => "", "value" => array(), "name" => "");
						}
					}else{
						//$prev["content"] = $this->fixRules($prev["content"]);
						$this->mergeCss($wpfc, $prev);
						$prev = array("content" => "", "value" => array(), "name" => "");
					}
				}
				//$prev["content"] = $this->fixRules($prev["content"]);
				$this->mergeCss($wpfc, $prev);
			}

			$this->html = preg_replace("/(<!-- )+/","<!-- ", $this->html);
			$this->html = preg_replace("/( -->)+/"," -->", $this->html);

			return $this->html;
		}

		public function mergeCss($wpfc, $prev){
			if(count($prev["value"]) > 0){
				$name = "";
				foreach ($prev["value"] as $prevKey => $prevValue) {
					if($prevKey == count($prev["value"]) - 1){
						$name = md5($prev["name"]);
						$cachFilePath = WPFC_WP_CONTENT_DIR."/cache/wpfc-minified/".$name;

						$prev["content"] = $this->fixRules($prev["content"]);

						if(isset($this->wpfc->options->wpFastestCacheMinifyCss) && $this->wpfc->options->wpFastestCacheMinifyCss){
							$prev["content"] = $this->_process($prev["content"]);
						}

						if(isset($this->wpfc->options->wpFastestCacheMinifyCssPowerFul) && $this->wpfc->options->wpFastestCacheMinifyCssPowerFul){
							$powerful_html = new WpFastestCachePowerfulHtml();
							$prev["content"] = $powerful_html->minify_css($prev["content"]);
						}

						/* 
							The css files are saved in the previous function.
							If only one css file is in the array, there is need to save again.
						*/
						if(count($prev["value"]) == 1){
							if($cssFiles = @scandir($cachFilePath, 1)){
								file_put_contents($cachFilePath."/".$cssFiles[0], $prev["content"]);
							}
						}else{
							if(!is_dir($cachFilePath)){
								$wpfc->createFolder($cachFilePath, $prev["content"], "css", time());
							}
						}

						if($cssFiles = @scandir($cachFilePath, 1)){
							$prefixLink = str_replace(array("http:", "https:"), "", content_url());
							$newLink = "<link rel='stylesheet' href='".$prefixLink."/cache/wpfc-minified/".$name."/".$cssFiles[0]."' type='text/css' media='all' />";
							$this->html = $wpfc->replaceLink($prevValue, "<!-- ".$prevValue." -->"."\n".$newLink, $this->html);
						}
					}else{
						$name .= $prevValue;
						$this->html = $wpfc->replaceLink($prevValue, "<!-- ".$prevValue." -->", $this->html);
					}
				}
			}
		}

		public function getError(){
			return $this->err;
		}

	    protected $_inHack = false;
	 
	    protected function _process($css){
	        $css = preg_replace("/^\s+/m", "", ((string) $css));
	        $css = preg_replace_callback('@\\s*/\\*([\\s\\S]*?)\\*/\\s*@'
	            ,array($this, '_commentCB'), $css);

	        //to remove empty chars from url()
			$css = preg_replace("/url\((\s+)([^\)]+)(\s+)\)/", "url($2)", $css);

	        return trim($css);
	    }
	    
	    protected function _commentCB($m){
	        $hasSurroundingWs = (trim($m[0]) !== $m[1]);
	        $m = $m[1]; 
	        // $m is the comment content w/o the surrounding tokens, 
	        // but the return value will replace the entire comment.
	        if ($m === 'keep') {
	            return '/**/';
	        }
	        if ($m === '" "') {
	            // component of http://tantek.com/CSS/Examples/midpass.html
	            return '/*" "*/';
	        }
	        if (preg_match('@";\\}\\s*\\}/\\*\\s+@', $m)) {
	            // component of http://tantek.com/CSS/Examples/midpass.html
	            return '/*";}}/* */';
	        }
	        if ($this->_inHack) {
	            // inversion: feeding only to one browser
	            if (preg_match('@
	                    ^/               # comment started like /*/
	                    \\s*
	                    (\\S[\\s\\S]+?)  # has at least some non-ws content
	                    \\s*
	                    /\\*             # ends like /*/ or /**/
	                @x', $m, $n)) {
	                // end hack mode after this comment, but preserve the hack and comment content
	                $this->_inHack = false;
	                return "/*/{$n[1]}/**/";
	            }
	        }
	        if (substr($m, -1) === '\\') { // comment ends like \*/
	            // begin hack mode and preserve hack
	            $this->_inHack = true;
	            return '/*\\*/';
	        }
	        if ($m !== '' && $m[0] === '/') { // comment looks like /*/ foo */
	            // begin hack mode and preserve hack
	            $this->_inHack = true;
	            return '/*/*/';
	        }
	        if ($this->_inHack) {
	            // a regular comment ends hack mode but should be preserved
	            $this->_inHack = false;
	            return '/**/';
	        }
	        // Issue 107: if there's any surrounding whitespace, it may be important, so 
	        // replace the comment with a single space
	        return $hasSurroundingWs // remove all other comments
	            ? ' '
	            : '';
	    }
	}
?>