<?php
	class WpFastestCacheAdmin extends WpFastestCache{
		private $menuTitle = "WP Fastest Cache";
		private $pageTitle = "WP Fastest Cache Settings";
		private $adminPageUrl = "wp-fastest-cache/admin/index.php";
		private $systemMessage = array();
		private $options = array();
		private $cronJobSettings;
		private $startTime;
		private $blockCache = false;

		public function __construct(){
			$this->options = $this->getOptions();
			
			$this->optionsPageRequest();
			$this->iconUrl = plugins_url("wp-fastest-cache/images/icon-32x32.png");
			$this->setCronJobSettings();
			$this->addButtonOnEditor();
			add_action('admin_enqueue_scripts', array($this, 'addJavaScript'));
			$this->checkActivePlugins();

			if(file_exists(plugin_dir_path(__FILE__)."admin-toolbar.php")){
				add_action( 'wp_loaded', array($this, "load_admin_toolbar") );
			}
		}

		public function get_premium_version(){
			$wpfc_premium_version = "";
			if(file_exists(WPFC_WP_PLUGIN_DIR."/wp-fastest-cache-premium/wpFastestCachePremium.php")){
				if($data = @file_get_contents(WPFC_WP_PLUGIN_DIR."/wp-fastest-cache-premium/wpFastestCachePremium.php")){
					preg_match("/Version:\s*(.+)/", $data, $out);
					if(isset($out[1]) && $out[1]){
						$wpfc_premium_version = trim($out[1]);
					}
				}
			}
			return $wpfc_premium_version;
		}
		public function load_admin_toolbar(){
			if (current_user_can( 'manage_options' ) || current_user_can('edit_others_pages')) {
				include_once plugin_dir_path(__FILE__)."admin-toolbar.php";

				add_action('wp_ajax_wpfc_delete_cache', array($this, "deleteCacheToolbar"));
				add_action('wp_ajax_wpfc_delete_cache_and_minified', array($this, "deleteCssAndJsCacheToolbar"));
				
				$toolbar = new WpFastestCacheAdminToolbar();
				$toolbar->add();
			}
		}

		public function deleteCacheToolbar(){
			$this->deleteCache();
		}

		public function deleteCssAndJsCacheToolbar(){
			$this->deleteCssAndJsCache();
		}

		public function checkActivePlugins(){
			//for WP-Polls
			if($this->isPluginActive('wp-polls/wp-polls.php')){
				require_once "wp-polls.php";
				$wp_polls = new WpPollsForWpFc();
				$wp_polls->hook();
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
		   if (current_user_can( 'manage_options' )) {
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
			if (wp_script_is('quicktags') && current_user_can( 'manage_options' )){ ?>
				<script type="text/javascript">
				    QTags.addButton('wpfc_not', 'wpfcNOT', '<!--[wpfcNOT]-->', '', '', 'Block caching for this page');
			    </script>
		    <?php }
		}

		public function optionsPageRequest(){
			if(!empty($_POST)){
				if(isset($_POST["wpFastestCachePage"])){
					include_once ABSPATH."wp-includes/capabilities.php";
					include_once ABSPATH."wp-includes/pluggable.php";

					if(is_multisite()){
						$this->systemMessage = array("The plugin does not work with Multisite", "error");
						return 0;
					}

					if(current_user_can('manage_options')){
						if($_POST["wpFastestCachePage"] == "options"){
							$this->saveOption();
						}else if($_POST["wpFastestCachePage"] == "deleteCache"){
							$this->deleteCache();
						}else if($_POST["wpFastestCachePage"] == "deleteCssAndJsCache"){
							$this->deleteCssAndJsCache();
						}else if($_POST["wpFastestCachePage"] == "cacheTimeout"){
							$this->addCacheTimeout();
						}else if($_POST["wpFastestCachePage"] == "exclude"){
							$this->save_exclude_pages();
						}
					}else{
						die("Forbidden");
					}
				}
			}
		}

		public function save_exclude_pages(){
			$rules = array();

			for($i = 1; $i < count($_POST); $i++){
				$rule = array();

				if(isset($_POST["wpfc-exclude-rule-prefix-".$i]) && $_POST["wpfc-exclude-rule-prefix-".$i] && $_POST["wpfc-exclude-rule-content-".$i]){
					$rule["prefix"] = $_POST["wpfc-exclude-rule-prefix-".$i];
					$rule["content"] = $_POST["wpfc-exclude-rule-content-".$i];

					array_push($rules, $rule);
				}
			}

			$data = json_encode($rules);

			if(get_option("WpFastestCacheExclude")){
				update_option("WpFastestCacheExclude", $data);
			}else{
				add_option("WpFastestCacheExclude", $data, null, "yes");
			}

			$this->systemMessage = array("Options have been saved", "success");
		}

		public function addCacheTimeout(){
			if(isset($_POST["wpFastestCacheTimeOut"])){
				if($_POST["wpFastestCacheTimeOut"]){
					if(isset($_POST["wpFastestCacheTimeOutHour"]) && is_numeric($_POST["wpFastestCacheTimeOutHour"])){
						if(isset($_POST["wpFastestCacheTimeOutMinute"]) && is_numeric($_POST["wpFastestCacheTimeOutMinute"])){
							$selected = mktime($_POST["wpFastestCacheTimeOutHour"], $_POST["wpFastestCacheTimeOutMinute"], 0, date("n"), date("j"), date("Y"));

							if($selected > time()){
								$timestamp = $selected;
							}else{
								if(time() - $selected < 60){
									$timestamp = $selected + 60;
								}else{
									// if selected time is less than now, 24hours is added
									$timestamp = $selected + 24*60*60;
								}
							}

							wp_clear_scheduled_hook($this->slug());
							wp_schedule_event($timestamp, $_POST["wpFastestCacheTimeOut"], $this->slug());
						}else{
							echo "Minute was not set";
							exit;
						}
					}else{
						echo "Hour was not set";
						exit;
					}
				}else{
					wp_clear_scheduled_hook($this->slug());
				}
			}
		}

		public function deleteCssAndJsCache(){
			delete_option("WpFastestCacheCSS");
			delete_option("WpFastestCacheCSSSIZE");
			delete_option("WpFastestCacheJS");
			delete_option("WpFastestCacheJSSIZE");

			if(is_dir($this->getWpContentDir()."/cache/wpfc-minified")){
				$this->rm_folder_recursively($this->getWpContentDir()."/cache/wpfc-minified");
				$this->deleteCache(true);
			}else{
				$this->deleteCache();
				$this->systemMessage = array("Already deleted","success");
			}
		}

		public function setCronJobSettings(){
			if(wp_next_scheduled($this->slug())){
				$this->cronJobSettings["period"] = wp_get_schedule($this->slug());
				$this->cronJobSettings["time"] = wp_next_scheduled($this->slug());
			}
		}

		public function addMenuPage(){
			add_action('admin_menu', array($this, 'register_my_custom_menu_page'));
		}

		public function addJavaScript(){
			wp_enqueue_script("wpfc-jquery-ui", plugins_url("wp-fastest-cache/js/jquery-ui.min.js"), array(), time(), false);
			wp_enqueue_script("wpfc-dialog", plugins_url("wp-fastest-cache/js/dialog.js"), array(), time(), false);


			wp_enqueue_script("wpfc-cdn", plugins_url("wp-fastest-cache/js/cdn/cdn.js"), array(), time(), false);
			//wp_enqueue_script("wpfc-cdn-maxcdn", plugins_url("wp-fastest-cache/js/cdn/maxcdn.js"), array(), time(), false);


			wp_enqueue_script("wpfc-language", plugins_url("wp-fastest-cache/js/language.js"), array(), time(), false);
			wp_enqueue_script("wpfc-info", plugins_url("wp-fastest-cache/js/info.js"), array(), time(), true);
			wp_enqueue_script("wpfc-schedule", plugins_url("wp-fastest-cache/js/schedule.js"), array(), time(), true);
			wp_enqueue_script("wpfc-toolbar", plugins_url("wp-fastest-cache/js/toolbar.js"), array(), time(), true);

			
			if(class_exists("WpFastestCacheImageOptimisation")){

				if(file_exists(WPFC_WP_PLUGIN_DIR."/wp-fastest-cache-premium/pro/js/statics.js")){
					wp_enqueue_script("wpfc-statics", plugins_url("wp-fastest-cache-premium/pro/js/statics.js"), array(), time(), false);
				}else{
					wp_enqueue_script("wpfc-statics", plugins_url("wp-fastest-cache/js/statics.js"), array(), time(), false);
				}

				if(file_exists(WPFC_WP_PLUGIN_DIR."/wp-fastest-cache-premium/pro/js/premium.js")){
					wp_enqueue_script("wpfc-premium", plugins_url("wp-fastest-cache-premium/pro/js/premium.js"), array(), time(), true);
				}
			}
			
			if(isset($this->options->wpFastestCacheLanguage) && $this->options->wpFastestCacheLanguage != "eng"){
				wp_enqueue_script("wpfc-dictionary", plugins_url("wp-fastest-cache/js/lang/".$this->options->wpFastestCacheLanguage.".js"), array(), time(), false);
			}
		}

		public function register_my_custom_menu_page(){
			if(function_exists('add_menu_page')){ 
				add_menu_page($this->pageTitle, $this->menuTitle, 'manage_options', "WpFastestCacheOptions", array($this, 'optionsPage'), $this->iconUrl, "99.".time() );
				wp_enqueue_style("wp-fastest-cache", plugins_url("wp-fastest-cache/css/style.css"), array(), time(), "all");
			}
			
			wp_enqueue_style("wp-fastest-cache-toolbar", plugins_url("wp-fastest-cache/css/toolbar.css"), array(), time(), "all");
			
			if(isset($_GET["page"]) && $_GET["page"] == "WpFastestCacheOptions"){
				wp_enqueue_style("wp-fastest-cache-buycredit", plugins_url("wp-fastest-cache/css/buycredit.css"), array(), time(), "all");
				wp_enqueue_style("wp-fastest-cache-flaticon", plugins_url("wp-fastest-cache/css/flaticon.css"), array(), time(), "all");
				wp_enqueue_style("wp-fastest-cache-dialog", plugins_url("wp-fastest-cache/css/dialog.css"), array(), time(), "all");
			}
		}

		public function saveOption(){
			unset($_POST["wpFastestCachePage"]);
			$data = json_encode($_POST);
			//for optionsPage() $_POST is array and json_decode() converts to stdObj
			$this->options = json_decode($data);

			$this->systemMessage = $this->modifyHtaccess($_POST);

			if(isset($this->systemMessage[1]) && $this->systemMessage[1] != "error"){

				if($message = $this->checkCachePathWriteable()){


					if(is_array($message)){
						$this->systemMessage = $message;
					}else{
						if(get_option("WpFastestCache")){
							update_option("WpFastestCache", $data);
						}else{
							add_option("WpFastestCache", $data, null, "yes");
						}
					}
				}
			}
		}

		public function checkCachePathWriteable(){
			$message = array();

			if(!is_dir($this->getWpContentDir()."/cache/")){
				if (@mkdir($this->getWpContentDir()."/cache/", 0755, true)){
					//
				}else{
					array_push($message, "- /wp-content/cache/ is needed to be created");
				}
			}else{
				if (@mkdir($this->getWpContentDir()."/cache/testWpFc/", 0755, true)){
					rmdir($this->getWpContentDir()."/cache/testWpFc/");
				}else{
					array_push($message, "- /wp-content/cache/ permission has to be 755");
				}
			}

			if(!is_dir($this->getWpContentDir()."/cache/all/")){
				if (@mkdir($this->getWpContentDir()."/cache/all/", 0755, true)){
					//
				}else{
					array_push($message, "- /wp-content/cache/all/ is needed to be created");
				}
			}else{
				if (@mkdir($this->getWpContentDir()."/cache/all/testWpFc/", 0755, true)){
					rmdir($this->getWpContentDir()."/cache/all/testWpFc/");
				}else{
					array_push($message, "- /wp-content/cache/all/ permission has to be 755");
				}	
			}

			if(count($message) > 0){
				return array(implode("<br>", $message), "error");
			}else{
				return true;
			}
		}

		public function modifyHtaccess($post){
			$path = ABSPATH;
			if($this->is_subdirectory_install()){
				$path = $this->getABSPATH();
			}

			if(isset($_SERVER["SERVER_SOFTWARE"]) && $_SERVER["SERVER_SOFTWARE"] && preg_match("/iis/i", $_SERVER["SERVER_SOFTWARE"])){
				return array("The plugin does not work with Microsoft IIS only with Apache", "error");
			}

			if(!file_exists($path.".htaccess")){
				return array("<label>.htaccess was not found</label> <a target='_blank' href='http://www.wpfastestcache.com/warnings/htaccess-was-not-found/'>Read More</a>", "error");
			}

			if($this->isPluginActive('wp-postviews/wp-postviews.php')){
				$wp_postviews_options = get_option("views_options");
				$wp_postviews_options["use_ajax"] = true;
				update_option("views_options", $wp_postviews_options);

				if(!WP_CACHE){
					if($wp_config = @file_get_contents(ABSPATH."wp-config.php")){
						$wp_config = str_replace("\$table_prefix", "define('WP_CACHE', true);\n\$table_prefix", $wp_config);

						if(!@file_put_contents(ABSPATH."wp-config.php", $wp_config)){
							return array("define('WP_CACHE', true); is needed to be added into wp-config.php", "error");
						}
					}else{
						return array("define('WP_CACHE', true); is needed to be added into wp-config.php", "error");
					}
				}
			}

			$htaccess = file_get_contents($path.".htaccess");

			if(defined('DONOTCACHEPAGE')){
				return array("DONOTCACHEPAGE <label>constant is defined as TRUE. It must be FALSE</label>", "error");
			}else if(!get_option('permalink_structure')){
				return array("You have to set <strong><u><a href='".admin_url()."options-permalink.php"."'>permalinks</a></u></strong>", "error");
			}else if($res = $this->checkSuperCache($path, $htaccess)){
				return $res;
			}else if($this->isPluginActive('adrotate/adrotate.php')){
				return $this->warningIncompatible("AdRotate");
			}else if($this->isPluginActive('mobilepress/mobilepress.php')){
				return $this->warningIncompatible("MobilePress", array("name" => "WPtouch Mobile", "url" => "https://wordpress.org/plugins/wptouch/"));
			}else if($this->isPluginActive('wp-performance-score-booster/wp-performance-score-booster.php')){
				return array("WP Performance Score Booster needs to be deactive<br>This plugin has aldready Gzip, Leverage Browser Caching features", "error");
			}else if($this->isPluginActive('bwp-minify/bwp-minify.php')){
				return array("Better WordPress Minify needs to be deactive<br>This plugin has aldready Minify feature", "error");
			}else if($this->isPluginActive('gzippy/gzippy.php')){
				return array("GZippy needs to be deactive<br>This plugin has aldready Gzip feature", "error");
			}else if($this->isPluginActive('gzip-ninja-speed-compression/gzip-ninja-speed.php')){
				return array("GZip Ninja Speed Compression needs to be deactive<br>This plugin has aldready Gzip feature", "error");
			}else if($this->isPluginActive('wordpress-gzip-compression/ezgz.php')){
				return array("WordPress Gzip Compression needs to be deactive<br>This plugin has aldready Gzip feature", "error");
			}else if($this->isPluginActive('filosofo-gzip-compression/filosofo-gzip-compression.php')){
				return array("GZIP Output needs to be deactive<br>This plugin has aldready Gzip feature", "error");
			}else if($this->isPluginActive('head-cleaner/head-cleaner.php')){
				return array("Head Cleaner needs to be deactive", "error");
			}else if(is_writable($path.".htaccess")){
				$htaccess = $this->insertLBCRule($htaccess, $post);
				$htaccess = $this->insertGzipRule($htaccess, $post);
				$htaccess = $this->insertRewriteRule($htaccess, $post);
				$htaccess = preg_replace("/\n+/","\n", $htaccess);

				file_put_contents($path.".htaccess", $htaccess);
			}else{
				return array("Options have been saved", "success");
				//return array(".htaccess is not writable", "error");
			}
			return array("Options have been saved", "success");

		}

		public function warningIncompatible($incompatible, $alternative = false){
			if($alternative){
				return array($incompatible." <label>needs to be deactive</label><br><label>We advise</label> <a id='alternative-plugin' target='_blank' href='".$alternative["url"]."'>".$alternative["name"]."</a>", "error");
			}else{
				return array($incompatible." <label>needs to be deactive</label>", "error");
			}
		}

		public function insertLBCRule($htaccess, $post){
			if(isset($post["wpFastestCacheLBC"]) && $post["wpFastestCacheLBC"] == "on"){


			$data = "# BEGIN LBCWpFastestCache"."\n".
					'<FilesMatch "\.(ico|pdf|flv|jpg|jpeg|png|gif|js|css|swf|x-html|css|xml|js|woff|ttf|svg|eot)(\.gz)?$">'."\n".
					'<IfModule mod_expires.c>'."\n".
					'ExpiresActive On'."\n".
					'ExpiresDefault A0'."\n".
					'ExpiresByType image/gif A2592000'."\n".
					'ExpiresByType image/png A2592000'."\n".
					'ExpiresByType image/jpg A2592000'."\n".
					'ExpiresByType image/jpeg A2592000'."\n".
					'ExpiresByType image/ico A2592000'."\n".
					'ExpiresByType image/svg+xml A2592000'."\n".
					'ExpiresByType text/css A2592000'."\n".
					'ExpiresByType text/javascript A2592000'."\n".
					'ExpiresByType application/javascript A2592000'."\n".
					'</IfModule>'."\n".
					'<IfModule mod_headers.c>'."\n".
					'Header set Expires "max-age=2592000, public"'."\n".
					'Header unset ETag'."\n".
					'Header set Connection keep-alive'."\n".
					'</IfModule>'."\n".
					'FileETag None'."\n".
					'</FilesMatch>'."\n".
					"# END LBCWpFastestCache"."\n";

				preg_match("/BEGIN LBCWpFastestCache/", $htaccess, $check);
				if(count($check) === 0){
					return $data.$htaccess;
				}else{
					return $htaccess;
				}
			}else{
				//delete levere browser caching
				$htaccess = preg_replace("/#\s?BEGIN\s?LBCWpFastestCache.*?#\s?END\s?LBCWpFastestCache/s", "", $htaccess);
				return $htaccess;
			}
		}

		public function insertGzipRule($htaccess, $post){
			if(isset($post["wpFastestCacheGzip"]) && $post["wpFastestCacheGzip"] == "on"){
		    	$data = "# BEGIN GzipWpFastestCache"."\n".
		          		"<IfModule mod_deflate.c>"."\n".
		          		"AddOutputFilterByType DEFLATE image/svg+xml"."\n".
		  				"AddOutputFilterByType DEFLATE text/plain"."\n".
		  				"AddOutputFilterByType DEFLATE text/html"."\n".
		  				"AddOutputFilterByType DEFLATE text/xml"."\n".
		  				"AddOutputFilterByType DEFLATE text/css"."\n".
		  				"AddOutputFilterByType DEFLATE text/javascript"."\n".
		  				"AddOutputFilterByType DEFLATE application/xml"."\n".
		  				"AddOutputFilterByType DEFLATE application/xhtml+xml"."\n".
		  				"AddOutputFilterByType DEFLATE application/rss+xml"."\n".
		  				"AddOutputFilterByType DEFLATE application/javascript"."\n".
		  				"AddOutputFilterByType DEFLATE application/x-javascript"."\n".
		  				"AddOutputFilterByType DEFLATE application/x-font-ttf"."\n".
						"AddOutputFilterByType DEFLATE application/vnd.ms-fontobject"."\n".
						"AddOutputFilterByType DEFLATE font/opentype font/ttf font/eot font/otf"."\n".
		  				"</IfModule>"."\n".
						"# END GzipWpFastestCache"."\n";


				$htaccess = preg_replace("/#\s?BEGIN\s?GzipWpFastestCache.*?#\s?END\s?GzipWpFastestCache/s", "", $htaccess);
				return $data.$htaccess;

			}else{
				//delete gzip rules
				$htaccess = preg_replace("/#\s?BEGIN\s?GzipWpFastestCache.*?#\s?END\s?GzipWpFastestCache/s", "", $htaccess);
				return $htaccess;
			}
		}

		public function insertRewriteRule($htaccess, $post){
			if(isset($post["wpFastestCacheStatus"]) && $post["wpFastestCacheStatus"] == "on"){
				$htaccess = preg_replace("/#\s?BEGIN\s?WpFastestCache.*?#\s?END\s?WpFastestCache/s", "", $htaccess);
				$htaccess = $this->getHtaccess().$htaccess;
			}else{
				$htaccess = preg_replace("/#\s?BEGIN\s?WpFastestCache.*?#\s?END\s?WpFastestCache/s", "", $htaccess);
				$this->deleteCache();
			}

			return $htaccess;
		}

		public function prefixRedirect(){
			$forceTo = "";
			
			if(defined("WPFC_DISABLE_REDIRECTION") && WPFC_DISABLE_REDIRECTION){
				return $forceTo;
			}

			if(preg_match("/^https:\/\//", home_url())){
				if(preg_match("/^https:\/\/www\./", home_url())){
					$forceTo = "\nRewriteCond %{HTTPS} =on"."\n".
					           "RewriteCond %{HTTP_HOST} ^www.".str_replace("www.", "", $_SERVER["HTTP_HOST"])."\n";
				}else{
					$forceTo = "\nRewriteCond %{HTTPS} =on"."\n".
							   "RewriteCond %{HTTP_HOST} ^".str_replace("www.", "", $_SERVER["HTTP_HOST"])."\n";
				}
			}else{
				if(preg_match("/^http:\/\/www\./", home_url())){
					$forceTo = "\nRewriteCond %{HTTP_HOST} ^".str_replace("www.", "", $_SERVER["HTTP_HOST"])."\n".
							   "RewriteRule ^(.*)$ ".preg_quote(home_url(), "/")."\/$1 [R=301,L]"."\n";
				}else{
					$forceTo = "\nRewriteCond %{HTTP_HOST} ^www.".str_replace("www.", "", $_SERVER["HTTP_HOST"])." [NC]"."\n".
							   "RewriteRule ^(.*)$ ".preg_quote(home_url(), "/")."\/$1 [R=301,L]"."\n";
				}
			}
			return $forceTo;
		}

		public function getHtaccess(){
			$mobile = "";
			$loggedInUser = "";

			if(isset($_POST["wpFastestCacheMobile"]) && $_POST["wpFastestCacheMobile"] == "on"){
				$mobile = "RewriteCond %{HTTP_USER_AGENT} !^.*(".$this->getMobileUserAgents().").*$ [NC]"."\n";
			}

			if(isset($_POST["wpFastestCacheLoggedInUser"]) && $_POST["wpFastestCacheLoggedInUser"] == "on"){
				$loggedInUser = "RewriteCond %{HTTP:Cookie} !^.*(comment_author_|wordpress_logged_in|wp-postpass_|wp_woocommerce_session).*$"."\n";
			}

			$data = "# BEGIN WpFastestCache"."\n".
					"<IfModule mod_rewrite.c>"."\n".
					"RewriteEngine On"."\n".
					"RewriteBase /"."\n".
					"AddDefaultCharset UTF-8"."\n".$this->ruleForWpContent().
					$this->prefixRedirect().
					"RewriteCond %{REQUEST_METHOD} !POST"."\n".
					"RewriteCond %{QUERY_STRING} !.*=.*"."\n".$loggedInUser.
					'RewriteCond %{HTTP:X-Wap-Profile} !^[a-z0-9\"]+ [NC]'."\n".
					'RewriteCond %{HTTP:Profile} !^[a-z0-9\"]+ [NC]'."\n".$mobile;
			

			if(ABSPATH == "//"){
				$data = $data."RewriteCond %{DOCUMENT_ROOT}/".WPFC_WP_CONTENT_BASENAME."/cache/all/$1/index.html -f"."\n";
			}else{
				$data = $data."RewriteCond %{DOCUMENT_ROOT}/".WPFC_WP_CONTENT_BASENAME."/cache/all/$1/index.html -f [or]"."\n";
				$data = $data."RewriteCond ".WPFC_WP_CONTENT_DIR."/cache/all/".$this->getRewriteBase(true)."$1/index.html -f"."\n";
			}

			$data = $data.'RewriteRule ^(.*) "/'.$this->getRewriteBase().WPFC_WP_CONTENT_BASENAME.'/cache/all/'.$this->getRewriteBase(true).'$1/index.html" [L]'."\n";
			
			//RewriteRule !/  "/wp-content/cache/all/index.html" [L]


			if(class_exists("WpFcMobileCache") && isset($this->options->wpFastestCacheMobileTheme) && $this->options->wpFastestCacheMobileTheme){
				$wpfc_mobile = new WpFcMobileCache();
				$wpfc_mobile->set_wptouch($this->isPluginActive('wptouch/wptouch.php'));
				$data = $data."\n\n\n".$wpfc_mobile->update_htaccess($data);
			}

			$data = $data."</IfModule>"."\n".
					"<FilesMatch \"\.(html|htm)$\">"."\n".
					"FileETag None"."\n".
					"<ifModule mod_headers.c>"."\n".
					"Header unset ETag"."\n".
					"Header set Cache-Control \"max-age=0, no-cache, no-store, must-revalidate\""."\n".
					"Header set Pragma \"no-cache\""."\n".
					"Header set Expires \"Mon, 29 Oct 1923 20:30:00 GMT\""."\n".
					"</ifModule>"."\n".
					"</FilesMatch>"."\n".
					"# END WpFastestCache"."\n";
			return $data;
		}

		public function ruleForWpContent(){
			return "";
			$newContentPath = str_replace(home_url(), "", content_url());
			if(!preg_match("/wp-content/", $newContentPath)){
				$newContentPath = trim($newContentPath, "/");
				return "RewriteRule ^".$newContentPath."/cache/(.*) ".WPFC_WP_CONTENT_DIR."/cache/$1 [L]"."\n";
			}
			return "";
		}

		public function getRewriteBase($sub = ""){
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
				$out[1] = trim($out[1], "/");

				if(preg_match("/\/".$out[1]."\//", WPFC_WP_CONTENT_DIR)){
					return $out[1]."/";
				}else{
					return "";
				}
			}else{
				return "";
			}
		}



		public function checkSuperCache($path, $htaccess){
			if($this->isPluginActive('wp-super-cache/wp-cache.php')){
				return array("WP Super Cache needs to be deactive", "error");
			}else{
				@unlink($path."wp-content/wp-cache-config.php");

				$message = "";
				
				if(is_file($path."wp-content/wp-cache-config.php")){
					$message .= "<br>- be sure that you removed /wp-content/wp-cache-config.php";
				}

				if(preg_match("/supercache/", $htaccess)){
					$message .= "<br>- be sure that you removed the rules of super cache from the .htaccess";
				}

				return $message ? array("WP Super Cache cannot remove its own remnants so please follow the steps below".$message, "error") : "";
			}

			return "";
		}

		public function check_htaccess(){
			$path = ABSPATH;

			if($this->is_subdirectory_install()){
				$path = $this->getABSPATH();
			}
			
			if(!is_writable($path.".htaccess") && count($_POST) > 0){
				include_once(WPFC_MAIN_PATH."templates/htaccess.html");

				$htaccess = file_get_contents($path.".htaccess");

				if(isset($this->options->wpFastestCacheLBC)){
					$htaccess = $this->insertLBCRule($htaccess, array("wpFastestCacheLBC" => "on"));
				}
				if(isset($this->options->wpFastestCacheGzip)){
					$htaccess = $this->insertGzipRule($htaccess, array("wpFastestCacheGzip" => "on"));
				}
				if(isset($this->options->wpFastestCacheStatus)){
					$htaccess = $this->insertRewriteRule($htaccess, array("wpFastestCacheStatus" => "on"));
				}
				
				$htaccess = preg_replace("/\n+/","\n", $htaccess);

				echo "<noscript id='wpfc-htaccess-data'>".$htaccess."</noscript>";
				echo "<noscript id='wpfc-htaccess-path-data'>".$path.".htaccess"."</noscript>";
				?>
				<script type="text/javascript">
					Wpfc_Dialog.dialog("wpfc-htaccess-modal");
					jQuery("#wpfc-htaccess-modal-rules").html(jQuery("#wpfc-htaccess-data").html());
					jQuery("#wpfc-htaccess-modal-path").html(jQuery("#wpfc-htaccess-path-data").html());
				</script>
				<?php
			}

		}

		public function optionsPage(){
			$this->systemMessage = count($this->systemMessage) > 0 ? $this->systemMessage : $this->getSystemMessage();

			$wpFastestCacheCombineCss = isset($this->options->wpFastestCacheCombineCss) ? 'checked="checked"' : "";
			$wpFastestCacheGzip = isset($this->options->wpFastestCacheGzip) ? 'checked="checked"' : "";
			$wpFastestCacheCombineJs = isset($this->options->wpFastestCacheCombineJs) ? 'checked="checked"' : "";
			$wpFastestCacheCombineJsPowerFul = isset($this->options->wpFastestCacheCombineJsPowerFul) ? 'checked="checked"' : "";
			$wpFastestCacheDeferCss = isset($this->options->wpFastestCacheDeferCss) ? 'checked="checked"' : "";
			$wpFastestCacheLanguage = isset($this->options->wpFastestCacheLanguage) ? $this->options->wpFastestCacheLanguage : "eng";
			$wpFastestCacheLBC = isset($this->options->wpFastestCacheLBC) ? 'checked="checked"' : "";
			$wpFastestCacheLoggedInUser = isset($this->options->wpFastestCacheLoggedInUser) ? 'checked="checked"' : "";
			$wpFastestCacheMinifyCss = isset($this->options->wpFastestCacheMinifyCss) ? 'checked="checked"' : "";

			$wpFastestCacheMinifyCssPowerFul = isset($this->options->wpFastestCacheMinifyCssPowerFul) ? 'checked="checked"' : "";


			$wpFastestCacheMinifyHtml = isset($this->options->wpFastestCacheMinifyHtml) ? 'checked="checked"' : "";
			$wpFastestCacheMinifyHtmlPowerFul = isset($this->options->wpFastestCacheMinifyHtmlPowerFul) ? 'checked="checked"' : "";

			$wpFastestCacheMinifyJs = isset($this->options->wpFastestCacheMinifyJs) ? 'checked="checked"' : "";

			$wpFastestCacheMobile = isset($this->options->wpFastestCacheMobile) ? 'checked="checked"' : "";
			$wpFastestCacheMobileTheme = isset($this->options->wpFastestCacheMobileTheme) ? 'checked="checked"' : "";

			$wpFastestCacheNewPost = isset($this->options->wpFastestCacheNewPost) ? 'checked="checked"' : "";
			
			$wpFastestCacheRemoveComments = isset($this->options->wpFastestCacheRemoveComments) ? 'checked="checked"' : "";

			$wpFastestCacheStatus = isset($this->options->wpFastestCacheStatus) ? 'checked="checked"' : "";
			$wpFastestCacheTimeOut = isset($this->cronJobSettings["period"]) ? $this->cronJobSettings["period"] : "";
			?>
			<div class="wrap">
				<h2>WP Fastest Cache Options</h2>
				<?php if($this->systemMessage){ ?>
					<div class="updated <?php echo $this->systemMessage[1]."-wpfc"; ?>" id="message"><p><?php echo $this->systemMessage[0]; ?></p></div>
				<?php } ?>
				<div class="tabGroup">
					<?php
						$tabs = array(array("id"=>"wpfc-options","title"=>"Settings"),
									  array("id"=>"wpfc-deleteCache","title"=>"Delete Cache"),
									  array("id"=>"wpfc-cacheTimeout","title"=>"Cache Timeout"));

						if(class_exists("WpFastestCacheImageOptimisation")){
						}
							array_push($tabs, array("id"=>"wpfc-imageOptimisation","title"=>"Image Optimization"));
						
						array_push($tabs, array("id"=>"wpfc-premium","title"=>"Premium"));

						array_push($tabs, array("id"=>"wpfc-exclude","title"=>"Exclude"));


						// $cdn_tester_list = array("berkatan.com",
						// 						"villa-mosaica.com",
						// 						"teknooneri.com",
						// 						"poweryourinvestment.com",
						// 						"monamouresthetiqueauto.com",
						// 						"mapassionesthetiqueauto.ca",
						// 						"blackwaterstudios.co.uk",
						// 						"thessdreview.com",
						// 						"technologyx.com",
						// 						"thrivingaudios.com",
						// 						"smartlist.ee");
						// if(in_array(str_replace("www.", "", $_SERVER["HTTP_HOST"]), $cdn_tester_list)){
						// 	array_push($tabs, array("id"=>"wpfc-cdn","title"=>"CDN"));
						// }

						array_push($tabs, array("id"=>"wpfc-cdn","title"=>"CDN"));

						foreach ($tabs as $key => $value){
							$checked = "";

							//tab of "delete css and js" has been removed so there is need to check it
							if(isset($_POST["wpFastestCachePage"]) && $_POST["wpFastestCachePage"] && $_POST["wpFastestCachePage"] == "deleteCssAndJsCache"){
								$_POST["wpFastestCachePage"] = "deleteCache";
							}

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
				    <div class="tab1" style="padding-left:10px;">
						<form method="post" name="wp_manager">
							<input type="hidden" value="options" name="wpFastestCachePage">
							<div class="questionCon">
								<div class="question">Cache System</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheStatus; ?> id="wpFastestCacheStatus" name="wpFastestCacheStatus"><label for="wpFastestCacheStatus">Enable</label></div>
							</div>

							<div class="questionCon">
								<div class="question">Logged-in Users</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheLoggedInUser; ?> id="wpFastestCacheLoggedInUser" name="wpFastestCacheLoggedInUser"><label for="wpFastestCacheLoggedInUser">Don't show the cached version for logged-in users</label></div>
							</div>

							<div class="questionCon">
								<div class="question">Mobile</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMobile; ?> id="wpFastestCacheMobile" name="wpFastestCacheMobile"><label for="wpFastestCacheMobile">Don't show the cached version for desktop to mobile devices</label></div>
							</div>

							<?php if(class_exists("WpFastestCachePowerfulHtml")){ ?>
							<div class="questionCon">
								<div class="question">Mobile Theme</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMobileTheme; ?> id="wpFastestCacheMobileTheme" name="wpFastestCacheMobileTheme"><label for="wpFastestCacheMobileTheme">Create cache for mobile theme</label></div>
							</div>
							<?php }else{ ?>
							<div class="questionCon disabled">
								<div class="question">Mobile Theme</div>
								<div class="inputCon"><input type="checkbox" id="wpFastestCacheMobileTheme"><label for="wpFastestCacheMobileTheme">Create cache for mobile theme</label></div>
							</div>
							<?php } ?>

							<div class="questionCon">
								<div class="question">New Post</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheNewPost; ?> id="wpFastestCacheNewPost" name="wpFastestCacheNewPost"><label for="wpFastestCacheNewPost">Clear all cache files when a post or page is published</label></div>
							</div>
							<div class="questionCon">
								<div class="question">Minify HTML</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMinifyHtml; ?> id="wpFastestCacheMinifyHtml" name="wpFastestCacheMinifyHtml"><label for="wpFastestCacheMinifyHtml">You can decrease the size of page</label></div>
								<div class="get-info"><a target="_blank" href="http://www.wpfastestcache.com/optimization/minify-html/"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></a></div>
							</div>

							<?php if(class_exists("WpFastestCachePowerfulHtml")){ ?>
							<div class="questionCon">
								<div class="question">Minify HTML Plus</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMinifyHtmlPowerFul; ?> id="wpFastestCacheMinifyHtmlPowerFul" name="wpFastestCacheMinifyHtmlPowerFul"><label for="wpFastestCacheMinifyHtmlPowerFul">More powerful minify html</label></div>
							</div>
							<?php }else{ ?>
							<div class="questionCon disabled">
								<div class="question">Minify HTML Plus</div>
								<div class="inputCon"><input type="checkbox" id="wpFastestCacheMinifyHtmlPowerFul"><label for="wpFastestCacheMinifyHtmlPowerFul">More powerful minify html</label></div>
							</div>
							<?php } ?>



							<div class="questionCon">
								<div class="question">Minify Css</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMinifyCss; ?> id="wpFastestCacheMinifyCss" name="wpFastestCacheMinifyCss"><label for="wpFastestCacheMinifyCss">You can decrease the size of css files</label></div>
								<div class="get-info"><a target="_blank" href="http://www.wpfastestcache.com/optimization/minify-css/"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></a></div>
							</div>



							<?php if(class_exists("WpFastestCachePowerfulHtml") && method_exists("WpFastestCachePowerfulHtml", "minify_css")){ ?>
							<div class="questionCon">
								<div class="question">Minify Css Plus</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMinifyCssPowerFul; ?> id="wpFastestCacheMinifyCssPowerFul" name="wpFastestCacheMinifyCssPowerFul"><label for="wpFastestCacheMinifyCssPowerFul">More powerful minify css</label></div>
							</div>
							<?php }else{ ?>
							<div class="questionCon disabled">
								<div class="question">Minify Css Plus</div>
								<div class="inputCon"><input type="checkbox" id="wpFastestCacheMinifyCssPowerFul"><label for="wpFastestCacheMinifyCssPowerFul">More powerful minify css</label></div>
							</div>
							<?php } ?>


							<div class="questionCon">
								<div class="question">Combine Css</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheCombineCss; ?> id="wpFastestCacheCombineCss" name="wpFastestCacheCombineCss"><label for="wpFastestCacheCombineCss">Reduce HTTP requests through combined css files</label></div>
								<div class="get-info"><a target="_blank" href="http://www.wpfastestcache.com/optimization/combine-js-css-files/"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></a></div>
							</div>



							<div class="questionCon" style="display:none;">
								<div class="question">Defer Css</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheDeferCss; ?> id="wpFastestCacheDeferCss" name="wpFastestCacheDeferCss"><label for="wpFastestCacheDeferCss">Load the css files after page load</label></div>
							</div>






							<?php if(class_exists("WpFastestCachePowerfulHtml")){ ?>
								<?php if(method_exists("WpFastestCachePowerfulHtml", "minify_js_in_body")){ ?>
									<div class="questionCon">
										<div class="question">Minify Js</div>
										<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheMinifyJs; ?> id="wpFastestCacheMinifyJs" name="wpFastestCacheMinifyJs"><label for="wpFastestCacheMinifyJs">You can decrease the size of js files</label></div>
									</div>
								<?php }else{ ?>
									<div class="questionCon update-needed">
										<div class="question">Minify Js</div>
										<div class="inputCon"><input type="checkbox" id="wpFastestCacheMinifyJs"><label for="wpFastestCacheMinifyJs">You can decrease the size of js files</label></div>
									</div>
								<?php } ?>
							<?php }else{ ?>
							<div class="questionCon disabled">
								<div class="question">Minify Js</div>
								<div class="inputCon"><input type="checkbox" id="wpFastestCacheMinifyJs"><label for="wpFastestCacheMinifyJs">You can decrease the size of js files</label></div>
							</div>
							<?php } ?>

							<div class="questionCon">
								<div class="question">Combine Js</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheCombineJs; ?> id="wpFastestCacheCombineJs" name="wpFastestCacheCombineJs"><label for="wpFastestCacheCombineJs">Reduce HTTP requests through combined js files</label> <b style="color:red;">(header)</b></div>
								<div class="get-info"><a target="_blank" href="http://www.wpfastestcache.com/optimization/combine-js-css-files/"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></a></div>
							</div>



							<?php if(class_exists("WpFastestCachePowerfulHtml")){ ?>
								<?php if(method_exists("WpFastestCachePowerfulHtml", "combine_js_in_footer")){ ?>
									<div class="questionCon">
										<div class="question">Combine Js Plus</div>
										<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheCombineJsPowerFul; ?> id="wpFastestCacheCombineJsPowerFul" name="wpFastestCacheCombineJsPowerFul"><label for="wpFastestCacheCombineJsPowerFul">Reduce HTTP requests through combined js files</label> <b style="color:red;">(footer)</b></div>
									</div>
								<?php }else{ ?>
									<div class="questionCon update-needed">
										<div class="question">Combine Js Plus</div>
										<div class="inputCon"><input type="checkbox" id="wpFastestCacheCombineJsPowerFul"><label for="wpFastestCacheCombineJsPowerFul">Reduce HTTP requests through combined js files</label> <b style="color:red;">(footer)</b></div>
									</div>
								<?php } ?>
							<?php }else{ ?>
								<div class="questionCon disabled">
									<div class="question">Combine Js Plus</div>
									<div class="inputCon"><input type="checkbox" id="wpFastestCacheCombineJsPowerFul"><label for="wpFastestCacheCombineJsPowerFul">Reduce HTTP requests through combined js files</label> <b style="color:red;">(footer)</b></div>
								</div>
							<?php } ?>
							
							<div class="questionCon">
								<div class="question">Gzip</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheGzip; ?> id="wpFastestCacheGzip" name="wpFastestCacheGzip"><label for="wpFastestCacheGzip">Reduce the size of files sent from your server</label></div>
								<div class="get-info"><a target="_blank" href="http://www.wpfastestcache.com/optimization/enable-gzip-compression/"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></a></div>
							</div>

							<div class="questionCon">
								<div class="question">Browser Caching</div>
								<div class="inputCon"><input type="checkbox" <?php echo $wpFastestCacheLBC; ?> id="wpFastestCacheLBC" name="wpFastestCacheLBC"><label for="wpFastestCacheLBC">Reduce page load times for repeat visitors</label></div>
								<div class="get-info"><a target="_blank" href="http://www.wpfastestcache.com/optimization/leverage-browser-caching/"><img src="<?php echo plugins_url("wp-fastest-cache/images/info.png"); ?>" /></a></div>
							</div>

							<div class="questionCon">
								<div class="question">Language</div>
								<div class="inputCon">
									<select id="wpFastestCacheLanguage" name="wpFastestCacheLanguage">
									  <option value="de">Deutsch</option>
									  <option value="eng">English</option>
									  <option value="fr">Français</option>
									  <option value="es">Español</option>
									  <option value="it">Italiana</option>
									  <option value="ja">日本語</option>
									  <option value="pt">Português</option>
									  <option value="ro">Română</option>
									  <option value="ru">Русский</option>
									  <option value="sv">Svenska</option>
									  <option value="tr">Türkçe</option>
									  <!-- <option value="ukr">Українська</option> -->
									</select> 
								</div>
							</div>
							<div class="questionCon qsubmit">
								<div class="submit"><input type="submit" value="Submit" class="button-primary"></div>
							</div>
						</form>
				    </div>
				    <div class="tab2">
				    	<div id="container-show-hide-logs" style="display:none; float:right; padding-right:20px; cursor:pointer;">
				    		<span id="show-delete-log">Show Logs</span>
				    		<span id="hide-delete-log" style="display:none;">Hide Logs</span>
				    	</div>

				    	<?php 
			   				if(class_exists("WpFastestCacheStatics")){
				   				$cache_statics = new WpFastestCacheStatics();
				   				$cache_statics->statics();
			   				}
				   		?>

				    	<form method="post" name="wp_manager" class="delete-line">
				    		<input type="hidden" value="deleteCache" name="wpFastestCachePage">
				    		<div class="questionCon qsubmit left">
				    			<div class="submit"><input type="submit" value="Delete Cache" class="button-primary"></div>
				    		</div>
				    		<div class="questionCon right">
				    			<div style="padding-left:11px;">
				    			<label>You can delete all cache files</label><br>
				    			<label>Target folder</label> <b><?php echo $this->getWpContentDir(); ?>/cache/all</b>
				    			</div>
				    		</div>
				   		</form>
				   		<form method="post" name="wp_manager" class="delete-line" style="height: 120px;">
				    		<input type="hidden" value="deleteCssAndJsCache" name="wpFastestCachePage">
				    		<div class="questionCon qsubmit left">
				    			<div class="submit"><input type="submit" value="Delete Cache and Minified CSS/JS" class="button-primary"></div>
				    		</div>
				    		<div class="questionCon right">
				    			<div style="padding-left:11px;">
				    			<label>If you modify any css file, you have to delete minified css files</label><br>
				    			<label>All cache files will be removed as well</label><br>
				    			<label>Target folder</label> <b><?php echo $this->getWpContentDir(); ?>/cache/all</b><br>
				    			<!-- <label>Target folder</label> <b><?php echo $this->getWpContentDir(); ?>/cache/wpfc-mobile-cache</b><br> -->
				    			<label>Target folder</label> <b><?php echo $this->getWpContentDir(); ?>/cache/wpfc-minified</b>
				    			</div>
				    		</div>
				   		</form>
				   		<?php 
				   				if(class_exists("WpFastestCacheLogs")){
					   				$logs = new WpFastestCacheLogs("delete");
					   				$logs->printLogs();
				   				}
				   		?>
				    </div>
				    <div class="tab3">
				    	<form method="post" name="wp_manager" id="wpfc-schedule-panel">
				    		<input type="hidden" value="cacheTimeout" name="wpFastestCachePage">
				    		<div class="questionCon">
				    			<label style="padding-left:11px;">All cached files are deleted at the determinated time.</label>
				    		</div>
				    		<div class="questionCon" style="text-align: center;padding-top: 10px;">

				    			<span>First Job Time:</span>
				    			<select id="wpFastestCacheTimeOutHour" name="wpFastestCacheTimeOutHour">
				    				<option selected="" value="">Hour</option>
				    				<?php
				    					for($i = 0; $i < 24; $i++){
				    						echo "<option value='".$i."'>".$i."</option>";
				    					}

				    				?>
				    			</select>
				    			<select id="wpFastestCacheTimeOutMinute" name="wpFastestCacheTimeOutMinute">
				    				<option selected="" value="">Minute</option>
				    				<?php
				    					for($i = 0; $i < 60; $i++){
				    						echo "<option value='".$i."'>".$i."</option>";
				    					}

				    				?>
				    			</select>&ensp;&ensp;&ensp;
				    			<span>Frequency: </span>
								<select id="wpFastestCacheTimeOut" name="wpFastestCacheTimeOut">
									<?php

										$schedules = wp_get_schedules();
										$first = true;
										foreach ($schedules as $key => $value) {
											if(isset($value["wpfc"]) && $value["wpfc"]){
												if($first){
													echo "<option value=''>Choose One</option>";
													$first = false;
												}
												echo "<option value='{$key}'>{$value["display"]}</option>";
											}
										}

										// $arrSettings = array(array("value" => "", "text" => "Choose One"),
										// 					array("value" => "everyfiveminute", "text" => "Once Every 5 Minutes"),
										// 					array("value" => "everyfifteenminute", "text" => "Once Every 15 Minutes"),
										// 					array("value" => "twiceanhour", "text" => "Twice an Hour"),
										// 					array("value" => "onceanhour", "text" => "Once an Hour"),
										// 					array("value" => "everysixhours", "text" => "Once Every 6 Hours"),
										// 					array("value" => "onceaday", "text" => "Once a Day"),
										// 					array("value" => "weekly", "text" => "Once a Week"),
										// 					array("value" => "montly", "text" => "Once a Month'")
										// 				);

										// foreach ($arrSettings as $key => $value) {
										// 	//$checked = $value["value"] == $wpFastestCacheTimeOut ? 'selected=""' : "";
										// 	echo "<option value='{$value["value"]}'>{$value["text"]}</option>";
										// }
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
														<label id="deleteCron">[ x ]</label>
													</td>
												</tr>
												<?php if($this->cronJobSettings["period"] == "twicedaily"){ ?>
													<tr>
														<th scope="row" style="border-left:1px solid #DEDBD1;"><?php echo date("d-m-Y @ H:i", $this->cronJobSettings["time"] + 12*60*60); ?></th>
														<td style="border-right:1px solid #DEDBD1;"><?php echo $this->cronJobSettings["period"]; ?></td>
													</tr>
												<?php } ?>
											</tbody>
									</table>
					    		</div>
				    		<?php } ?>
				    		<div class="questionCon" style="text-align: center;padding-top: 10px;">
				    			<strong><label>Server time</label>: <label id="wpfc-server-time"><?php echo date("Y/m/d H:i:s"); ?></label></strong>
				    		</div>
				    		<div class="questionCon qsubmit">
				    			<div class="submit"><input type="submit" value="Submit" class="button-primary"></div>
				    		</div>
				   		</form>
				    </div>
				    <?php if(class_exists("WpFastestCacheImageOptimisation")){ ?>
					    <div class="tab4">
					    	<h2 style="padding-left:20px;padding-bottom:10px;">Optimize Image Tool</h2>

					    		<?php $xxx = new WpFastestCacheImageOptimisation(); ?>
					    		<?php $xxx->statics(); ?>
						    	<?php $xxx->imageList(); ?>
					    </div>
				    <?php }else{ ?>
						<div class="tab4" style="">
							<div style="z-index:9999;width: 160px; height: 60px; position: absolute; margin-left: 254px; margin-top: 74px; color: white;">
								<div style="font-family:sans-serif;font-size:13px;text-align: center; border-radius: 5px; float: left; background-color: rgb(51, 51, 51); color: white; width: 147px; padding: 20px 50px;">
									<label>Only available in Premium version</label>
								</div>
							</div>
							<h2 style="opacity: 0.3;padding-left:20px;padding-bottom:10px;">Optimize Image Tool</h2>
							<div id="container-show-hide-image-list" style="opacity: 0.3;float: right; padding-right: 20px; cursor: pointer;">
								<span id="show-image-list">Show Images</span>
								<span id="hide-image-list" style="display:none;">Hide Images</span>
							</div>
							<div style="opacity: 0.3;width:100%;float:left;" id="wpfc-image-static-panel">
								<div style="float: left; width: 100%;">
									<div style="float:left;padding-left: 22px;padding-right:15px;">
										<div style="display: inline-block;">
											<div style="width: 150px; height: 150px; position: relative; border-top-left-radius: 150px; border-top-right-radius: 150px; border-bottom-right-radius: 150px; border-bottom-left-radius: 150px; background-color: #ffcc00;">
												

												<div style="position: absolute; top: 0px; left: 0px; width: 150px; height: 150px; border-top-left-radius: 150px; border-top-right-radius: 150px; border-bottom-right-radius: 150px; border-bottom-left-radius: 150px; clip: rect(0px 150px 150px 75px);">
													<div style="position: absolute; top: 0px; left: 0px; width: 150px; height: 150px; border-radius: 150px; clip: rect(0px, 75px, 150px, 0px); transform: rotate(109.62deg); background-color: rgb(255, 165, 0); border-spacing: 109.62px;" id="wpfc-pie-chart-little"></div>
												</div>


												<div style="display:none;position: absolute; top: 0px; left: 0px; width: 150px; height: 150px; border-top-left-radius: 150px; border-top-right-radius: 150px; border-bottom-right-radius: 150px; border-bottom-left-radius: 150px; clip: rect(0px 150px 150px 25px); -webkit-transform: rotate(0deg); transform: rotate(0deg);" id="wpfc-pie-chart-big-container-first">
													<div style="position: absolute; top: 0px; left: 0px; width: 150px; height: 150px; border-top-left-radius: 150px; border-top-right-radius: 150px; border-bottom-right-radius: 150px; border-bottom-left-radius: 150px; clip: rect(0px 75px 150px 0px); -webkit-transform: rotate(180deg); transform: rotate(180deg); background-color: #FFA500;"></div>
												</div>
												<div style="display:none;position: absolute; top: 0px; left: 0px; width: 150px; height: 150px; border-top-left-radius: 150px; border-top-right-radius: 150px; border-bottom-right-radius: 150px; border-bottom-left-radius: 150px; clip: rect(0px 150px 150px 75px); -webkit-transform: rotate(180deg); transform: rotate(180deg);" id="wpfc-pie-chart-big-container-second-right">
													<div style="position: absolute; top: 0px; left: 0px; width: 150px; height: 150px; border-top-left-radius: 150px; border-top-right-radius: 150px; border-bottom-right-radius: 150px; border-bottom-left-radius: 150px; clip: rect(0px 75px 150px 0px); -webkit-transform: rotate(90deg); transform: rotate(90deg); background-color: #FFA500;" id="wpfc-pie-chart-big-container-second-left"></div>
												</div>

											</div>
											<div style="width: 114px;height: 114px;margin-top: -133px;background-color: white;margin-left: 18px;position: absolute;border-radius: 150px;">
												<p style="text-align:center;margin:27px 0 0 0;color: black;">Succeed</p>
												<p style="text-align: center; font-size: 18px; font-weight: bold; font-family: verdana; margin: -2px 0px 0px; color: black;" id="wpfc-optimized-statics-percent" class="">30.45</p>
												<p style="text-align:center;margin:0;color: black;">%</p>
											</div>
										</div>
									</div>
									<div style="float: left;padding-left:12px;" id="wpfc-statics-right">
										<ul style="list-style: none outside none;float: left;">
											<li>
												<div style="background-color: rgb(29, 107, 157);width:15px;height:15px;float:left;margin-top:4px;border-radius:5px;"></div>
												<div style="float:left;padding-left:6px;">All JPEG</div>
												<div style="font-size: 14px; font-weight: bold; color: black; float: left; width: 65%; margin-left: 5px;" id="wpfc-optimized-statics-total_image_number" class="">7196</div>
											</li>
											<li>
												<div style="background-color: rgb(29, 107, 157);width:15px;height:15px;float:left;margin-top:4px;border-radius:5px;"></div>
												<div style="float:left;padding-left:6px;">Pending</div>
												<div style="font-size: 14px; font-weight: bold; color: black; float: left; width: 65%; margin-left: 5px;" id="wpfc-optimized-statics-pending" class="">5002</div>
											</li>
											<li>
												<div style="background-color: #FF0000;width:15px;height:15px;float:left;margin-top:4px;border-radius:5px;"></div>
												<div style="float:left;padding-left:6px;">Errors</div>
												<div style="font-size: 14px; font-weight: bold; color: black; float: left; width: 65%; margin-left: 5px;" id="wpfc-optimized-statics-error" class="">3</div>
											</li>
										</ul>
										<ul style="list-style: none outside none;float: left;">
											<li>
												<div style="background-color: rgb(61, 207, 60);width:15px;height:15px;float:left;margin-top:4px;border-radius:5px;"></div>
												<div style="float:left;padding-left:6px;"><span>Optimized Images</span></div>
												<div style="font-size: 14px; font-weight: bold; color: black; float: left; width: 65%; margin-left: 5px;" id="wpfc-optimized-statics-optimized" class="">2191</div>
											</li>

											<li>
												<div style="background-color: rgb(61, 207, 60);width:15px;height:15px;float:left;margin-top:4px;border-radius:5px;"></div>
												<div style="float:left;padding-left:6px;"><span>Total Reduction</span></div>
												<div style="font-size: 14px; font-weight: bold; color: black; float: left; width: 80%; margin-left: 5px;" id="wpfc-optimized-statics-reduction" class="">78400.897</div>
											</li>
											<li></li>
										</ul>

										<ul style="list-style: none outside none;float: left;">
											<li>
												<h1 style="margin-top:0;float:left;">Credit: <span style="display: inline-block; height: 16px; width: auto;min-width:25px;" id="wpfc-optimized-statics-credit" class="">9910</span></h1>
												<span id="buy-image-credit">More</span>
											</li>
											<li>
												<input type="submit" class="button-primary" value="Optimize All JPEG" id="wpfc-optimize-images-button" style="width:100%;height:110px;">
											</li>
										</ul>
									</div>
								</div>
							</div>
						</div>
				    <?php } ?>
				    <div class="tab5">
				    	<?php
				    		if(!get_option("WpFc_api_key")){
				    			update_option("WpFc_api_key", md5(microtime(true)));
				    		}

				    		if(!defined('WPFC_API_KEY')){ // for download_error.php
				    			define("WPFC_API_KEY", get_option("WpFc_api_key"));
				    		}
				    	?>
				    	<div id="wpfc-premium-container">
				    		<div class="wpfc-premium-step">
				    			<div class="wpfc-premium-step-header">
				    				<label>Discover Features</label>
				    			</div>
				    			<div class="wpfc-premium-step-content">
				    				In the premium version there are some new features which speed up the sites more.
				    			</div>
				    			<div class="wpfc-premium-step-image">
				    				<img src="<?php echo plugins_url("wp-fastest-cache/images/rocket.png"); ?>" />
				    			</div>
				    			<div class="wpfc-premium-step-footer">
				    				<h1 id="new-features-h1">New Features</h1>
				    				<ul>
				    					<li><a target="_blank" style="text-decoration: none;color: #444;" href="http://www.wpfastestcache.com/premium/image-optimization/">Image Optimization</a></li>
				    					<li><a target="_blank" style="text-decoration: none;color: #444;" href="http://www.wpfastestcache.com/premium/mobile-cache/">Mobile Cache</a></li>
				    					<li><a target="_blank" style="text-decoration: none;color: #444;" href="http://www.wpfastestcache.com/premium/minify-html-plus/">Minify HTML Plus</a></li>
				    					<li><a target="_blank" style="text-decoration: none;color: #444;" href="http://www.wpfastestcache.com/premium/combine-js-plus/">Combine Js Plus</a></li>
				    					<li><a target="_blank" style="text-decoration: none;color: #444;" href="http://www.wpfastestcache.com/premium/minify-js/">Minify Js</a></li>
				    					<li><a target="_blank" style="text-decoration: none;color: #444;" href="http://www.wpfastestcache.com/premium/delete-cache-logs/">Delete Cache Logs</a></li>
				    					<li><a target="_blank" style="text-decoration: none;color: #444;" href="http://www.wpfastestcache.com/premium/cache-statics/">Cache Statics</a></li>
				    				</ul>
				    			</div>
				    		</div>
				    		<div class="wpfc-premium-step">
				    			<div class="wpfc-premium-step-header">
				    				<label>Checkout</label>
				    			</div>
				    			<div class="wpfc-premium-step-content">
				    				You need to pay before downloading the premium version.
				    			</div>
				    			<div class="wpfc-premium-step-image">
				    				<img width="140px" height="140px" src="<?php echo plugins_url("wp-fastest-cache/images/wallet.png"); ?>" />
				    			</div>
				    			<div class="wpfc-premium-step-footer">
				    				<h1 style="float:left;" id="just-h1">Just</h1><h1>&nbsp;$<span id="wpfc-premium-price">39.99</span></h1>
				    				<p>The download button will be available after paid. You can buy the premium version now.</p>
				    				
				    				<?php if(!preg_match("/\.ir/i", $_SERVER["HTTP_HOST"])){ ?>
					    				<?php if(class_exists("WpFastestCachePowerfulHtml")){ ?>
						    					<button id="wpfc-buy-premium-button" type="submit" class="wpfc-btn primaryDisableCta" style="width:200px;">
							    					<span>Purchased</span>
							    				</button>
						    				<?php }else{ ?>
							    				<form action="http://api.wpfastestcache.net/paypal/buypremium/" method="post">
							    					<input type="hidden" name="hostname" value="<?php echo str_replace(array("http://", "www."), "", $_SERVER["HTTP_HOST"]); ?>">
								    				<button id="wpfc-buy-premium-button" type="submit" class="wpfc-btn primaryCta" style="width:200px;">
								    					<span>Buy</span>
								    				</button>
							    				</form>
						    			<?php } ?>
					    			<?php } ?>


				    			</div>
				    		</div>
				    		<div class="wpfc-premium-step">
				    			<div class="wpfc-premium-step-header">
				    				<label>Download & Update</label>
				    			</div>
				    			<div class="wpfc-premium-step-content">
				    				You can download and update the premium when you want if you paid.
				    			</div>
				    			<div class="wpfc-premium-step-image" style="">
				    				<img src="<?php echo plugins_url("wp-fastest-cache/images/download-128x128.png"); ?>" />
				    			</div>
				    			<div class="wpfc-premium-step-footer">
				    				<h1 id="get-now-h1">Get It Now!</h1>
				    				<p>Please don't delete the free version. Premium version works with the free version.</p>


				    				<?php if(class_exists("WpFastestCachePowerfulHtml")){ ?>
					    				<button id="wpfc-update-premium-button" class="wpfc-btn primaryDisableCta" style="width:200px;">
					    					<span data-type="update">Update</span>
					    				</button>
					    				<script type="text/javascript">
					    					jQuery(document).ready(function(){

				    							if(jQuery(".tab5").is(":visible")){
										    		wpfc_premium_page();
									    		}

									    		jQuery("#wpfc-premium").change(function(e){
									    			wpfc_premium_page();
									    		});

									    		function wpfc_premium_page(){
										    		jQuery(document).ready(function(){
							    						if(typeof Wpfc_Premium == "undefined"){
							    							jQuery("#wpfc-update-premium-button").attr("class", "wpfc-btn primaryCta");

							    							jQuery("#wpfc-update-premium-button").click(function(){
							    								jQuery("#revert-loader-toolbar").show();
							    								
																jQuery.get('<?php echo plugins_url('wp-fastest-cache/templates'); ?>' + "/update_error.php?error_message=" + "You use old version of premium. " + "&apikey=" + '<?php echo get_option("WpFc_api_key"); ?>', function( data ) {
																	jQuery("body").append(data);
																	Wpfc_Dialog.dialog("wpfc-modal-updateerror");
																	jQuery("#revert-loader-toolbar").hide();
																});
							    							});
							    						}else{
							    							Wpfc_Premium.check_update("<?php echo $this->get_premium_version(); ?>", '<?php echo "http://api.wpfastestcache.net/premium/newdownload/".str_replace(array("http://", "www."), "", $_SERVER["HTTP_HOST"])."/".get_option("WpFc_api_key"); ?>', '<?php echo plugins_url('wp-fastest-cache/templates'); ?>');
							    						}
										    		});
									    		}

					    					});
					    				</script>
					    				<script type="text/javascript">

					    				</script>
				    				<?php }else{ ?>
					    				<button class="wpfc-btn primaryCta" id="wpfc-download-premium-button" class="wpfc-btn primaryDisableCta" style="width:200px;">
					    					<span data-type="download">Download</span>
					    				</button>
					    				<script type="text/javascript">
					    					jQuery("#wpfc-download-premium-button").click(function(){
					    						jQuery("#revert-loader-toolbar").show();
						    					jQuery.get("<?php echo plugins_url('wp-fastest-cache/templates'); ?>/download.html", function( data ) {
						    						var wpfc_api_url = '<?php echo "http://api.wpfastestcache.net/premium/newdownload/".str_replace(array("http://", "www."), "", $_SERVER["HTTP_HOST"])."/".get_option("WpFc_api_key"); ?>';
						    						jQuery("body").append(data);
						    						jQuery("#wpfc-download-now").attr("href", wpfc_api_url);
						    						Wpfc_Dialog.dialog("wpfc-modal-downloaderror");
						    						jQuery("#revert-loader-toolbar").hide();
						    					});
					    					});
					    				</script>
				    				<?php } ?>
				    				<!--
				    				<button class="wpfc-btn primaryNegativeCta" style="width:200px;">
				    					<span>Update</span>
				    					<label>(v 1.0)</label>
				    				</button>
				    			-->
				    			</div>
				    		</div>
				    	</div>
				    </div>
				    <div class="tab6" style="padding-left:20px;">
				    	<h2 style="padding-bottom:10px;">Exclude Pages</h2>
				    	<div class="questionCon" style="padding-bottom:10px;">
				    			<label>You can stop to create cache for specific pages</label><label style="padding-bottom:10px;padding-left:5px;">[<a target="_blank" href="http://www.wpfastestcache.com/features/exclude-page/">How to use this feature</a>]</label>
				    	</div>
				    	<form method="post" name="wp_manager">
				    		<input type="hidden" value="exclude" name="wpFastestCachePage">
				    		<div class="wpfc-exclude-rule-container">
					    		<div class="wpfc-exclude-rule-line">
					    			<div class="wpfc-exclude-rule-line-left">
							    		<select name="wpfc-exclude-rule-prefix-1">
							    				<option selected="" value=""></option>
							    				<option value="startwith">Start With</option>
							    				<option value="contain">Contain</option>
							    				<option value="exact">Exact</option>
							    		</select>
					    			</div>
					    			<div class="wpfc-exclude-rule-line-middle">
						    			<input type="text" name="wpfc-exclude-rule-content-1" style="width:390px;">
					    			</div>
					    			<div class="wpfc-exclude-rule-line-add">
					    				<img src="<?php echo plugins_url("wp-fastest-cache/images/add.png"); ?>">
					    			</div>
					    			<div class="wpfc-exclude-rule-line-delete">
					    				<img src="<?php echo plugins_url("wp-fastest-cache/images/delete.png"); ?>">
					    			</div>
					    		</div>
				    		</div>
				    		<div class="questionCon qsubmit">
								<div class="submit"><input type="submit" class="button-primary" value="Submit"></div>
							</div>
				    	</form>
				    	<script type="text/javascript">
				    		var WpFcExcludePages = {
				    			rules: [],
				    			init: function(rules){
				    				this.rules = rules;
				    				this.update_rules();
				    				this.click_event_for_add_button();
				    			},
				    			update_rules: function(){
				    				var self = this;

				    				if(typeof this.rules != "undefined" && this.rules.length > 0){
				    					jQuery.each(self.rules, function(i, e){
				    						if(i > 0){
				    							self.add_line(i + 1);
				    						}

			    							jQuery("input[name='wpfc-exclude-rule-content-" + (i+1) + "']").val(e.content);
			    							jQuery("select[name='wpfc-exclude-rule-prefix-" + (i+1) + "']").val(e.prefix);
				    					});
				    				}
				    			},
				    			add_line: function(number){
				    				var line = jQuery(".wpfc-exclude-rule-line").first().closest(".wpfc-exclude-rule-line").clone();
			    					line.find(".wpfc-exclude-rule-line-add").remove();
			    					line.find(".wpfc-exclude-rule-line-delete").show();

			    					line.find("select").attr("name", "wpfc-exclude-rule-prefix-" + number);
			    					line.find("input").attr("name", "wpfc-exclude-rule-content-" + number);
			    					line.find("input").val("");

			    					line.find(".wpfc-exclude-rule-line-delete").click(function(e){
			    						jQuery(e.target).closest(".wpfc-exclude-rule-line").remove();
			    					});

			    					jQuery(".wpfc-exclude-rule-container").append(line);
				    			},
				    			click_event_for_add_button: function(){
				    				var self = this;
				    				var line_length = 0;

				    				jQuery(".wpfc-exclude-rule-line-add").click(function(e){
				    					line_length = jQuery("div.wpfc-exclude-rule-line").length;
				    					self.add_line(line_length + 1);
				    				});
				    			}
				    		};
					    	<?php 
					    		if($rules_json = get_option("WpFastestCacheExclude")){
					    			?>WpFcExcludePages.init(<?php echo $rules_json; ?>);<?php
					    		}else{
					    			?>WpFcExcludePages.init();<?php
					    		}
					    	?>
				    	</script>
				    </div>

				    <div class="tab7" style="padding-left:20px;">
				    	<h2 style="padding-bottom:10px;">CDN Settings</h2>
				    	<div>
				    		<div class="integration-page" style="display: block;width:98%;float:left;">

 				    			<div wpfc-cdn-name="keycdn" class="int-item">
				    				<img src="data:image/png;base64,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"/>
				    				<div class="app">
				    					<div style="font-weight:bold;font-size:14px;">CDN by KeyCDN</div>
				    					<p>Our global delivery network instantly speed up your websites, online games or live streams.</p>
				    				</div>
				    				<div class="meta">
				    					<span class="connected"></span>
				    				</div>
				    			</div>


				    			<div wpfc-cdn-name="amazonaws" class="int-item">
				    				<img src="data:image/png;base64,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"/>
				    				<div class="app">
				    					<div style="font-weight:bold;font-size:14px;">CDN by Cloudfront</div>
				    					<p>Amazon CloudFront CDN gives businesses and developers a fast content delivery network.</p>
				    				</div>
				    				<div class="meta">
				    					<span class="connected"></span>
				    				</div>
				    			</div>

				    			<div wpfc-cdn-name="maxcdn" class="int-item">
				    				<img src="data:image/png;base64,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"/>
				    				<div class="app">
				    					<div style="font-weight:bold;font-size:14px;">CDN by MaxCDN</div>
				    					<p>Experts in Content Delivery Network Services</p>
				    				</div>
				    				<div class="meta">
				    					<span class="connected"></span>
				    				</div>
				    			</div>


<!-- 				    			<div wpfc-cdn-name="cdn77" class="int-item">
				    				<img src="data:image/png;base64,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"/>
				    				<div class="app">
				    					<div style="font-weight:bold;font-size:14px;">CDN by CDN77</div>
				    					<p>Website speed acceleration with CDN77. 28+ PoPs, Pay-as-you-go prices, no commitments.</p>
				    				</div>
				    				<div class="meta">
				    					<span class="connected"></span>
				    				</div>
				    			</div> -->


<!-- 				    			<div wpfc-cdn-name="incapsula" class="int-item">
				    				<img src="data:image/png;base64,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"/>
				    				<div class="app">
				    					<div style="font-weight:bold;font-size:14px;">CDN by Incapsula</div>
				    					<p>Security CDN that makes any website safer, faster and more reliable.</p>
				    				</div>
				    				<div class="meta">
				    					<span class="connected"></span>
				    				</div>
				    			</div> -->





				    		</div>
				    	</div>
				    	<script type="text/javascript">
				    		(function() {
					    		<?php
					    			$cdn_values = get_option("WpFastestCacheCDN");
					    			if($cdn_values){
					    				$cdn_values_arr = json_decode($cdn_values);
					    				?>
					    					jQuery("div[wpfc-cdn-name='<?php echo $cdn_values_arr->id;?>']").find("span.connected").text("Connected");
					    				<?php
					    			}
					    		?>
				    			jQuery(".int-item").click(function(e){
					    			jQuery.ajax({
										type: 'GET', 
										url: self.ajax_url,
										cache: false,
										data : {"action": "wpfc_cdn_options_ajax_request"},
										dataType : "json",
										success: function(data){
						    				WpfcCDN.init({"id" : jQuery(e.currentTarget).attr("wpfc-cdn-name"),
						    					"template_main_url" : "<?php echo plugins_url('wp-fastest-cache/templates/cdn'); ?>",
						    					"values" : data
						    				});
										}
									});
				    			});
				    		})();
				    	</script>
				    </div>












			</div>

			<div class="omni_admin_sidebar">
				<div class="omni_admin_sidebar_section" id="vote-us">
					<h3 style="color: antiquewhite;">Support Us</h3>
					<ul>
						<li><label>If you like it, Please vote and support us.</label></li>
					</ul>
					<script>
						jQuery("#vote-us").click(function(){
							var win=window.open("http://wordpress.org/support/view/plugin-reviews/wp-fastest-cache?free-counter?rate=5#postform", '_blank');
							win.focus();
						});
					</script>
				</div>
				<div class="omni_admin_sidebar_section">
					<?php if(class_exists("WpFastestCachePowerfulHtml")){ ?>
						<h3>Premium Support</h3>
						<ul>
							<li><label>You can send an email</label> <a target="_blank"><label>fastestcache@gmail.com</label></a></li>
						</ul>
					<?php }else{ ?>
						<h3>Having Issues?</h3>
						<ul>
							<li><label>You can create a ticket</label> <a target="_blank" href="http://wordpress.org/support/plugin/wp-fastest-cache"><label>WordPress support forum</label></a></li>
							<?php
							if(isset($this->options->wpFastestCacheLanguage) && $this->options->wpFastestCacheLanguage == "tr"){
								?>
								<li><label>R10 Üzerinden Sorabilirsiniz</label> <a target="_blank" href="http://www.r10.net/wordpress/1096868-wp-fastest-cache-wp-en-hizli-ve-en-basit-cache-sistemi.html"><label>R10.net destek başlığı</label></a></li>
								<?php
							}
							?>
						</ul>
					<?php } ?>
				</div>
			</div>

			<div id="wpfc-plugin-setup-warning" class="mainContent" style="display:none;border:1px solid black">
			        <div class="pageView"style="display: block;">
			            <div class="fakeHeader">
			                <h3 class="title-h3">Error Occured</h3>
			            </div>
			            <div class="fieldRow active">

			            </div>
			            <div class="pagination">
			                <div class="next" style="text-align: center;float: none;">
			                    <button class="wpfc-btn primaryCta" id="wpfc-read-tutorial">
			                        <span class="label">Continue</span>
			                    </button>
			                </div>
			            </div>
			        </div>
			</div>

			<?php if(!class_exists("WpFastestCacheImageOptimisation")){ ?>
				<div id="wpfc-premium-tooltip" style="display:none;width: 160px; height: 60px; position: absolute; margin-left: 354px; margin-top: 112px; color: white;">
					<div style="float:left;width:13px;">
						<div style="width: 0px; height: 0px; border-top: 6px solid transparent; border-right: 6px solid #333333; border-bottom: 6px solid transparent; float: right; margin-right: 0px; margin-top: 25px;"></div>
					</div>
					<div style="font-family:sans-serif;font-size:13px;text-align: center; border-radius: 5px; float: left; background-color: rgb(51, 51, 51); color: white; width: 147px; padding: 10px 0px;">
						<label>Only available in Premium version</label>
					</div>
				</div>

				<script type="text/javascript">
					jQuery("div.questionCon.disabled").click(function(e){
						if(typeof window.wpfc.tooltip != "undefined"){
							clearTimeout(window.wpfc.tooltip);
						}

						var inputCon = jQuery(e.currentTarget).find(".inputCon");
						var left = 30;

						jQuery(e.currentTarget).children().each(function(i, child){
							left = left + jQuery(child).width();
						});

						jQuery("#wpfc-premium-tooltip").css({"margin-left" : left + "px", "margin-top" : (jQuery(e.currentTarget).offset().top - jQuery(".tab1").offset().top + 25) + "px"});
						jQuery("#wpfc-premium-tooltip").fadeIn( "slow", function() {
							window.wpfc.tooltip = setTimeout(function(){ jQuery("#wpfc-premium-tooltip").hide(); }, 1000);
						});
						return false;
					});
				</script>
			<?php }else{ ?>
				<script type="text/javascript">
					jQuery(".update-needed").click(function(e){
						jQuery("#revert-loader-toolbar").show();
						jQuery.get('<?php echo plugins_url("wp-fastest-cache/templates/update_now.html"); ?>', function( data ) {
							jQuery("#revert-loader-toolbar").hide();
							jQuery("body").append(data);
							Wpfc_Dialog.dialog("wpfc-modal-updatenow");
						});
						return false;
					});
				</script>
			<?php } ?>
			<script>Wpfclang.init("<?php echo $wpFastestCacheLanguage; ?>");</script>
			<?php
			if(isset($_SERVER["SERVER_SOFTWARE"]) && $_SERVER["SERVER_SOFTWARE"] && !preg_match("/iis/i", $_SERVER["SERVER_SOFTWARE"])){
				$this->check_htaccess();
			}
		}
	}
?>