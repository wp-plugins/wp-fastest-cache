<?php
	class JsUtilities{
		private $html = "";
		private $jsLinks = array();
		private $jsLinksExcept = "";
		private $url = "";

		public function __construct($html){
			$this->html = preg_replace("/\s+/", " ", ((string) $html));
			$this->setJsLinks();
			$this->setJsLinksExcept();
		}

		public function setJsLinks(){
			preg_match("/<head(.*?)<\/head>/si", $this->html, $head);

			preg_match_all("/<script[^<>]+src=[\"\']([^\"\']+)[\"\'][^<>]*><\/script>/", $head[1], $this->jsLinks);

			$this->jsLinks = $this->jsLinks[0];
		}

		public function setJsLinksExcept(){
			preg_match("/<head(.*?)<\/head>/si", $this->html, $head);

			preg_match_all("/<\!--\s*\[\s*if[^>]+>(.*?)<\!\s*\[\s*endif\s*\]\s*-->/si", $head[1], $jsLinksInIf);

			preg_match_all("/<\!--(?!\[if)(.*?)(?!<\!\s*\[\s*endif\s*\]\s*)-->/si", $head[1], $jsLinksCommentOut);
			
			$this->jsLinksExcept = implode(" ", array_merge($jsLinksInIf[0], $jsLinksCommentOut[0]));
		}

		public function getJsLinksExcept(){
			return $this->jsLinksExcept;
		}

		public function getJsLinks(){
			return $this->jsLinks;
		}

		public function minify($url, $minify = true){
			$this->url = $url;

			$cachFilePath = ABSPATH."wp-content"."/cache/wpfc-minified/".md5($url);
			$jsLink = content_url()."/cache/wpfc-minified/".md5($url);

			if(is_dir($cachFilePath)){
				return array("cachFilePath" => $cachFilePath, "jsContent" => "", "url" => $jsLink);
			}else{
				if($js = $this->file_get_contents_curl($url."?v=".time())){
					if($minify){
						$js = preg_replace("/^\s+/m", "", ((string) $js));
						$js = preg_replace_callback('@\\s*/\\*([\\s\\S]*?)\\*/\\s*@', array($this, '_commentCB'), $js);
					}

					$js = "\n /* source --> ".$url." */ \n".$js;

					return array("cachFilePath" => $cachFilePath, "jsContent" => $js, "url" => $jsLink);
				}
			}
			return false;
		}

		public function checkInternal($link){
			$contentUrl = str_replace(array("http://www.", "http://", "https://www.", "https://"), "", content_url());

			$httpHost = str_replace("www.", "", $_SERVER["HTTP_HOST"]); 
			if(preg_match("/src=[\"\'](.*?)[\"\']/", $link, $src)){
				if(@strpos($src[1], $httpHost)){
					return $src[1];
				}
			}
			return false;
		}

		public function replaceLink($search, $replace, $content){
			$href = "";

			if(stripos($search, "<script") === false){
				$href = $search;
			}else{
				preg_match("/.+src=[\"\'](.+)[\"\'].+/", $search, $out);
			}

			if(count($out) > 0){
				$content = preg_replace("/<script[^>]+".preg_quote($out[1], "/")."[^>]+><\/script>/", $replace, $content);
			}

			return $content;
		}

		public function _commentCB($m){
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

		public function file_get_contents_curl($url) {
			$ch = curl_init();
		 
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //Set curl to return the data instead of printing it to the browser.
			curl_setopt($ch, CURLOPT_URL, $url);
		 
			$data = curl_exec($ch);
			curl_close($ch);
		 
			return $data;
		}
	}
?>