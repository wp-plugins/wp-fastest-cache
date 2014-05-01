<?php
	class CssUtilities{
		private $html = "";
		private $cssLinks = array();
		private $cssLinksExcept = "";
		private $url = "";

		public function __construct($html){
			$this->html = preg_replace("/\s+/", " ", ((string) $html));
			$this->setCssLinks();
			$this->setCssLinksExcept();
		}

		public function minify($url, $minify = true){
			$this->url = $url;
			//preg_match("/^.*?wp-content\/(themes|plugins)\/(.*?)$/", $url, $name);

			$cachFilePath = ABSPATH."wp-content"."/cache/wpfc-minified/".md5($url);
			$cssLink = content_url()."/cache/wpfc-minified/".md5($url);

			if(is_dir($cachFilePath)){
				return array("cachFilePath" => $cachFilePath, "cssContent" => "", "url" => $cssLink);
			}else{
				if($css = $this->file_get_contents_curl($url."?v=".time())){
					if($minify){
						$cssContent = $this->_process($css);
						$cssContent = $this->fixPathsInCssContent($cssContent);
					}else{
						$cssContent = $css;
						$cssContent = $this->fixPathsInCssContent($cssContent);
					}

					return array("cachFilePath" => $cachFilePath, "cssContent" => $cssContent, "url" => $cssLink);
				}
			}
			return false;
		}

		public function file_get_contents_curl($url) {
			$ch = curl_init();
		 
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Set curl to return the data instead of printing it to the browser.
			curl_setopt($ch, CURLOPT_URL, $url);
		 
			$data = curl_exec($ch);
			curl_close($ch);
		 
			return $data;
		}

		public function fixPathsInCssContent($css){
			$css = preg_replace("/@import\s+[\"\']([^\;\"\'\)]+)[\"\'];/", "@import url($1);", $css);
			return preg_replace_callback("/url\(([^\)]*)\)/", array($this, 'newImgPath'), $css);
			//return preg_replace_callback("/url\((?P<path>[^\)]*)\)/", array($this, 'newImgPath'), $css);
		}

		public function fixRules($css){
			$css = $this->fixImportRules($css);
			$css = $this->fixCharset($css);
			return $css;
		}

		public function fixImportRules($css){
			preg_match_all('/@import\s+url\([^\)]+\);/i', $css, $imports);

			if(count($imports[0]) > 0){
				$css = preg_replace('/@import\s+url\([^\)]+\);/i', "/* @import is moved to the top */", $css);
				for ($i = count($imports[0])-1; $i >= 0; $i--) {
					$css = $imports[0][$i]."\n".$css;
				}
			}
			return $css;
		}

		public function fixCharset($css){
			preg_match_all('/@charset.+;/i', $css, $charsets);
			if(count($charsets[0]) > 0){
				$css = preg_replace('/@charset.+;/i', "/* @charset is moved to the top */", $css);
				foreach($charsets[0] as $charset){
					$css = $charset."\n".$css;
				}
			}
			return $css;
		}

		public function newImgPath($matches){
			$matches[1] = str_replace(array("\"","'"), "", $matches[1]);
			if(!$matches[1]){
				$matches[1] = "";
			}else if(preg_match("/^(\/\/|http|\/\/fonts|data:image)/", $matches[1])){
				$matches[1] = $matches[1];
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

			return "url(".$matches[1].")";
		}

		public function setCssLinks(){
			preg_match("/<head(.*?)<\/head>/si", $this->html, $head);
			preg_match_all("/<link[^<>]*rel=[\"\']stylesheet[\"\'][^<>]*>/", $head[1], $this->cssLinks);
			$this->cssLinks = $this->cssLinks[0];
		}

		public function setCssLinksExcept(){
			preg_match("/<head(.*?)<\/head>/si", $this->html, $head);

			preg_match_all("/<\!--\s*\[\s*if[^>]+>(.*?)<\!\s*\[\s*endif\s*\]\s*-->/si", $head[1], $cssLinksInIf);

			preg_match_all("/<\!--(?!\[if)(.*?)(?!<\!\s*\[\s*endif\s*\]\s*)-->/si", $head[1], $cssLinksCommentOut);
			
			$this->cssLinksExcept = implode(" ", array_merge($cssLinksInIf[0], $cssLinksCommentOut[0]));
		}

		public function getCssLinks(){
			return $this->cssLinks;
		}

		public function getCssLinksExcept(){
			return $this->cssLinksExcept;
		}

		public function checkInternal($link){
			$contentUrl = str_replace(array("http://www.", "http://", "https://www.", "https://"), "", content_url());

			$httpHost = str_replace("www.", "", $_SERVER["HTTP_HOST"]); 
			if(preg_match("/href=[\"\'](.*?)[\"\']/", $link, $href)){
				if(@strpos($href[1], $httpHost)){
					return $href[1];
					// if(strpos($href[1], $contentUrl."/themes") || strpos($href[1], $contentUrl."/plugins")){
					// }
				}
			}
			return false;
		}

		public function minifyCss($wpfc, $content){
			if(count($this->getCssLinks()) > 0){
				foreach ($this->getCssLinks() as $key => $value) {
					if($href = $this->checkInternal($value)){
						$minifiedCss = $this->minify($href);

						if($minifiedCss){
							if(!is_dir($minifiedCss["cachFilePath"])){
								$prefix = time();
								$wpfc->createFolder($minifiedCss["cachFilePath"], $minifiedCss["cssContent"], "css", $prefix);
							}

							if($cssFiles = @scandir($minifiedCss["cachFilePath"], 1)){
								$prefixLink = str_replace(array("http:", "https:"), "", $minifiedCss["url"]);
								$content = str_replace($href, $prefixLink."/".$cssFiles[0], $content);	
							}
						}
					}
				}
			}

			return $content;
		}

		public function combineCss($wpfc, $content, $minify = false){
			if(count($this->getCssLinks()) > 0){
				$prev = array("content" => "", "value" => array());
				foreach ($this->getCssLinks() as $key => $value) {
					if($href = $this->checkInternal($value)){
						if(strpos($this->getCssLinksExcept(), $href) === false){

							$minifiedCss = $this->minify($href, $minify);

							if($minifiedCss){
								if(!is_dir($minifiedCss["cachFilePath"])){
									$prefix = time();
									$wpfc->createFolder($minifiedCss["cachFilePath"], $minifiedCss["cssContent"], "css", $prefix);
								}

								if($cssFiles = @scandir($minifiedCss["cachFilePath"], 1)){
									if($cssContent = $this->file_get_contents_curl($minifiedCss["url"]."/".$cssFiles[0]."?v=".time())){
										$prev["content"] .= $cssContent;
										array_push($prev["value"], $value);
									}
								}
							}
						}else{
							$prev["content"] = $this->fixRules($prev["content"]);
							$content = $this->mergeCss($wpfc, $prev, $content);
							$prev = array("content" => "", "value" => array());
						}
					}else{
						$prev["content"] = $this->fixRules($prev["content"]);
						$content = $this->mergeCss($wpfc, $prev, $content);
						$prev = array("content" => "", "value" => array());
					}
				}
				$prev["content"] = $this->fixRules($prev["content"]);
				$content = $this->mergeCss($wpfc, $prev, $content);
			}

			return $content;
		}

		public function mergeCss($wpfc, $prev, $content){
			if(count($prev["value"]) > 0){
				$name = "";
				foreach ($prev["value"] as $prevKey => $prevValue) {
					if($prevKey == count($prev["value"]) - 1){
						$name = md5($name);
						$cachFilePath = ABSPATH."wp-content"."/cache/wpfc-minified/".$name;

						if(!is_dir($cachFilePath)){
							$wpfc->createFolder($cachFilePath, $prev["content"], "css", time());
						}

						if($cssFiles = @scandir($cachFilePath, 1)){
							$prefixLink = str_replace(array("http:", "https:"), "", content_url());
							$newLink = "<link rel='stylesheet' href='".$prefixLink."/cache/wpfc-minified/".$name."/".$cssFiles[0]."' type='text/css' media='all' />";
							$content = $wpfc->replaceLink($prevValue, "<!-- ".$prevValue." -->"."\n".$newLink, $content);
						}
					}else{
						$name .= $prevValue;
						$content = $wpfc->replaceLink($prevValue, "<!-- ".$prevValue." -->", $content);
					}
				}
			}
			return $content;
		}


	    protected $_inHack = false;
	   
	    /**
	     * Constructor
	     * 
	     * @param array $options (currently ignored)
	     * 
	     * @return null
	     */
	    // private function __construct($options) {
	    //     $this->_options = $options;
	    // }
	    
	    /**
	     * Minify a CSS string
	     * 
	     * @param string $css
	     * 
	     * @return string
	     */
	    protected function _process($css)
	    {
	        //$css = str_replace("\r\n", "\n", $css);

	        $css = preg_replace("/^\s+/m", "", ((string) $css));
	        
	        // preserve empty comment after '>'
	        // http://www.webdevout.net/css-hacks#in_css-selectors
	        // $css = preg_replace('@>/\\*\\s*\\*/@', '>/*keep*/', $css);
	        
	        // // preserve empty comment between property and value
	        // // http://css-discuss.incutio.com/?page=BoxModelHack
	        // $css = preg_replace('@/\\*\\s*\\*/\\s*:@', '/*keep*/:', $css);
	        // $css = preg_replace('@:\\s*/\\*\\s*\\*/@', ':/*keep*/', $css);
	        
	        // apply callback to all valid comments (and strip out surrounding ws
	        $css = preg_replace_callback('@\\s*/\\*([\\s\\S]*?)\\*/\\s*@'
	            ,array($this, '_commentCB'), $css);

	        // // remove ws around { } and last semicolon in declaration block
	        // $css = preg_replace('/\\s*{\\s*/', '{', $css);
	        // $css = preg_replace('/;?\\s*}\\s*/', '}', $css);
	        
	        // // remove ws surrounding semicolons
	        // //causes break css down
	        // // $css = preg_replace('/\\s*;\\s*/', ';', $css);
	        
	        // // remove ws around urls
	        // $css = preg_replace('/
	        //         url\\(      # url(
	        //         \\s*
	        //         ([^\\)]+?)  # 1 = the URL (really just a bunch of non right parenthesis)
	        //         \\s*
	        //         \\)         # )
	        //     /x', 'url($1)', $css);
	        
	        // // remove ws between rules and colons
	        // $css = preg_replace('/
	        //         \\s*
	        //         ([{;])              # 1 = beginning of block or rule separator 
	        //         \\s*
	        //         ([\\*_]?[\\w\\-]+)  # 2 = property (and maybe IE filter)
	        //         \\s*
	        //         :
	        //         \\s*
	        //         (\\b|[#\'"])        # 3 = first character of a value
	        //     /x', '$1$2:$3', $css);
	        
	        // // remove ws in selectors
	        // $css = preg_replace_callback('/
	        //         (?:              # non-capture
	        //             \\s*
	        //             [^~>+,\\s]+  # selector part
	        //             \\s*
	        //             [,>+~]       # combinators
	        //         )+
	        //         \\s*
	        //         [^~>+,\\s]+      # selector part
	        //         {                # open declaration block
	        //     /x'
	        //     ,array($this, '_selectorsCB'), $css);
	        
	        // // minimize hex colors
	        // $css = preg_replace('/([^=])#([a-f\\d])\\2([a-f\\d])\\3([a-f\\d])\\4([\\s;\\}])/i'
	        //     , '$1#$2$3$4$5', $css);
	        
	        // // remove spaces between font families
	        // $css = preg_replace_callback('/font-family:([^;}]+)([;}])/'
	        //     ,array($this, '_fontFamilyCB'), $css);
	        
	        // $css = preg_replace('/@import\\s+url/', '@import url', $css);
	        
	        // // replace any ws involving newlines with a single newline
	        // $css = preg_replace('/[ \\t]*\\n+\\s*/', "\n", $css);
	        
	        // // separate common descendent selectors w/ newlines (to limit line lengths)
	        //$css = preg_replace('/([\\w#\\.\\*]+)\\s+([\\w#\\.\\*]+){/', "$1\n$2{", $css);
	        
	        // // Use newline after 1st numeric value (to limit line lengths).
	        // $css = preg_replace('/
	        //     ((?:padding|margin|border|outline):\\d+(?:px|em)?) # 1 = prop : 1st numeric value
	        //     \\s+
	        //     /x'
	        //     ,"$1\n", $css);
	        
	        // prevent triggering IE6 bug: http://www.crankygeek.com/ie6pebug/
	        //$css = preg_replace('/:first-l(etter|ine)\\{/', ':first-l$1 {', $css);
	            
	        return trim($css);
	    }
	    
	    /**
	     * Replace what looks like a set of selectors  
	     *
	     * @param array $m regex matches
	     * 
	     * @return string
	     */
	    protected function _selectorsCB($m)
	    {
	        // remove ws around the combinators
	        return preg_replace('/\\s*([,>+~])\\s*/', '$1', $m[0]);
	    }
	    
	    /**
	     * Process a comment and return a replacement
	     * 
	     * @param array $m regex matches
	     * 
	     * @return string
	     */
	    protected function _commentCB($m)
	    {
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
	    
	    /**
	     * Process a font-family listing and return a replacement
	     * 
	     * @param array $m regex matches
	     * 
	     * @return string   
	     */
	    protected function _fontFamilyCB($m)
	    {
	        $m[1] = preg_replace('/
	                \\s*
	                (
	                    "[^"]+"      # 1 = family in double qutoes
	                    |\'[^\']+\'  # or 1 = family in single quotes
	                    |[\\w\\-]+   # or 1 = unquoted family
	                )
	                \\s*
	            /x', '$1', $m[1]);
	        return 'font-family:' . $m[1] . $m[2];
	    }
	}
?>