<?php
		
		function minify($content){
			return isset($this->options->wpFastestCacheMinifyHtml) ? preg_replace("/^\s+/m", "", ((string) $content)) : $content;
		}

		
		function getJsLinksExcept(){
			return $this->jsLinksExcept;
		}

		
		function newImgPath($matches){
			$matches[1] = str_replace(array("\"","'"), "", $matches[1]);
			if(!$matches[1]){
				$matches[1] = "";
			}else if(preg_match("/^(\/\/|http|\/\/fonts|data:image|data:application)/", $matches[1])){
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

		
		function isPluginActive( $plugin ) {
			return in_array( $plugin, (array) get_option( 'active_plugins', array() ) ) || $this->isPluginActiveForNetwork( $plugin );
		}
		
		function addMenuPage(){
			add_action('admin_menu', array($this, 'register_my_custom_menu_page'));
		}

		
		function getRewriteBase($sub = ""){
			if($sub && $this->is_subdirectory_install()){
				$trimedProtocol = preg_replace("/http:\/\/|https:\/\//", "", trim(home_url(), "/"));
				$path = strstr($trimedProtocol, '/');

				if($path){
					return trim($path, "/")."/";
				}else{
					return "";
				}
			}
			
			$url = rtrim(site_url(), "/");
			preg_match("/https?:\/\/[^\/]+(.*)/", $url, $out);

			if(isset($out[1]) && $out[1]){
				return trim($out[1], "/")."/";
			}else{
				return "";
			}
		}

		
		function isCommenter(){
			$commenter = wp_get_current_commenter();
			return isset($commenter["comment_author_email"]) && $commenter["comment_author_email"] ? true : false;
		}
		
		function setJsLinks(){
			preg_match("/<head(.*?)<\/head>/si", $this->html, $head);

			preg_match_all("/<script[^<>]+src=[\"\']([^\"\']+)[\"\'][^<>]*><\/script>/", $head[1], $this->jsLinks);

			$this->jsLinks = $this->jsLinks[0];
		}

		
		function mergeCss($wpfc, $prev){
			if(count($prev["value"]) > 0){
				foreach ($prev["value"] as $prevKey => $prevValue) {
					if($prevKey == count($prev["value"]) - 1){
						$name = md5($prev["name"]);
						$cachFilePath = ABSPATH."wp-content"."/cache/wpfc-minified/".$name;

						if(!is_dir($cachFilePath)){
							$wpfc->createFolder($cachFilePath, $prev["content"], "css", time());
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

		
?>