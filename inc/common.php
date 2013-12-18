<?php
	class WpFastestCache{
		private $menuTitle = "WP Fastest Cache";
		private $pageTitle = "WP Fastest Cache Settings";
		private $slug = "wp_fastest_cache";
		private $adminPageUrl = "wp-fastest-cache/admin/index.php";
		private $wpContentDir = "";
		private $systemMessage = "";
		private $options = array();
		private $cronJobSettings;
		private $startTime;
		private $blockCache = false;

		public function __construct(){
			$this->setWpContentDir();
			$this->setOptions();
			$this->detectNewPost();
			$this->checkCronTime();
			if(is_admin()){
				$this->optionsPageRequest();
				$this->iconUrl = plugins_url("wp-fastest-cache/images/icon.png");
				$this->setCronJobSettings();
				$this->addButtonOnEditor();
				add_action('admin_enqueue_scripts', array($this, 'addJavaScript'));
			}
		}

		public function addButtonOnEditor(){
			add_action('admin_print_footer_scripts', array($this, 'addButtonOnQuicktagsEditor'));
			add_action('init', array($this, 'myplugin_buttonhooks'));
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

		public function myplugin_buttonhooks() {
		   // Only add hooks when the current user has permissions AND is in Rich Text editor mode
		   if ( ( current_user_can('edit_posts') || current_user_can('edit_pages') ) && get_user_option('rich_editing') ) {
		     add_filter("mce_external_plugins", array($this, "myplugin_register_tinymce_javascript"));
		     add_filter('mce_buttons', array($this, 'myplugin_register_buttons'));
		   }
		}
		// Load the TinyMCE plugin : editor_plugin.js (wp2.5)
		public function myplugin_register_tinymce_javascript($plugin_array) {
		   $plugin_array['wpfc'] = plugins_url('../js/button.js?v='.time(),__file__);
		   return $plugin_array;
		}

		public function myplugin_register_buttons($buttons) {
		   array_push($buttons, 'wpfc');
		   return $buttons;
		}

		public function addButtonOnQuicktagsEditor(){
			if (wp_script_is('quicktags')){ ?>
				<script type="text/javascript">
				    QTags.addButton('wpfc_not', 'wpfcNOT', '[wpfcNOT]', '', '', 'Block caching for this page');
			    </script>
		    <?php }
		}

		public function deactivate(){
			if(is_file(ABSPATH.".htaccess") && is_writable(ABSPATH.".htaccess")){
				$htaccess = file_get_contents(ABSPATH.".htaccess");
				$htaccess = preg_replace("/#\s?BEGIN\s?WpFastestCache.*?#\s?END\s?WpFastestCache/s", "", $htaccess);
				$htaccess = preg_replace("/#\s?BEGIN\s?GzipWpFastestCache.*?#\s?END\s?GzipWpFastestCache/s", "", $htaccess);
				file_put_contents(ABSPATH.".htaccess", $htaccess);
			}

			wp_clear_scheduled_hook("wp_fastest_cache");
			delete_option("WpFastestCache");
			$wpfc = new WpFastestCache();
			$wpfc->deleteCache();
		}

		public function optionsPageRequest(){
			if(!empty($_POST)){
				if(isset($_POST["wpFastestCachePage"])){
					if($_POST["wpFastestCachePage"] == "options"){
						$this->saveOption();
					}else if($_POST["wpFastestCachePage"] == "deleteCache"){
						$this->deleteCache();
					}else if($_POST["wpFastestCachePage"] == "cacheTimeout"){
						$this->addCacheTimeout();
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

		public function addJavaScript(){
			wp_enqueue_script( "language", plugins_url("wp-fastest-cache/js/language.js"), array(), time(), false);
			wp_enqueue_script( "info", plugins_url("wp-fastest-cache/js/info.js"), array(), time(), true);
		}

		public function register_my_custom_menu_page(){
			if(function_exists('add_menu_page')){ 
				add_menu_page($this->pageTitle, $this->menuTitle, 'manage_options', "WpFastestCacheOptions", array($this, 'optionsPage'), $this->iconUrl, 99 );
				wp_enqueue_style("wp-fastest-cache", plugins_url("wp-fastest-cache/css/style.css"), array(), time(), "all");
			}
		}

		public function optionsPage(){
			$wpFastestCacheStatus = "";
			$wpFastestCacheNewPost = "";
			$wpFastestCacheLanguage = "";
			$wpFastestCacheTimeOut = "";
			$wpFastestCacheStatus = isset($this->options->wpFastestCacheStatus) ? 'checked="checked"' : "";
			$wpFastestCacheNewPost = isset($this->options->wpFastestCacheNewPost) ? 'checked="checked"' : "";
			$wpFastestCacheMinifyHtml = isset($this->options->wpFastestCacheMinifyHtml) ? 'checked="checked"' : "";
			$wpFastestCacheMinifyCss = isset($this->options->wpFastestCacheMinifyCss) ? 'checked="checked"' : "";
			$wpFastestCacheGzip = isset($this->options->wpFastestCacheGzip) ? 'checked="checked"' : "";
			$wpFastestCacheLanguage = isset($this->options->wpFastestCacheLanguage) ? $this->options->wpFastestCacheLanguage : "eng";
			$wpFastestCacheTimeOut = isset($this->cronJobSettings["period"]) ? $this->cronJobSettings["period"] : "";
			?>
			<div class="wrap">
				<h2>WP Fastest Cache Options</h2>
				<?php if($this->systemMessage){ ?>
					<div class="updated <?php echo $this->systemMessage[1]; ?>" id="message"><p><?php echo $this->systemMessage[0]; ?></p></div>
				<?php } ?>
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
								<div class="question">Cache System</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheStatus; ?> id="wpFastestCacheStatus" name="wpFastestCacheStatus"><label for="wpFastestCacheStatus">Enable</label></div>
							</div>
							<div class="questionCon">
								<div class="question">New Post</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheNewPost; ?> id="wpFastestCacheNewPost" name="wpFastestCacheNewPost"><label for="wpFastestCacheNewPost">Clear all cache files when a post or page is published</label></div>
							</div>
							<div class="questionCon">
								<div class="question">Minify HTML</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMinifyHtml; ?> id="wpFastestCacheMinifyHtml" name="wpFastestCacheMinifyHtml"><label for="wpFastestCacheMinifyHtml">You can decrease the size of page</label></div>
								<div class="get-info"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></div>
							</div>

							<div class="questionCon">
								<div class="question">Minify Css</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMinifyCss; ?> id="wpFastestCacheMinifyCss" name="wpFastestCacheMinifyCss"><label for="wpFastestCacheMinifyCss">You can decrease the size of css files</label></div>
								<div class="get-info"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></div>
							</div>

							<div class="questionCon">
								<div class="question">Gzip</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheGzip; ?> id="wpFastestCacheGzip" name="wpFastestCacheGzip"><label for="wpFastestCacheGzip">Reduce the size of files sent from your server</label></div>
								<div class="get-info"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></div>
							</div>


							<div class="questionCon">
								<div class="question">Language</div>
								<div class="inputCon">
									<select id="wpFastestCacheLanguage" name="wpFastestCacheLanguage">
									  <option value="eng">English</option>
									  <option value="es">Español</option>
									  <option value="ru">Русский</option>
									  <option value="tr">Türkçe</option>
									  <option value="ukr">Українська</option>
									</select> 
								</div>
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
				    			<label>You can delete all cache files</label><br>
				    			<label>Target folder</label> <b><?php echo $this->wpContentDir; ?>/cache/all</b>
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
				    		<div class="questionCon">
				    			<label style="padding-left:11px;">All cached files are deleted at the determinated time.</label>
				    		</div>
				    		<div class="questionCon" style="text-align: center;padding-top: 10px;">
									<select id="wpFastestCacheTimeOut" name="wpFastestCacheTimeOut">
										<?php
											$arrSettings = array(array("value" => "", "text" => "Choose One"),
																array("value" => "hourly", "text" => "Once an hour"),
																array("value" => "daily", "text" => "Once a day"),
																array("value" => "twicedaily", "text" => "Twice a day"));

											foreach ($arrSettings as $key => $value) {
												$checked = $value["value"] == $wpFastestCacheTimeOut ? 'selected=""' : "";
												echo "<option {$checked} value='{$value["value"]}'>{$value["text"]}</option>";
											}
										?>
									</select> 
							</div>
							<?php if($wpFastestCacheTimeOut){ ?>
								<div class="questionCon">
									<table class="widefat fixed" style="border:0;border-top:1px solid #DEDBD1;border-radius:0;margin: 5px 4% 0 4%;width: 92%;">
										<thead>
											<tr>
												<th scope="col" style="border-left:1px solid #DEDBD1;border-top-left-radius:0;">Next due</th>
												<th scope="col" style="border-right:1px solid #DEDBD1;border-top-right-radius:0;">Schedule</th>
											</tr>
										</thead>
											<tbody>
												<tr>
													<th scope="row" style="border-left:1px solid #DEDBD1;"><?php echo date("d-m-Y @ H:i", $this->cronJobSettings["time"]); ?></th>
													<td style="border-right:1px solid #DEDBD1;"><?php echo $this->cronJobSettings["period"]; ?>
														<label id="deleteCron" style="float:right;padding-right:5px;">[ x ]</label>
														<script>
															jQuery("#deleteCron").click(function(){
																var selectPeriod = jQuery("#wpFastestCacheTimeOut");
																selectPeriod.val("");
																var submit = selectPeriod.closest("form").find('input[type="submit"]');
																submit.click();
															})
														</script>
													</td>
												</tr>
											</tbody>
									</table>
					    		</div>
				    		<?php } ?>
				    		<div class="questionCon" style="text-align: center;padding-top: 10px;">
				    			<strong><label>Server time</label>: <?php echo date("Y-m-d H:i:s"); ?></strong>
				    		</div>
				    		<div class="questionCon qsubmit">
				    			<div class="submit"><input type="submit" value="Submit" class="button-primary"></div>
				    		</div>
				   		</form>
				    </div>
				</div>
				<div class="omni_admin_sidebar">
				<div class="omni_admin_sidebar_section">
				  <h3>Having Issues?</h3>
				  <ul>
				    <li><label>You can create a ticket</label> <a target="_blank" href="http://wordpress.org/support/plugin/wp-fastest-cache"><label>WordPress support forum</label></a></li>
				  </ul>
				  </div>
				</div>
			</div>
			<script>Wpfclang.init("<?php echo $wpFastestCacheLanguage; ?>");</script>
			<?php
		}

		public function checkCronTime(){
			add_action($this->slug,  array($this, 'setSchedule'));
			add_action($this->slug."TmpDelete",  array($this, 'actionDelete'));
		}

		public function detectNewPost(){
			if(isset($this->options->wpFastestCacheNewPost) && isset($this->options->wpFastestCacheStatus)){
				add_filter ('publish_post', array($this, 'deleteCache'));
				add_filter ('delete_post', array($this, 'deleteCache'));
			}
		}

		public function deleteCache(){
			if(is_dir($this->wpContentDir."/cache/all")){
				//$this->rm_folder_recursively($this->wpContentDir."/cache/all");
				if(is_dir($this->wpContentDir."/cache/tmpWpfc")){
					rename($this->wpContentDir."/cache/all", $this->wpContentDir."/cache/tmpWpfc/".time());
					wp_schedule_single_event(time() + 60, $this->slug."TmpDelete");
					$this->systemMessage = array("All cache files have been deleted","success");
				}else if(@mkdir($this->wpContentDir."/cache/tmpWpfc", 0755, true)){
					rename($this->wpContentDir."/cache/all", $this->wpContentDir."/cache/tmpWpfc/".time());
					wp_schedule_single_event(time() + 60, $this->slug."TmpDelete");
					$this->systemMessage = array("All cache files have been deleted","success");
				}else{
					$this->systemMessage = array("Permission of <strong>/wp-content/cache</strong> must be <strong>755</strong>", "error");
				}
			}else{
				$this->systemMessage = array("Already deleted","success");
			}
		}

		public function actionDelete(){
			if(is_dir($this->wpContentDir."/cache/tmpWpfc")){
				$this->rm_folder_recursively($this->wpContentDir."/cache/tmpWpfc");
				if(is_dir($this->wpContentDir."/cache/tmpWpfc")){
					wp_schedule_single_event(time() + 60, $this->slug."TmpDelete");
				}
			}
		}

		public function addCacheTimeout(){
			if(isset($_POST["wpFastestCacheTimeOut"])){
				if($_POST["wpFastestCacheTimeOut"]){
					wp_clear_scheduled_hook($this->slug);
					wp_schedule_event(time() + 120, $_POST["wpFastestCacheTimeOut"], $this->slug);
				}else{
					wp_clear_scheduled_hook($this->slug);
				}
			}
		}

		public function setSchedule(){
			$this->deleteCache();
		}

		public function setCronJobSettings(){
			if(wp_next_scheduled($this->slug)){
				$this->cronJobSettings["period"] = wp_get_schedule($this->slug);
				$this->cronJobSettings["time"] = wp_next_scheduled($this->slug);
			}
		}

		public function rm_folder_recursively($dir, $i = 1) {
		    foreach(scandir($dir) as $file) {
		    	if($i > 500){
		    		return true;
		    	}else{
		    		$i++;
		    	}
		        if ('.' === $file || '..' === $file) continue;
		        if (is_dir("$dir/$file")) $this->rm_folder_recursively("$dir/$file", $i);
		        else @unlink("$dir/$file");
		    }
		    
		    @rmdir($dir);
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
					return array(".htacces was not found", "error");
				}else if(is_writable(ABSPATH.".htaccess")){
					$htaccess = file_get_contents(ABSPATH.".htaccess");
					$htaccess = $this->insertRewriteRule($htaccess);
					$htaccess = $this->insertGzipRule($htaccess, $post);
					file_put_contents(ABSPATH.".htaccess", $htaccess);
				}else{
					return array(".htacces is not writable", "error");
				}
				return array("Options have been saved", "success");
			}else{
				//disable
				$this->deleteCache();
				return array("Options have been saved", "success");
			}
		}

		public function insertGzipRule($htaccess, $post){
			if(isset($post["wpFastestCacheGzip"]) && $post["wpFastestCacheGzip"] == "on"){
		    	$data = "# BEGIN GzipWpFastestCache"."\n".
		          		"<IfModule mod_deflate.c>"."\n".
		  				"AddOutputFilterByType DEFLATE text/plain"."\n".
		  				"AddOutputFilterByType DEFLATE text/html"."\n".
		  				"AddOutputFilterByType DEFLATE text/xml"."\n".
		  				"AddOutputFilterByType DEFLATE text/css"."\n".
		  				"AddOutputFilterByType DEFLATE application/xml"."\n".
		  				"AddOutputFilterByType DEFLATE application/xhtml+xml"."\n".
		  				"AddOutputFilterByType DEFLATE application/rss+xml"."\n".
		  				"AddOutputFilterByType DEFLATE application/javascript"."\n".
		  				"AddOutputFilterByType DEFLATE application/x-javascript"."\n".
		  				"</IfModule>"."\n".
						"# END GzipWpFastestCache"."\n\n";

				preg_match("/BEGIN GzipWpFastestCache/", $htaccess, $check);
				if(count($check) === 0){
					return $data.$htaccess;
				}else{
					return $htaccess;
				}	
			}else{
				//delete gzip rules
				$htaccess = preg_replace("/#\s?BEGIN\s?GzipWpFastestCache.*?#\s?END\s?GzipWpFastestCache/s", "", $htaccess);
				//echo $htaccess;
				//file_put_contents(ABSPATH.".htaccess", $htaccess);
				return $htaccess;
			}
		}

		public function insertRewriteRule($htaccess){
			preg_match("/wp-content\/cache\/all/", $htaccess, $check);
			if(count($check) === 0){
				$htaccess = $this->getHtaccess().$htaccess;
			}else{
				//already changed
			}
			return $htaccess;
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
				$cachFilePath = $this->wpContentDir."/cache/all".$_SERVER["REQUEST_URI"];

				$content = $this->cacheDate($buffer);
				$content = $this->minify($content);
				$content = $this->minifyCss($content);

				$this->createFolder($cachFilePath, $content);

				if($_SERVER["REQUEST_URI"] == "/"){
					$this->createFolder($this->wpContentDir."/cache/all/index.php", $content);
				}

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
						if(is_writable($this->wpContentDir) || ((is_dir($this->wpContentDir."/cache")) && (is_writable($this->wpContentDir."/cache")))){
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
				$contentUrl = str_replace("http://", "", content_url());
				$contentUrl = str_replace("www.", "", $contentUrl);

				$contentUrlPreg = preg_quote($contentUrl, "/");

				preg_match_all("/href=[\"\']http\:\/\/(www\.)?$contentUrlPreg\/(.*?)\.css(.*?)[\"\']/", $content, $match, PREG_SET_ORDER);
				if(count($match) > 0){
					foreach ($match as $fileV) {
						$file = "http://".$fileV[1].$contentUrl."/".$fileV[2];
				    	$cssThemePath = "/".$fileV[2];
					    $cachFilePath = $this->wpContentDir."/cache/all/".md5($cssThemePath);

					    if(!is_dir($cachFilePath)){
					    	if($cachFilePath){
								if($cssContent = @file_get_contents($file.".css")){
									require_once "minify-css.php";
								    $themePath = substr($cssThemePath, 1, strripos($cssThemePath, "/"));
								    $cssContent = preg_replace('/url\((\"|\')?(?!\"http|http|\'http)(.*?)\.(.*?)\)/','url($1'.str_repeat("../", 3).$themePath.'$2.$3)',$cssContent); 
								    $cssContent = Minify_CSS_Compressor::process($cssContent, array());
								    $prefix = time();
								    $this->createFolder($cachFilePath, $cssContent, "css", $prefix);

									$newFile = str_replace('wp-content', 'wp-content/cache/all/', $file);
								    $newFile = str_replace($cssThemePath, md5($cssThemePath), $newFile);
								    $content = str_replace($fileV[3] ? $file.".css".$fileV[3] : $file.".css", $newFile."/".$prefix."index.css", $content);
								}
					    	}
					    }else{
						    $newFile = str_replace('wp-content', 'wp-content/cache/all/', $file);
						    $newFile = str_replace($cssThemePath, md5($cssThemePath), $newFile);
						    $cssFiles = scandir($cachFilePath, 1);
						    if($cssFiles){
						    	$content = str_replace($fileV[3] ? $file.".css".$fileV[3] : $file.".css", $newFile."/".$cssFiles[0], $content);
						    }
					    }

				  	}
				}
			}
			return $content;
		}
	}
?>