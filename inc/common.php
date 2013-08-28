<?php
	class WpFastestCache{
		private $menuTitle = "WP Fastest Cache";
		private $pageTitle = "WP Fastest Cache Settings";
		private $adminPageUrl = "wp-fastest-cache/admin/index.php";
		private $wpContentDir = "";
		private $systemMessage = "";
		private $options = array();

		public function __construct(){
			$this->iconUrl = plugins_url("wp-fastest-cache/images/icon.png");
			$this->setWpContentDir();
			$this->setOptions();
			$this->optionsPageRequest();
			$this->detectNewPost();
		}

		public function optionsPageRequest(){
			// for wp-admin/admin.php?page=WpFastestCacheOptions
			if(!empty($_POST)){
				if(isset($_POST["wpFastestCachePage"])){
					if($_POST["wpFastestCachePage"] == "options"){
						$this->saveOption();
					}else if($_POST["wpFastestCachePage"] == "deleteCache"){
						$this->deleteCache();
					}
				}
			}
		}

		public function setWpContentDir(){
			$this->wpContentDir = ABSPATH."wp-content";
		}

		public function addMenuPage(){
			add_action('admin_menu', array($this, 'register_my_custom_menu_page'));
		}

		public function register_my_custom_menu_page(){
			if(function_exists('add_menu_page')){ 
				add_menu_page($this->pageTitle, $this->menuTitle, 'manage_options', "WpFastestCacheOptions", array($this, 'optionsPage'), $this->iconUrl, 99 );
				wp_register_style('wp-fastest-cache', plugins_url("wp-fastest-cache/css/style.css") );
				wp_enqueue_style('wp-fastest-cache');
			}
		}

		public function optionsPage(){
			$wpFastestCacheStatus = "";
			$wpFastestCacheNewPost = "";
			$wpFastestCacheStatus = isset($this->options->wpFastestCacheStatus) ? 'checked="checked"' : "";
			$wpFastestCacheNewPost = isset($this->options->wpFastestCacheNewPost) ? 'checked="checked"' : "";

			?>
			<div class="wrap">
				<h2>WP Fastest Cache Options</h2>
				<?php if($this->systemMessage){ ?>
					<div class="updated fade below-h2" id="message"><p><?php echo $this->systemMessage; ?></p></div>
				<? } ?>
				<div class="tabGroup">
					<?php
						$tabs = array(array("id"=>"wpfc-options","title"=>"Settings"),
									  array("id"=>"wpfc-deleteCache","title"=>"Delete Cache"),
									  array("id"=>"wpfc-cacheTimeout","title"=>"Cache Timeout"));

						foreach ($tabs as $key => $value){
							$checked = "";
							if(!isset($_POST["wpFastestCachePage"]) && $value["id"] == "wpfc-options"){
								$checked = ' checked="checked" ';
							}else if((isset($_POST["wpFastestCachePage"])) && ("wpfc-".$_POST["wpFastestCachePage"] == $value["id"])){
								$checked = ' checked="checked" ';
							}
							echo '<input '.$checked.' type="radio" id="'.$value["id"].'" name="tabGroup1">'."\n";
							echo '<label for="'.$value["id"].'">'.$value["title"].'</label>'."\n";
						}
					?>
				    <br>
				    <div class="tab1">
						<form method="post" name="wp_manager">
							<input type="hidden" value="options" name="wpFastestCachePage">
							<div class="questionCon">
								<div class="question">Cache System:</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheStatus; ?> id="wpFastestCacheStatus" name="wpFastestCacheStatus"><label for="wpFastestCacheStatus"> Enable</label></div>
							</div>
							<div class="questionCon">
								<div class="question">New Post</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheNewPost; ?> id="wpFastestCacheNewPost" name="wpFastestCacheNewPost"><label for="wpFastestCacheNewPost"> Clear all cache files when a post or page is published</label></div>
							</div>
							<div class="questionCon">
								<div class="inputCon"><label style="cursor:text;padding-left:48px;"> The Pages which have <b>&lt;!-- &lt;wpfcNOT&gt; --&gt;</b> will not be cached</label></div>
							</div>
							<div class="questionCon qsubmit">
								<div class="submit"><input type="submit" value="Submit" class="button-primary"></div>
							</div>
						</form>
				    </div>
				    <div class="tab2">
				    	<form method="post" name="wp_manager">
				    		<input type="hidden" value="deleteCache" name="wpFastestCachePage">
				    		<div class="questionCon">
				    			<div style="padding-left:11px;">
				    			You can delete all cache files<br>
				    			Target folder <b><?php echo $this->wpContentDir; ?>/cache/all</b>
				    			</div>
				    		</div>
				    		<div class="questionCon qsubmit">
				    			<div class="submit"><input type="submit" value="Delete Now" class="button-primary"></div>
				    		</div>
				   		</form>
				    </div>
				    <div class="tab3">
				    	<form method="post" name="wp_manager">
				    		<input type="hidden" value="cacheTimeout" name="wpFastestCachePage">
				    		<!-- You can set the Cache Timeout below. All cached files are deleted at the determinated time. 
				    		<div class="submit"><input type="submit" value="Set" class="button-primary"></div>
				    		-->
				    		<div class="questionCon">
				    			<div style="padding-left:11px;">
				    			It will active in the next version
				    			</div>
				    		</div>
				   		</form>
				    </div>
				</div>

			</div>
			<?php
		}

		public function detectNewPost(){
			if(isset($this->options->wpFastestCacheNewPost) && isset($this->options->wpFastestCacheStatus)){
				add_filter ('publish_post', array($this, 'deleteCache'));
				add_filter ('delete_post', array($this, 'deleteCache'));
			}
		}

		public function deleteCache(){
			if(is_dir($this->wpContentDir."/cache/all")){
				$this->rm_folder_recursively($this->wpContentDir."/cache/all");
			}
			$this->systemMessage = "All cached files have been deleted";
		}

		public function rm_folder_recursively($dir) {
		    foreach(scandir($dir) as $file) {
		        if ('.' === $file || '..' === $file) continue;
		        if (is_dir("$dir/$file")) $this->rm_folder_recursively("$dir/$file");
		        else unlink("$dir/$file");
		    }
		    
		    rmdir($dir);
		    return true;
		}

		public function saveOption(){
			unset($_POST["wpFastestCachePage"]);
			$data = json_encode($_POST);
			//for optionsPage() $_POST is array and json_decode() converts to stdObj
			$this->options = json_decode($data);

			if(get_option("WpFastestCache")){
				update_option("WpFastestCache", $data);
			}else{
				add_option("WpFastestCache", $data, null, "yes");
			}
			$this->systemMessage = $this->modifyHtaccess($_POST);
		}

		public function setOptions(){
			if($data = get_option("WpFastestCache")){
				$this->options = json_decode($data);
			}
		}

		public function modifyHtaccess($post){
			if(isset($post["wpFastestCacheStatus"]) && $post["wpFastestCacheStatus"] == "on"){

				if(!is_file(ABSPATH.".htaccess")){
					return ".htacces was not found";
				}else if(is_writable(ABSPATH.".htaccess")){
					$htaccess = file_get_contents(ABSPATH.".htaccess");
					preg_match("/wp-content\/cache\/all/", $htaccess, $check);
					if(count($check) === 0){
						file_put_contents(ABSPATH.".htaccess", $this->getHtaccess().$htaccess);
					}else{
						//already changed
					}
				}else{
					return ".htacces is not writable";
				}
				return "Options have been saved";
			}else{
				//disable
				$this->deleteCache();
				return "Options have been saved";
			}
		}

		public function getHtaccess(){
			$data = "# BEGIN WpFastestCache"."\n".
					"<IfModule mod_rewrite.c>"."\n".
					"RewriteEngine On"."\n".
					"RewriteBase /"."\n".
					"RewriteCond %{REQUEST_METHOD} !POST"."\n".
					"RewriteCond %{QUERY_STRING} !.*=.*"."\n".
					"RewriteCond %{HTTP:Cookie} !^.*(comment_author_|wordpress_logged_in|wp-postpass_).*$"."\n".
					'RewriteCond %{HTTP:X-Wap-Profile} !^[a-z0-9\"]+ [NC]'."\n".
					'RewriteCond %{HTTP:Profile} !^[a-z0-9\"]+ [NC]'."\n".
					"RewriteCond %{DOCUMENT_ROOT}/".$this->getRewriteBase()."wp-content/cache/all/".$this->getRewriteBase()."$1/index.html -f"."\n".
					'RewriteRule ^(.*) "/'.$this->getRewriteBase().'wp-content/cache/all/'.$this->getRewriteBase().'$1/index.html" [L]'."\n".
					"</IfModule>"."\n".
					"# END WpFastestCache"."\n";
			return $data;
		}

		public function getRewriteBase(){
			$tmp = str_replace($_SERVER['DOCUMENT_ROOT']."/", "", ABSPATH);
			$tmp = str_replace("/", "", $tmp);
			$tmp = $tmp ? $tmp."/" : "";
			return $tmp;
		}

		public function createCache(){
			if(isset($this->options->wpFastestCacheStatus)){
				ob_start(array($this, "callback"));
			}
		}

		public function callback($buffer){
			preg_match("/<wpfcNOT>/", $buffer, $notCache);
			if($_SERVER["REQUEST_URI"] == "/robots.txt"){
				return $buffer;
			}else if(count($notCache) > 0){
				return $buffer."<!-- not cached -->";
			}else if($_GET["preview"]){
				return $buffer."<!-- not cached -->";
			}else{
				$cachFilePath = $this->wpContentDir."/cache/all".$_SERVER["REQUEST_URI"];
				$content = $buffer."".$this->cacheDate();
				$this->createFolder($cachFilePath, $content);

				return $buffer."<!-- WP Fastest Cache not cached version -->";
			}
		}

		public function cacheDate(){
			return "<!-- WP Fastest Cache ".date("d-m-y G:i:s", time()+3600*3)." -->";
		}

		public function createFolder($cachFilePath, $buffer){
			if($buffer && strlen($buffer) > 100){
				if (!is_user_logged_in()){
					if(!is_dir($cachFilePath)){
						if(is_writable($this->wpContentDir) || ((is_dir($this->wpContentDir."/cache")) && (is_writable($this->wpContentDir."/cache")))){
							if (!mkdir($cachFilePath, 0755, true)){

							}else{
								file_put_contents($cachFilePath."/index.html", $buffer);
							}
						}else{

						}
					}else{
						file_put_contents($cachFilePath."/index.html", $buffer);
					}
				}
			}
		}

	}

?>