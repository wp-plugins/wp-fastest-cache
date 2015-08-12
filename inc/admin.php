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
			}else if($this->isPluginActive('speed-booster-pack/speed-booster-pack.php')){
				return array("Speed Booster Pack needs to be deactive<br>", "error");
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
									  <option value="pl">Polski</option>
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
				    				<img src="data:image/png;base64,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"/>
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
				    				<img width="140px" height="140px" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAQAAAAEACAYAAABccqhmAAAACXBIWXMAAAsTAAALEwEAmpwYAAAKT2lDQ1BQaG90b3Nob3AgSUNDIHByb2ZpbGUAAHjanVNnVFPpFj333vRCS4iAlEtvUhUIIFJCi4AUkSYqIQkQSoghodkVUcERRUUEG8igiAOOjoCMFVEsDIoK2AfkIaKOg6OIisr74Xuja9a89+bN/rXXPues852zzwfACAyWSDNRNYAMqUIeEeCDx8TG4eQuQIEKJHAAEAizZCFz/SMBAPh+PDwrIsAHvgABeNMLCADATZvAMByH/w/qQplcAYCEAcB0kThLCIAUAEB6jkKmAEBGAYCdmCZTAKAEAGDLY2LjAFAtAGAnf+bTAICd+Jl7AQBblCEVAaCRACATZYhEAGg7AKzPVopFAFgwABRmS8Q5ANgtADBJV2ZIALC3AMDOEAuyAAgMADBRiIUpAAR7AGDIIyN4AISZABRG8lc88SuuEOcqAAB4mbI8uSQ5RYFbCC1xB1dXLh4ozkkXKxQ2YQJhmkAuwnmZGTKBNA/g88wAAKCRFRHgg/P9eM4Ors7ONo62Dl8t6r8G/yJiYuP+5c+rcEAAAOF0ftH+LC+zGoA7BoBt/qIl7gRoXgugdfeLZrIPQLUAoOnaV/Nw+H48PEWhkLnZ2eXk5NhKxEJbYcpXff5nwl/AV/1s+X48/Pf14L7iJIEyXYFHBPjgwsz0TKUcz5IJhGLc5o9H/LcL//wd0yLESWK5WCoU41EScY5EmozzMqUiiUKSKcUl0v9k4t8s+wM+3zUAsGo+AXuRLahdYwP2SycQWHTA4vcAAPK7b8HUKAgDgGiD4c93/+8//UegJQCAZkmScQAAXkQkLlTKsz/HCAAARKCBKrBBG/TBGCzABhzBBdzBC/xgNoRCJMTCQhBCCmSAHHJgKayCQiiGzbAdKmAv1EAdNMBRaIaTcA4uwlW4Dj1wD/phCJ7BKLyBCQRByAgTYSHaiAFiilgjjggXmYX4IcFIBBKLJCDJiBRRIkuRNUgxUopUIFVIHfI9cgI5h1xGupE7yAAygvyGvEcxlIGyUT3UDLVDuag3GoRGogvQZHQxmo8WoJvQcrQaPYw2oefQq2gP2o8+Q8cwwOgYBzPEbDAuxsNCsTgsCZNjy7EirAyrxhqwVqwDu4n1Y8+xdwQSgUXACTYEd0IgYR5BSFhMWE7YSKggHCQ0EdoJNwkDhFHCJyKTqEu0JroR+cQYYjIxh1hILCPWEo8TLxB7iEPENyQSiUMyJ7mQAkmxpFTSEtJG0m5SI+ksqZs0SBojk8naZGuyBzmULCAryIXkneTD5DPkG+Qh8lsKnWJAcaT4U+IoUspqShnlEOU05QZlmDJBVaOaUt2ooVQRNY9aQq2htlKvUYeoEzR1mjnNgxZJS6WtopXTGmgXaPdpr+h0uhHdlR5Ol9BX0svpR+iX6AP0dwwNhhWDx4hnKBmbGAcYZxl3GK+YTKYZ04sZx1QwNzHrmOeZD5lvVVgqtip8FZHKCpVKlSaVGyovVKmqpqreqgtV81XLVI+pXlN9rkZVM1PjqQnUlqtVqp1Q61MbU2epO6iHqmeob1Q/pH5Z/YkGWcNMw09DpFGgsV/jvMYgC2MZs3gsIWsNq4Z1gTXEJrHN2Xx2KruY/R27iz2qqaE5QzNKM1ezUvOUZj8H45hx+Jx0TgnnKKeX836K3hTvKeIpG6Y0TLkxZVxrqpaXllirSKtRq0frvTau7aedpr1Fu1n7gQ5Bx0onXCdHZ4/OBZ3nU9lT3acKpxZNPTr1ri6qa6UbobtEd79up+6Ynr5egJ5Mb6feeb3n+hx9L/1U/W36p/VHDFgGswwkBtsMzhg8xTVxbzwdL8fb8VFDXcNAQ6VhlWGX4YSRudE8o9VGjUYPjGnGXOMk423GbcajJgYmISZLTepN7ppSTbmmKaY7TDtMx83MzaLN1pk1mz0x1zLnm+eb15vft2BaeFostqi2uGVJsuRaplnutrxuhVo5WaVYVVpds0atna0l1rutu6cRp7lOk06rntZnw7Dxtsm2qbcZsOXYBtuutm22fWFnYhdnt8Wuw+6TvZN9un2N/T0HDYfZDqsdWh1+c7RyFDpWOt6azpzuP33F9JbpL2dYzxDP2DPjthPLKcRpnVOb00dnF2e5c4PziIuJS4LLLpc+Lpsbxt3IveRKdPVxXeF60vWdm7Obwu2o26/uNu5p7ofcn8w0nymeWTNz0MPIQ+BR5dE/C5+VMGvfrH5PQ0+BZ7XnIy9jL5FXrdewt6V3qvdh7xc+9j5yn+M+4zw33jLeWV/MN8C3yLfLT8Nvnl+F30N/I/9k/3r/0QCngCUBZwOJgUGBWwL7+Hp8Ib+OPzrbZfay2e1BjKC5QRVBj4KtguXBrSFoyOyQrSH355jOkc5pDoVQfujW0Adh5mGLw34MJ4WHhVeGP45wiFga0TGXNXfR3ENz30T6RJZE3ptnMU85ry1KNSo+qi5qPNo3ujS6P8YuZlnM1VidWElsSxw5LiquNm5svt/87fOH4p3iC+N7F5gvyF1weaHOwvSFpxapLhIsOpZATIhOOJTwQRAqqBaMJfITdyWOCnnCHcJnIi/RNtGI2ENcKh5O8kgqTXqS7JG8NXkkxTOlLOW5hCepkLxMDUzdmzqeFpp2IG0yPTq9MYOSkZBxQqohTZO2Z+pn5mZ2y6xlhbL+xW6Lty8elQfJa7OQrAVZLQq2QqboVFoo1yoHsmdlV2a/zYnKOZarnivN7cyzytuQN5zvn//tEsIS4ZK2pYZLVy0dWOa9rGo5sjxxedsK4xUFK4ZWBqw8uIq2Km3VT6vtV5eufr0mek1rgV7ByoLBtQFr6wtVCuWFfevc1+1dT1gvWd+1YfqGnRs+FYmKrhTbF5cVf9go3HjlG4dvyr+Z3JS0qavEuWTPZtJm6ebeLZ5bDpaql+aXDm4N2dq0Dd9WtO319kXbL5fNKNu7g7ZDuaO/PLi8ZafJzs07P1SkVPRU+lQ27tLdtWHX+G7R7ht7vPY07NXbW7z3/T7JvttVAVVN1WbVZftJ+7P3P66Jqun4lvttXa1ObXHtxwPSA/0HIw6217nU1R3SPVRSj9Yr60cOxx++/p3vdy0NNg1VjZzG4iNwRHnk6fcJ3/ceDTradox7rOEH0x92HWcdL2pCmvKaRptTmvtbYlu6T8w+0dbq3nr8R9sfD5w0PFl5SvNUyWna6YLTk2fyz4ydlZ19fi753GDborZ752PO32oPb++6EHTh0kX/i+c7vDvOXPK4dPKy2+UTV7hXmq86X23qdOo8/pPTT8e7nLuarrlca7nuer21e2b36RueN87d9L158Rb/1tWeOT3dvfN6b/fF9/XfFt1+cif9zsu72Xcn7q28T7xf9EDtQdlD3YfVP1v+3Njv3H9qwHeg89HcR/cGhYPP/pH1jw9DBY+Zj8uGDYbrnjg+OTniP3L96fynQ89kzyaeF/6i/suuFxYvfvjV69fO0ZjRoZfyl5O/bXyl/erA6xmv28bCxh6+yXgzMV70VvvtwXfcdx3vo98PT+R8IH8o/2j5sfVT0Kf7kxmTk/8EA5jz/GMzLdsAAAAgY0hSTQAAeiUAAICDAAD5/wAAgOkAAHUwAADqYAAAOpgAABdvkl/FRgABOnhJREFUeNrsvXeYJGl15vsLn96V97697x5vGcPAgEBCCKQFWXblV7oSMlcrN5IWCWQBraSVECCsEJ7BjWO86Z72XW2ry3SX91npM8PePyIyKqu6Zhi4Mpj6nie7q7LSREbGOd8573nPewTHcdhcm2tzfX8ucfMUbK7NtekANtfm2lybDmBzba7NtekANtfm2lybDmBzba7NtekANtfm2lybDmBzba7N9b22ZIBSaXTzTGyuNUuSJHK5PD//839ELpdHkqRX9Lwqr0QURSzLQpYl/vzPf5MtW7rRdeM/+2N0Aa8Hrgd+Bch8v3+vwWDvtQ5gc22u76G1FbgPuBe4A4h6938ceHTz9LDpADbX91awAuwEXu0Z/i1AcIPH3bvpADYdwOb63lgqsNcz+NcCh7z7Xm7d4zkLa/P0bTqAzfXdtwKeod/n5fW7Nrp+RVFEliQkSaSiG9i2Xf3TLmA7cHbzVG46gM313bFCwE3A64D7gQE2qFyJoogsS0iiSL5Y4sLwVc4Nj3HXjQeoS8awLBtAAe7adACbDmBz/fuvXcDbgTpgEPgosPJtvlYMuM3b5e8Del7qgaIgYDsOK9k849NznDg3xLHBi5TKOrliiWQ0wv133UypVK4+5T7g/Ztf16YD2Fz/fuse4JNAQ7UEKAjCTwiC8CZg/BW+Rgq428vn7wXaX+qBgiAgiiKK7F66V6dmuTByhZVsDkWW2NbbhaYEmF1c4tzwFe67/Ybap98INALzm1/bpgPYXK9gfRO9iHuATwPJ2vq/IIoHcZwv4iLziy/x3GZvR361Z/xNGxm7IAgIgANomkqpVGZ6foGh0Ss8d/wMV6amKRSLlMoVAprKzoE+Xn/XnZy+MMbxsxdYyeaIhkNYLhaQwq0SfGHzm910AJvrmyxBEJDll7xEXnWN8UsSgiCA4+A4zn7H4VPAmwVBqKYDXd4ufw9wp5cyXPOekiiiqAqlcoWVlSyyLGPZFodPDpLN57FsG0kUWMnmkBWZ3du3EgoGsG2HC8OjXBobpaWhHt0wGboyyQ17t2PpPhj46k0HsOkANtc3WbZtE4tF2L9/Gw899Cyh0Bom4F7gY0DSz8fXGn/1drdt258GHpQk6T4waok5a41eElEUBdMwmV9a5sjpC+iGRXNDksGLQ/zbVx/m9usPUJ9McOLcEIZp80e/+j8YGp+kouuAg2M7bO/voaUuxalzoyTjMc5fHuPG/Ttr3+4u3JKhvvktbzqAzfWtRwB7gQeBtmqKIIjiNcZv2zaCAIVC6d6Pf/zBe3/3d38eRZExDNN/IVEUkSSRcllnYmaewUujXBy5yqWxSQa62+nrbOJP//4DZHJ57rrlBn709ffx5//4SdKZCv1dHYxNzFAsFXEcMC2LoKYx0NnOJ7/0EKYl0dnazMWRcUqlMpIoYruRSj+wHziy+Q1vOoDN9a1hAAOe8Xf6xi8IiBsYf/VnSRJ59NFnEUWB3/mdn0NRZCzLQpJk8vki88tpxqfnuTB8heGrUwxdmeA1t91EX1cDv/tXf8dt1x/kjhsOgiAAcHDnds4NX+XK1DRzS2k0TcRGoLuthUhQ42Nf+CpPHhmkpaGReDRCJl9kanaBno5WbNMEt4x476YD2HQAm+tbWx3A52qNf72jWG/8lmVh2zaqqvDgg9/Ati1+7/d+iYpuMDZ5hbOXRhi+Ok4ml0eWJHo7mji4a4BwKMinvvowP/iauzm0awfFchnHtjl5/hL7dvQRCgUIBmQMQ2d8aoU7bjjI0OgYn3/4aRbSeRrr6tANnaOD5xEQOD9ylYGeTgzTjz7uBP735le66QA21ytbnd7Ov/tbMX7LMrEs975AQOHBL32DQrnE/lv2cvjEGc5cGiERjxKPhAkFg1w6fIz+zjZ+47//OMVKiYCmkS0UwHFQFIWKYfL00WPs6O+hVC5j2xYiAp/40sPIooJpSYQCQYqlMrIsUZ9IAHD20hj333EjgiBUj/0G7zONbzqAzbW5XmJJkoSiyC3AZ73cf43xC15YXjX+6s+1xl+NAmzbQVYkHvrqk0zPL/BT73gLP3z/vaRzeRRJBhx0wyQaCvLY8y+iGwbb+7pdB4LrVJrrEsgCXL4ySSZfYCVbpFw2kGUF2zbYvbWHfTsGEAWBx184gapo7N7ax+FTZ1nOZEnGo1iWAxDBrWJ8ZNMBbK7Nde1KKYp89/Jy5jVXrky9WlHk9o12/uqqGr9t2xiGAThrjN91CBaOYyPLEs984zAdLc38yH/7AabnFzFlGdOyiIXDNNYl+MQXH8ZxRA6fOE9jfYKVbJ6QFiBTKBLUAhTLOoZhoaoKvR2tbOvvYv+OATpbGtECGiDyqhv387//7mNMzS2gyjKXRie47bq9WFaletibDmDTAWyumlUl5tytqvK9uVyh+T3v+QCnT18iGNRe0vir9+u6ztmzg0iSRFNTM6FwaI3x27b7v2XbRMIhvvyFh8mVCtz5mtsRBJGu5kY0SeJv/+XTDHT3sL2vF13XKZbLXL46gWXaiKKKbth0tjaxe2sP+3duobWxHlV1y4emZfm037pUitfecT1fevR5opEQZy6OcOt1e2oP/W7ckmRu0wFsru/X1Y2LiN/j3VKy7Jbq3ve+j3L48GkikdDL7vzV8H90dARZlunt7SMYDJLP53zDt203HTBN93dwKFcqfPHfvkYwEOCnfuYtnDl/iadfPEVQi9Db0c780jIT03MsZ7MIQEdrA6+6eS8HdmyhPpVAVRUMw8Cy7Fquv78MvcKuLb185utPkYhGGb46Rb5QRFMVbNsBl258HfD4pgPYXN9PawsuGeZ1rFXMQZYlTNPkve/9CE89dZRwOPiyVODVfN9CkiSi0SiaptHU1IRlWczOzqIoMra9avzVx7sYg8jnPvFFTp2/ROf2LRzYvp3JmXmeOHzUPdDuDu6+ZT97tvWSSsRRZAnDMLHsjY2+dlm2TV0yTmtjHWXdoFiuMD41x/b+LnTbrE0DNh3A5vrexvJwZbJe7Rn9hoo5suwy/d73vo/yyCPPEQwG1vy9CvgJguDv/AChUAgHCAaDqKpGPp9nfHyccrnE0tICmqYRDke9SGDVAbhOwKFU1jn5zHFsRyIVT9Dd1sQb77mJ7f1dJOMxJElENwwsy8I0TQT3IAhoqu8MXso5BQIaW3o6ODZ4mWjITQN2beuDVTLSfcAfAvamA9hc30tLxRW/eD1uH/0BXEGNNUsUBCRZQpYVdL3CBz/4OR555PlrjB8cDMPENE0sywXfFEVF0zTa2ttZWloinkhgWxamaZHP5ygWi0QiUZLJJCCwvLy0JmKwbfexDuA4FseeeJa3vfEefu6nfwKcIrpuYJgmuuEgALIiY5oWoiiiKjLPHT9Nb0cbqUS8VvRjbRRgmuzf0c9TR07T1tTIpbEJKhW9thy4F5fcdGnTAWyu7/aleYb+Ou+2gw1kskRR8BRzJIqlMlevzjE4NMoLTx/nzLFzBALqNSG+YejouoGu6+i67t1v0tLSyuzMDPPz85iG6YN+tm2hKAoAqbo6Oju7OHz4eVbSKx5m4Bp/9bGOA4Zh8M5fe4Dmpnre+MbXYdkuWq+pKrbjMHJ1nMa6FI+/cJSvPPEMOPCm++7mB+65g1KptOEJMS2LjtYmQkENVVUYnZhncXmFxrokppuGqB4YuOkANtd35QoCN3sGf58X6kvXGr2IIkuIoki+UGL46jTHBy8yODTG0kqWuSvTlBaya4zfzdtNTNPAMEwMw8A03VA8k1mhUqnQ2NhMLpfz76+G+NWc37IsZqan6ejooLm5laVFtzO41vhN00LXKziOg2nZPPDA+2lqaubGG/dTLBY4fWGIkfFJnj12klffdhMHd++gXNGZW1xiam4e03xpiT/bdgiHgmzpaefK1AJBTWNwaJTX3H5D1QHggZ9//32bHz7wwAOYZnrTlL57VgQXuf814D3Ar+PKZjVQI5cliiKaqqDIEuWKzoXRcb78jef45Jcf4+kXzzA1t0QiFkfTbeavziDLkp/nrxq/6Ru/YeiUyxVs20bTFFZWVohGo8RicUqlEqbpav67EYOJ47g4QbFYZHFxEduyWF5e8sE7x7HRddepRKNRurp62LJlAFUNcPjwafoHOpE0mf/+O3+MLIk8+twRLgyPMT23gCDAL/34W3ns+SMkYxGaG+tfMg1QZJlyRefEuSFikTD5Uokb9+3Asn1wsxH4IFD6frh4FCW5GQF8F66qYs693q17/QOE6k6vuF9pNl9k8NIIx88Ocer8ZXKFEvWpJN1tbVR0nXPDoyydnqM0vwK4KjvVkt6q8Ru+8VcqOvFEnIGBLczNzbG4uMTc3AypVIpIJIIkxZFlmUpFZ2lpgWw264OFS0tLVCplSqUSqqohyxK5XJFIJEJraxt1dXVIkoRt20iS+xp//Vcf4vf/4Bf5qTf/AFMz89x7643UJxMosszEzCwf+8JXeOjpF/iR197zsifOMA229XZiWRaJWJQrkzNk80VCAQ3LxQHqPAf6lc0UYHN9J60qMedO4DXe72uNXhAQRQFVUXAch3Qmx7nLY5y+MMKFkauMz8xTLleQJJFwMEhzfR2tTfV87annWZ5eQMiW/Fbear6/kfHruo6iKAwMbAEHRkeGMQydXC5He3uOvv5+8vk8pmGgaSr19Q3ouk4mk6lpDRaRJJmGhgb6+weYmBgnlUqhqqqfNiiKiiRJOI7DwkKa9/3NR5Hrg4wvzPHW193LV594jnAwwPJKhseee5F3vuPtDHR1UtZfurXftmxSiRjd7c3kiyUM02ZobIJDu7fWioTcu+kANtd3wurydvr7vNw0tZHRS95Ob9s2SytZzlwc4dT5YYauTJDLFylVdFobG/xSmAAIosCxwfMYloWRLSJky76IRxW8u9b4DUzTJJ1OoygyZwdPMzs7R6lUpFyuEA6HiMdjyLKCKAg1vH+beDxBqVSiUMhj2w6OYwMO6XSa1rY2mpubuXJlzDf8qu5AtZlIVRWGR66STMfYtreHf/zU55EEkVsP7eO26w5wzy3X09HSTKn88nwAB5Blma29nTx55DThYJCzQ2Ncv3cHYNTiAN+XIiGbDuC/fvV5F+DrWUfMWWP0nmKOYZgsLKU5d/mKa/gXLuM4AqlEDByBWw7uY2punkKpjGVZREIhOlubEQSwHRi9NEZhdhlREuGbGL+uV6hUKpimQalUolgsYpoWlUqFcDjMvn37kSSZcrmEFghQKpX8fgBXUShGpVKhWCxiWabb0NPczMLCPIFAEFmWkSQZRVG8XoG1mgKyLLG0kGbsqzO88zd/hh95w6vR1BBgYxj6NzX+6jJNk91bevnak4fpaW/j8tVJSuVybTlwC+50oZObDmBz/YcDr7iqNPd7t5tx9e+vMXpZkpAVGV3XmZ5d5MylEc5cHOXS6DgLyyuYto0iS0TDYbb3drOSy6OpCjv6ezg2eAHbdujrbGc5k2F6YQnJtLCXCji2gyC+vPFXKmUEQaSlpRVZlpmZmcGyBMrlMqqqsmfPXkKhEOVyGSGfI5WqQ1YUyuWyV+s3cBybcDhMsVjEcRz6+weoq6ujkM+zkl5haWkRw9BpamomGo2tMX735vIENCSeeuh57rr5OpqbXYzgW1mWZdHWXE9dIoaAwOJyhqm5RbrbmqsaATIuUWrTAWyu/xjwFbcuXyXm7GcDNp4oCEie0ZfLFa5Oz3Hm4jCnL4wwPjOPIis019e5RB0BVEUGB7L5PC8OnueuGw4iyRKWZZOMRRFFkYCmki+WcMo6RrqAiPBNjN/N+VVVY9/+/YiiyMLCPKIoUCqVUBSFffsOEI1GPU6ATT6XIxKJEg6FyKyseK9pYxgWhuGi/L29fWiaW9PXVI1AwCGfzyFJIoVCgUgkuqa3oFpSdBwHSRY4fOQk73znn/G3f/uHpFIJKpXKKz75tuMQDAbY0tPOhZFJQsEg54bG6O9qqxUJuQu3qrLpADbXv8uqJea8BldM42WJOaVyhanpOY4OXuTkuSFmFtKEAgEkSaSvo92VtbJsLoyMEouEaWmoR5JErk7NsricJp11G9sW0yuUyhWaG1LkiiXqIxGWLk2gStI3Cft1DMMN9+vrGygUCpw7O8jszAwVXUeWJfbvP0A8HkfXdc9IbSoVnXQ6TVNTE5qmkc/nME0TXa8gCAK7du2moaGBTDaDUTMivKWlFcuyCEciqKrK0uKiRxmuEoocny8QCAQ4efIsv/3b7+Ev/uL/JZGIf0tOwLFtdm/r4+iZIRrrUpwfvsL9r7rJlxzHFQlpBmY3HcDm+nZXAJdrf7+HLO9gQ2KOgCLLiKJIoVhidGKGk+eGOH5uiNHxGcLBIDv6exjo7mZo7CpDV8a5MjXD/HKavs52AppGYyrJ/HKaaDjEjoFeTl8YolguMzo+RVnXyReL9Ha1YxTKzAyNIwnCN835TdP06/aWZXLp0kVKJZeWKwiwe/deEomEZ/yr5ULbtlleWiIWi5GqqyOTWSGfL6BpGn19fcTj7o6tKiqWudoMVKsilEgkyGdz5Au6pyVg10QC7v/BoMYTTzzPr//6/+av//r3iMdj6PorSwdM02Kgqx1RFAgHg4xOTLCSzRGPRLBsCyDuAbCf2HQAm+tbWVHgdm+XvwfYttGDqhx2QRD8+XXHBi9x7vIYM/NLLGVySKKIJIqIflnO5tLoVSzbIRYOMzE9SzIWpaO5kXPDYxRLZeaWlmlIJqhLxLFth0y+QKHklvdGR8aRMiXy2TySLL8iwE/w0pBMJosoihiGa/x79+6noaHBB/gsay2dt1KpMDMzTW9vHx2dXSRTOWLRGIFAwHUYnoCoqmkuWOg5AUEQyGWzblmxkCOXzSLLiscLWDX+6s/BYIBHH32W3/7t9/D+9z/gAaPGN8cBbIdYJExvRwvLKzlkSWZodIKbDuzC0n1W4J2bDmBzvZJVJebc5Rl+9zUgHqvEHEEQyOYLDF4c4cS5IU5dHCaXL7kXdEAjlYiTL5ZAEAhqGkFN49zlUfo62xEliT3b+2iuS5HJ50nGYgxdGSeViLF/x1ZGJyYplisc2LWNbK5AvlgEASTLpjKfwTYsRK+2/lJhvwuqOfT19SNJEqdOnaBYLPmA3v4DB2lqavJ3/lXJL/d/03QR/oX5eYLBEM3NzYSCQWzb9jv5nBpwU1NV/7WrUUA+l2NyYsIFFQU3PbBtZ43xV6OCUCjAl7/8KKoq85d/+XvXyI2/RBKAJItsH+jiIa/VeXBolJsP7q5NA+7xsJnSpgPYXOtXs3eB3M23QMy5ODrO6QsjnLowzOTsPK2NDTQ31BEKhOjvdIUvrkzNYNs2TQ31NNfXuTu4d9Hu2dZPsVTm608/T30qwa0H95GKx9i3fQuRUIj25kam5hZYXM5w+cpVUokYiXCYK2dHcEwbQRReluFnGAaO47B161a6uns4cvh5SqUSuq5jmgZ79uyluanZf9yq0a8afzUa0PUKM9NT1NfX+1GMaRg4nkMEl2YsiiKqqnrlQVcqzLJs6urqKRaLBIMBotEY6fRyjazYWmBQ01Q++ckvYtsW733vA8iyjGm+vBMwDJM9W3r54iPP0tbUyNDYBIVSEUWWcVxqcDeuSMjTmw5gc4GrGnNvTXj/ssQcy7JZSmc4d3mMk+cvc/riKIvLKxRLZVRVQRRFMrk82/u6CQcChAIB+rs7mJydR5IketpbOHd5lJVsHlmWaK6v4/o9Ozk2eIFgQHNr8LpBX0cby9kc5y+Pks7mMEyT7vYmfvot99OaSvJ///5TGIWyW+uvhsCW5ef5tTl/oVDAtl2CzuXLQ0xNTfoc/T179tLR0XnNzl8N/Vf/N6lUygQCITeKEMU1cmCChz9U837HcdxeBU2jUMh7YiE2kiQRDAYJhUIMbNnC6OgokxPjHlmpVkvAxQ1CoQAf//gXSCTivOtdv+Xl+i/tBCzLprE+SXN9Cl03yBfLjE/Ps7WnY71IyKYD+D5e3Z7Bv87L7WMvafRqdZRVmsFLo5y6MMyl0XEqhkE4GKCjrYHt/R2IooDjuM/TDYNCuYCqKEzMzqIqKqonUyVJEoZpUp+Mc3DXdgTBNaSejlZPX1/l8pVxcvkiNjZbe1zFnJ0DPbQ2N1Ipl/nd33sf585dJhDQ/AYZURQRRdFD5nXPCbjlvkKhgK5XOH8+7/9N1yvs3Lmbjs4uDK/91zVoc43Rm6bhtwc3NbfQ0d6OpmlrQL6qcIhTbdbxgMhqihQIBMnlcj7gZ9sWmUyGxcVFkokEU5OTNenGKibgOG6VQNNU/uZvPoDj2LzrXb+NJEn++1+TBDgOgYBbDjx5boRwMMjgxVF2DPTUioTcA/wJ3yciIZsOwO2gG+AVEHN8Np5uML+U5vTFEY6ducjIxDTBoEpzQ4pD+7agKgq2ZaMbJrphYhoWiuIq34aCblnPbdEVsWyH7o5GJAky2TwBVWVbbw/d7a0USyWWMzmWVjIsLKcJBQNs6+1g3/Z+tvd3U5+MI0kiCJBZyfLu93yAkyfPEwhoa8Q3VNWl2qqqSqGQ93r7K2iaRjKZZHp6CkEQPJafzvbtO+jp6cFYs/NbNeG+6yTA7fdvbGwiFAphGgbFYhFBEFAUxW8wchzHxwBwHHeKkGeQsiwTDAbJZjOecbtaA8OXh9BUN0IoFAqEw+GaqoGFadq+qpCqKvz5n/8DkiTxrnf9NpVK5WVEQiz2bOvj2WODtDY2cnF0nIq+RiTkOqAHGNl0AN/bn3snLjHnPuAQL0fMkWUqus7U3CKnLwxzfPAS5y5fwXZs+rvbufnQTkRRIJcrsrJSwLJcIYuAqhLWIjiq411gbiSsmyalYpmcaeLgUl63D3QhigKpZJiKbnHk9DmW0mnCwQBbejv4oVffwva+blLxqBtJmAa6YaAiUyiUefd7PsCRI6d946/m+9VdPRwOEwwGMYwoi4sL9PX309HRyZkzp5ibm0PXDcrlMtt37KC/f8Av79XiB5Zl+9hBIpGgvqGRUCiEZVmUPRpw7YwARVEQvMhDWOtN3bFijoPtOKiqSigUJpNZ8XN8XTfIZjNksxkkyS2ZVolHq5Lj1RQDVFXm3e/+P7S3t/ILv/ATVCrlDZ2AaZn0dLSgaW7kNTY5xdJyhoZUHNOdGaB54O6mA/geWypw0NvlXw3s4xUQcyanZjk2eIkT5y4xu5gmvZLDsEz27xqgr6uVUklnfmEFAYlELEoimkRTFP8i30hUMyxAKuYZsmFSqlTIZPLohk4opJJKRllYyiAKcX709Xezd8d2wEKvVKjour+bKoqCYZr8n7//hG/81Ty4GqJbluX36ycSSUKhEPUNDTQ3t3D16hVGhkfQdZ1KpUxfXz8D64x/ded3nYkgCHR0dBJPJDxD1bG8KkBt2K8bBoqioCoKhq77GEA1CnBqogAX1NMIhyOk08s+FViSZGKxOIqisGvXbqamJhkbGwVqMQEb2zb9Hfw97/m/hEIhfuInfohKRb/m/NuOQyQcor+zlan5NJqqcn7kKvc0HcRcnRlwF/CBTQfw3b+qxJz7vNtOvgkxp1gqMzYxy4lzlxi8NMrlK5MkojEQQJZkBnrb6e9pQ8BhYmqBkBaitbGZgKaujsVat/PUhJd+FFwtPEmiQCQUJBYJY1kWK9k8s3MZQiGV+s4Y3zh8nNHxGe66aT/RSIiKXt1dZVYyWf7mvR/hyAsbG7+r42dgWSbpdIlKpcLKygrJZIrLQ0OcPXvGA/B0env72LFjJ6aHuq8vG1ZbgltbWwmGQqskIMtaMxi0+smcGrVgSZI2BOfWN//URgLV1KMqKmLZFvv2H2B6eppCoeBHAlXJccMwkGUXRPyLv/gngkGVt7zljZTLxbVOwIvCdm/r49LYU8TCYc5eHOWuGw9QUw+808N+spsO4LtvVYk5VfR+64aJfw0xJ1cocn74KqfPD3Pm0ghjk7PkiyUO7NjKoV07KOs6kiRR1ksM9LYxOb0ItkhnSysBVcWy7TXAk1sOFP2yl+M4iIKIIAoIsMZBOF5OXH1+KhEjlYiRzuSYnlkmEg0wPjfLR774MK++5RBbezsxLff5//zPn+XZp48TDgdf0vhdRR83Z8/lspimSTKZJJVK0draxqVLF+nt7WPnrt3YloXtGV1t3m8Yulvfb2nxiTemafrAnlPzWWpHhJmmiaqqKIriRw/rI4W1zT82mqYRDIY8TMDywcHBwTPIskIqlWJlJe1HAdXuQ8uyaGlpJRwOoes67373PxIIaLzhDfdRLpfWOAHTMNne14luGCRiUUYmpt2ZAZpSHSHe7GFBD206gO+OlfK8dtXou68B8ahVzBHI5vNuqe7CMOeHr5LJ5d0OO8tGkSQCqsL4zCwdzU0sZ7I0NcZobmpiZGyGprp6UvEYlm3Xasu5aLsgUNZ1FtNpLNsmGgpxZXoKxwFVUYiEgjSk6pA8IY4qZ6B6gVYNKBmPkohGmFtaZnklT10yyoOPP8t1Czu55dBuPvTBz/HYo8+/AuPX0Ss6sVicZDLJpUsXmZ+fo6Ghkc7OLjRNo72jA8e2sWpy+FXjNwgEgrS0tSF7O/ka468x5LU77aozkGXZdyq+08PxdQJcwo/t7+qqqqFpAXK5rCctBtlMlsMvPO9HI7U6Bqqq0dHRTF1dnddG7BKD/uAP3gtwjROwbIdkIkZ7cz2lio5uWAyPT7Nvex+V1V6Fu74fHIDgOA6l0uh347E3eV/Sq/lmxBxZwcFheSXH+WG3j/7i6DiFUplkLEZnSzMODk8dOY5pWQQ0DcMLjZvqU9ywfxuGZbK8XKCjpYWAqqzZxUXPmPOFAlPzc6zk3KacUrlMPBolly/QWJfySn0i2XyBeDRKNp+npb6epvp6ZI+quz5nrar3TszOEo8HkSSJ2eFpTh89BwIeer+R8Ru+sTQ2NXHgwEEmJyZ4/vnnyGYzNDe3sHfffuLxOCsrK365bxVANKhUdGRFpq2tHUmWMausviqSv24X99F+z9gVRfHnBmRWVigWi35J9Nrn2j7hp+oI8vmsVyKsKgmbPiehp6fX1SewLBrq631NgdrIqlLRCQRU3vveP+D222+iVCr4fw8GA3zma4/z7LFzyKLE7m3d/NSb768dOHLGw4zM7yWDDwZ7v6sjgBbP4O/nFSrmLKazDI2Nc+r8MOeGr1Aq6zSkkoSDIWzHIRoK0VCXYPjqJAB9ne2oiowoSYxcnWT3jh5M22Z5uUhfRweCsBrCC4KAKAjkSyXGJieYW1qiWC4TCgTY2TdAMhZlbmmJukSSnvY2EtEYuUIBTVXJFQqUymWWMxlOXDhPYzJFR0uLC5rVgGqWZRHUVAa6u5iZX2D47DAjZ0eQJPFljN/0cnuX3x8KhTh37iznz50ln8+i6wYrK2kqlTKimHSrA55hVev9hmEgSat6ALquuwa2roznOA7OOuOvHrthGFgewafK1BPWEILc3b3WAdTeVDWAquoUCvmaAaMOoijQ2NjEwMAWLl266I8h84E+7/tRVYVyucJv/da7+Yu/+B1uu+0GSqWiHzHt3tLHw08fo7u1heErU1TKldo0ZQduX8fZzRTgv3Z1Aa/FJebchtu1dW0oI4AkSlQMg5mFZS4OX+HUhRHOj1xhKZ3BNC0O7NjGHddtYWE5zeOHj7lNOdYV8qUiAVWjs7WZxZUM6UyWZDzGTQd3oCgSi4t5ejvaQKCaIyKJIg4wOjnJ0NUrpOJxtnZ3U6pUaKlvIBmP01Rfz5aeHmzPKPLerHvTNBEFgUgoRH9nJxfHIlwcHWEhnSYeCtHX07OG0OIAsiSRm19h7PyYP8XnpY3fjQByuRylUpGzg2coFkv+rh6Px+nr62NudhZFVkjV1WHoOplsFstTArZth+aWZlRNQ/d4++sNH1yCj73O+GudV9UByYriEXjWYgBuGuD4DsBxbL/Gb9sWsgfOGl5VIZWqIxaLYZomkiyRSqXI53NrXnM1zXArJel0hne+8138zd/8Prfccj2lUgHTtOhoaSAeDSFJEvPLaWYWlmlvrsdwpcZlL6XcdAD/2WkJrkTT/Z7h38gGMlm1O74sSTgOLKQzXJmY5tLoOCu5AnNLaRaWVpAkEVmWODN0mUgkSLFUplgqsbWnk0goxHImQ1tvI3OLS2SybldeW1OKcDDA9Gya/s4OEGovKpmllRUuDo+gmyZbe3poqW+gLh4n7IW8siS5xm5ZPrisGwbhUIiA5rL0TMvCdhya6+uJRcKMjk/w4Q98hN7uLt7+U/8N1RubLckS505d4PgLJ/xq2ssZf7lc9ppmghQKBYrFIrZtU6lUiMUSDAwM4Dju71eujBEKhwlFIpTKZbJeyTCVqiMcjmBUKj6CvxGA52xg/LW3UrHoE5Ec8FuLV0HDVeM3zVUHIYoCmhZE0wK0t7ejKIrnxNxjyefznD93FlF0cQlJkqirq/PTC/f1DCoVd+ZAOp3hd3/3L/jbv/0jdu/eTqlUJBQKsbW3g8tj0wS0AIOXRuhub646ALwo8282MYD/+CUBu3CJOffgijMENzJ2wbMmSXIHXeTyeWYXFhm+cpWZhQXS2SyWZRANR9jS08PsYo4Hv/ECzQ2u9HS5XCEUDJArFNna20U6k6U+mSASCvLimfPs6OthObNCV0cD45OL9LS3I8uSf2EGNI0LQ0M89sTTdPV2c8v1h0hEogiiSFDTiEUiPkAlCMI1H9TwmmM0VfWNQBAE0pkMhVKJ6fl5Pvrhj5OKJ/ixn/hR6pMJzg9e4siTL+LgfJOw3zX+aDTGwJYBFhcWOXXqpK/pFwqF2b59J+BQLrtlwVKpxJ69e0kmUxiGwcz0NIIo0Nra5u7g5fK6EqabAtjr2H0b4QHVsD2eSKBpGpOTE+Sy2Zr5A04Nm08jGAwSDAYJBAKoqoooCoii5Ifluq67U4hMk0DAfXw6nWZlxSUQ9fT0EggE1gCKVdygXC6Rzebo6Gjh/e9/gN27t2FbJodPnedjX3iUukSceCzEr//MW9BX24uXvTRg4XsVA/ivHAyiAtcDP4fLvf5DVjXvFR9g82ilsix5ss4WkiRzZWKS0xcuMD41zdWpaZ558UWeO3acpUyaTD7HwlKa4fFxbj20F00LcWVylpVsDlmW6Ghporm+jhdODnJp9CqiINLW3Eh7cxPZQoH6ugjzS1kak3WEQ4HqOGlUVWVqbo6PfPDjHDq4n3vvuhNFktBUlfpUipwnjR0MBtFUdU3OW3Vg+WIRwzQJaJpvWJIoIisyhWIJVVGQogGGL17mqUefRFE0Lp265KYNHqvupYxf13U0TWP/gQNoWoBTJ0+Qy2Upl8ue8e/wnGDJxweamppoaW3zwcxgKEQk4sqJVTx23xrjZzUNejnjrwXuJEkiEAiQy2bI5/M1HAODcDhMS0sLTU2NxONxwuEwsiyvwQqqrylJEpqmEQqFCIcjyLLi/RwmFnOfWy3zWf6UIsfXOBAEmJtb5IUXTnLLLQeor28gFJD5xgsnaK6vZ3RiipsP7CLocTq8TehF4Pz3igP4rx4MUiXm3MOqTNZLE3MkiWKxxMWhMS5fmaC5sQ7TKPPsiy8SjYRJJuo4NngRw3J44913gvgUsqIQ0DRU1aV6yrLMmYtDzCwsEQyoBIMaC0tputtbURWZm/fvwfam2Cwsp7EdA90QUSSFRCzil/kURWFiZoa/f+8/0N3bxZvf9AamZ2aRRJFYJIIoCCTjcRRJIhmNElA1JuZmfcMHKFcqyJJEoVRidmGBeDRKJBzGtm2ef/pF2rpaUYIqpmFy8I4buXRkkPNHzxMIBdbwCqoI//qcP5PJEIlEuHD+PBOTE+SyGT8H37lzB6IoUSgU/NJeZ1cXTU3Na0J7WZZBEDAqFQzPgTmr7KUNd/31hlrl8/t1eo+9aNk2pmEgeN2CLS2tNDc3IyD4FY2qI3I8fMFxw1T/s1erJbUVk0DADRabW1owDYOJyQlsy14jLmrbDrKsEA6HGBub5Nd//U/5+7/7Izo6O+lpbyabLyAKIpfHJrlh3/bamQH3AJ/dxAD+/xFzbvNO5P0vRcyp5vKiIJArljg7dIVTFy5z9tIotg2vvu0gJ86c4PkTJ7Esi3e89a38wycfpFxxh0sWSgY7tgyQzuYIBgIENI1Du3Zy+ORZ8oUSmiZz/Z4d9LS3YXpo8rbebkzLZnElw3PHT4PgcOOB7czMpunp6PDRflmWmVta5O/+6u9ILy7zW7/z6wyNjgGwtafHwyAcJFGkLpGgPpGkouvEIhEquo7hNdFUdJ1ypUKpXMYBJEkkGAzy5GPP8PBXHqOlrYnXv/l+2ltbmZ2cpTHe4KPz1XOkeMq7VVS+CuxVKmV0vcLiYomlpUWvP19HUVS2bNmGLKsuhz+ZJBaLE41GXZZdoeAaOqAFAu5QT4/7v6aJp8YJXFMFWBcBrPL0bT8Xd2wbvaJT0XVEUaSjo5OWlhZP9FO6hjhlOw6I4qrjqYk6hBqade37ppeXCYVDiIKAadse12D1ONwoQiQcDnL69AV+8Zd+nw984M85sGsrDz72POFgkHOXx7jxwM7aT/0q3P6AyqYD+NaIObd5u/yGxJy1eb2AZdmMTs9weWyCkxeGmZiZRxQktvZ2ctt1O/jSIw8xMj5BKpVAFiUs2+LVt97MhRG3XzyTK9Lf3c34zAztzU10NDXz2LMv8okHH+W1d9zOcjZHe3MT+WKJqbl55haXqBgGjakEd9+0j4ZknOPnL5HOFEjFE8iiiOWJVzgCfPXLD3F1aJR3/MrP0tXRwdXJSeqSSVayWVLxuD/RxjBdBF2RZFrrG6gYBpNzsziOQyQU8iMKWZJAEHns4Sc58sxRAsEA41cneeQr32DLzi0sjs6ilys4rO3qE0XXaRQKeb+l17Zt6usbkGWZ+flZJElG1ytIkszu3Xuor29A1VSCgYBXz3d1AEzDcNl/XkheKpVIJBIoiuIbTBXAFGqMfiPE3QXzrJpGndUuQndWoEMimWBxcYG2tnbf+BVVdXf+9aDiurWeHyGK4jWzBDKZDPPz8ywtLVIul4nHYwQCoRpase0/NhwOcPLkeX7t/3mA33vg/8GybVKJOBdHxykWS/73idspehB4ftMBvPyq84g5978cMae6g4iShOz1v+fyBa5OzTAzP08mn2NrdyN33rCLgBrGsCzOXDzHUiZDb1eHN1tORjd02lvqOXd5lHtvvY692/qomGXam5sYn5rjj97/AY4OXuL+O+4kEYuRzZd49thpbCz6Olq599ZD7N85QGtjPZoW4rNffxRBhHLJoL0xjuWBeIIo8NQzz/HMw08iShJtXW2kV1ZQZJlKpYIsy+7E3HyeeCTCSj5POBgkFo5gWRaaoqCpGvlMmsXltJ/LCqLAkWdf5Ozx88iKjKqpRCIR8pkCX//CQ+RzOXr7+rDMVQKMYRhEPAXdUChELpdDVVX2HziIoig89+zTOI6LkAcCAW686RZaWlrcndUTBCmVSquG49VPBe+7MQyDlZUVGhoa/N3YB+zWA4FrSnl2TZeeva5/30RR3Zw6lUyxe/ceAoGASxTyHJrPI3CcDS+s9fc7Xlul6M0TXA3z3UhJ96IMXTfRNMcfSlI9PndKEUSjYZ57/jj/533/Qrij2SUs5QpMzS3S29FalQwXvChg0wFssKrz6+7Hlcqq25iNJyJJIuWK7mvCzczNMzU7Q7FU5vSFixw9fcYlt4giqqoQDgW5bs9e7n/VnUzOXqWtpZlIKEwgoBHQNEzHYWd3C7ceegeqIjO7sMTpc5f5+lPPcfj0ecKBCJ2tnQxfnaBYKrFrSy9337KPWDhINp/j7ptvABwcByZnppmaW6BcMUjFE37rqizLjF69yic/8HEqxRJd2/qIx2Nuicq7qC3LYnFlBd0wiASDKKLI3NISjgPxSIRiuUxZd3P/qNfTXjEMTh874+IJ3d309fWvyWmbm5uZskwmJsZpbGzyR3NXB2JEo1Gi0RgLCws0NTUDAseOHmViYgJRFOnu7mHX7j0kEq4ar+kJeVZJOz5xosbwqu9dKpUolUprevnXNzPVHuuq8bu7P4AW0FA8inO5VEbVVESvMlJtH64Cc+vpw9cY+nqnUJsKeEzJatejZbnH2tTUjGmaNDc3I8syY2MjfhpQrTpUHVswGOD8+VGi82n23biPSDjMmYsjbOntXD8z4F2bDmAtMed+D9BLbVjXk1zdN8MwmJ1fZmpukUQ0Qq6Q5eS5c4SCAURRZvjqFPt2bCGTz7GYXiEcDhFQNURJxHIsnj9+GlFUuPfWW9xmEVVFkWQkSSKXL/K1J57l9IVLnDg3RDZfpKzb9Hb00NXazMHdW+nvaiMcVLk4OsbnHnqYrz/5PI4Dj3zk79jS04UkCS7O4NjoFZu2+ugqzVeAI4ePUci4RJPmjlYqpum1u+GTXURRJBmLeUizgGFZzC4uUNF1dMMgFg6TcRwCyQCFUpFTJ85y+uhZduzYSSKZQAsEMDx+e3X19PRy9eoVb4hmnS/jtbAw75NjisUiIyPDnDs3SCaTIZFIsGXLNjo6OwG8rjlnTR59jTDHmpvtS4NXJ/W8dP3fXsPlN00TWZZJJpOomuaWQh2HWMxeW0HwyECyLK89rmqKsS69WO8QnI2wI1nxyEu2pxTkfo6KrtPd08Py8hJzc/P+++M5DstyiUaRSASrUGH68jidO3oZujKxvnnpJu+6v/r96gC2stpHfzMvIZNV3S0kSWJhKc2J80McOXmeUDDI9Xu2cOTkUY6cPMW2gV6aG9v4xvNHaayrp1h2OLhnNyfPXyAaiaAqMpFQmH3btvEvn/0qc0sZRMmhMZXAsW0K5TKz80uks3kM08IwbFqamtgxsI3r9+6gp70ZRRG5MDzKhz/zeZ44fIxsvkBnaws/dN+ruO+2m2lurMeybYplnfGZOUzTIhwMIkoCluVeoJMzszzz6FP+Z8yvZBmfmaaQL1CXSNCYSvmAYjgY9Hc00bv4Lduiqa6OYrnESi6HrEgMHR/m2HPHAYempiZSdXUEAgEW5+cRanZdy7bp7Ozi8vAQMzPTxONxv54/OjpCR0eH+7zFBcrlMqlUil279pCqq/PD742N/NpcexW8c/x6u1gjJuo9yiuHrrL3Vuv9JpIku9N+PWdWLle83gG3fBkKhVBV1S+prjHqDXL/jchFL/W3qr6gaRb80h84zM3NeE1XIoax6mB9nUIvygOQZImZ8Rlsx0Gpj7CSzZOIRbAsvxz4KuBfvl8cQJWY8zov/Ln5pYg5VUpsRdcJaAFs2ySbz/H5h5/mhZMXuHHfdnYOtPK1J77B/NIS8XiMZDzBlu5eRCFEKBDANC1aG9tYXFkhoGo0N9TT2drCP33yC8wuZrj5wH6eOXYCx1FYSKcJBgJEI0li0Xpam+rYs7WPLT3tpOJRnjj8Iu//l4/z9LGT6LpBd3srb//B13HvLTdwYNc2EvEY4M64EwWByZl5CuUShmHRmEz5F6goiVw8f4mVhSX/8+oVNw8fmRhHVRWa6+vBA7yWVlZIxmKoXmrg9gmIZPN5EKCxro5HHnmSR7/+BLZlI3l03lw2S2ZlhaxXI29v71gd123b9PT0MTI8zOLiIprHHagafzQaIxaLMz8/y7ZtO0jV1bmGuU6Tb32zzkYIejWUr4bzgse4Mk2DaqO860/sNWw7y3JbfuvqG9wKRbFIuaJTn4CVrINh2D7hKJlMomkBHGdVDpxaR+QZtr0u9Hde4ufa+6pcg2x2tWHJtmzGr14lm8tSLLrRUDWykSTZpY9L8urmJQpMX5lCmNcYvjrFzQd31zYH3f297gCqijn3eYScQ2ykmCMIyF7ZJpMrsJTOUNYNFEliZn6Gp198kWw+zw+/9g3UJ+vYtbWdT33lQUzHpqer09XLA5obk0zMLJEt5GhvricejbCjv5/6ZIKx8Wn+11/8HYKg8AOvehUVXaenvZ1yRScaipJKRNjW30VvRwu2bXLm4hADXdeTzub547/9AE31dfzS29/CPbfcwK4tfcSiUcDxGGGVVQKOJDIyPu1dRCLBgOqDf47jMDU+ueaza5qKaVqEgkFSMRcojIbD6F64WS1lVWWwRVEkEY2iKAoPfuVhHvrKN/z0u+okLMuiWCwyPz/vEXkCNDU1uRx7b/ft6enh8mWDxcVFbrnlVnr7+nnu2aeJxWJEIhEaG10SjSxJrq5+TSht1+b51dr6BkM4VxuBLL+70XHciTrry25uFOA+NhyOUFdf7/IcPIDRsqCpTqZcsSmWXC1FwzBZXl6moaFhDb6wEbdgfeRSG6VsFD1UsQhXVdhtI/YrER53wNU/rCMcDjM1NYltu2Il61McSZYopHM8/vBz3Lh/V21Pxp1eSTv3vegA3gH88ksTc0QUWUKUJPL5AhdGrjIyPo0iyyiySCab5vTFC4xPTYEgkIhFsR2DaDhEvpBHlmQ6WlsJahqa5vLCV3IZbtq/FU1TScTCZLI5VlZyfOSzX+H0xWH2b99JZ2sbR86co1As0dKQYs+2rfR3tVLWyxw5Ncg//9unGbw0DAj0drbz6ltv4msf+ls6WpqIx+LgWK7WXam0Ye5YLFVYTGfQDZNQILDm77bjsJLJ+L+HY1F23XjATUEKRYauXGHf9u3UJRLYxSK2LPt97wIQDARIRqPIssznvvg1HvziQ66AJ/iSW9VyWzQaJRjs9e5TkWTZ7wisNtt0dna5eMrsLBcunmd2ZoYbbrgJ0zSJxmIEQiHKHuf/pcJrZx3ot7qLr52+U+00rGoCVKOB2gYe27aJJxLUeSmHruvgfXZRFGhIiUzOCj5cJ4oClYpOJpMhlUpdk4I46x3Atdv8NeH/NQxFb6S4KyiS9XoLXMegKCqKIrNv/wEkSWRiYoJgMLimNFh9LU1TeOShZ4hHw/zCL/w3wMGy7HaPufqN70UHsBVXI4/1FFxRrI6ycvvox6fnaEgl6GitJ6CKjE9P8+ThF1FVleamJgIBzeXoF3LkiyaiZHLrdYfIFgpEQiESsSixSARZlNFUmYmZWT7xpeM8e+w0Y5MztDe3sq13K6YFuWKeWw7upL+rjaV0mhdOnuH9H/kYQ2PjSKLIDft28cCv/By3Xbefvq4OdMNge18Plm35bZ8vtURBIJ0vkCnksS2HcCTol7pEUSSbyzN1ZWIVBNm3k8bWJkbODSGrCo4Asiiy5Al/hIJBsrkckXAYSRRJeenAww8/wVe//KhPRa0aqCthJft9A4ripg7JVApN05ienva7CKvEmvb2Di5dusjCwjw33HATkizj2LabhtWIeazZsTfIpWsJO6slu9USWdWR+S3A67AC27ZJplKuAIdlYdaM5nIASYRUXGSdEiiiKJLP5wmHw2iBwFpwsQaYXL/jvxQ/YL3hVp+zVml4NbpZXFhgcXGBzq5upqama87t6jmrvqckiXzsY19EUWR+/ud/jEpFx7btu79XHcAXgXfiSmQjCAJl3WBwaIyT54Y4c2mUdCbH3GKaX/2pH2ZHfxsj41McG7zEjoFebj50gOn5Bb/TTZZlbMehp6OZ0YkpXn3rdSRiEQaHhnnm6ElKpQrTCwsMXhxmam6RUtmgPlXHnm07ObhzK1t7O+hoaWBqfo5nj57kz//xgwxfnSAYCHDLwb389A+/gVsP7WOgpxNVDWJbrkJuVYyyyq8HrtHnq61SzC+l3RDZdptzHL/8J3H4uSNMjroko2RDHfNTsxz5xrMszy+x56aD5HJ5VvJ5murqwDQpFIs4gQCxSBTLK/U99tjTfOazX0YUBd/QnRqjrkpm1e5iuWyWkix7bbkGoihi2y6ZxrIsOjo6UWQ3cpBEkUAoRKVc9nALabVZZwPK7ka7/nrSTjWftm3bH99VLU5UDSmVqqO+vh67Ovhjg+iqqU5CEoU1RluNKvL5HKrXEenlQxse64bG/xKPWcUwqvqCiucEsv7nMi2Loy8eoa2tDcexyWRWUJSGNeXN6uuAg6IofPjDnyMcDvLjP/6DVCr6PbZt/z5gfa85gBPAGNAHENBUPv/w03zuoadoaWwgXyxx0/49jE1Oc3VylsdfeIHTF0aJhCOoapiDu/uxHIdoOOxqumkakXCY/q4Wdm/tYXp+gYeffp7Hnj/KzPwykihRKOtUdNg5sI3r9mzn+j3bSSYijF6d4OmjL/LIM4e5MjlNPBrhtkP7+fm3vZk7bzhId1srsqJhWy7FtlblRRRENK/+PJVeQECgPpbYUB5akiSm5xf9jF2RJUwv/y1VdF584UUAGtqaOXDb9Tz8qQeZHLnCtn27iMZjGKbB8NWrhINBkvE4xVIJw7IwbYuApvL0Uy/w6ENPItZM5V1vhKZpuOw+SUISBEyPrJPJZLh69QqOJ5nd2NjkOQEDcBl1V66MkUjEkSRXaKNULhMKhdz3qsmhryXsrM7bc4d8rO6C1WEbgiD66L5lmVS3csuyqK9voKGxcQ1GUa3JOx5uIIqQSoisTfBXnUChUHDVflXVfeUNyo0bVghexkHUpiirTsBtHKqKjIIrMHrx4gUsy03BqpTnWuN3HaPjOW5473s/jG3b/ORP/tC+clnf6jjO+e81B1D2Qps+1xM6tDc3kIzFuPXQXsYmpxEFgQM7tvLi6bM8eXSQG/bsxkHg7NAYd9+yn56OdqKhCI11SQKqRjZX4PMPP87RwfOcvzyGYTq0NDbR19WLIMCO/i629LSzvb+H6blZ/uVzX+aRZ15gdmGZumSM2284yG/+j5/g9usP0tbUgCSrWKZr9Ia5avSyJHnUYJvlfJYXhk7T19xOoVJmJr3IGw7dQcW+doS0YVrkCiUM017TmScKAvl8nmzazf93XbeXVGM9CAKNrU3sv+06Crk8sWSclVyW0xcvcduhg+5UH8NgOZNh7NIYh5855l7wXuPLRuh7FQsQAC0YxCoUMC0LTdOIRqNrWGvuYy0flY9EIpw9O8jOnbsIh6P+nD9ZlqmUy37EsdaQVnf+2sm+q6nA6vxAQRTc5h3ToirA2dDQSGNT08ZluTVG7hAMCERCArYDwmqF32cclstlnyy0EWbxcqH/+sevgpkObmFhtVTpthmH1kQC1dJhXV0dW7ZsRZQkCvm8zxOwrGrJc5UJ+Vd/9UHC4aDy5jf/wJ0gfM85AIDHgZ91LzaLHf1dyLJIeiVLR3OT731bmxrpaW9nx0AP3W1NbOvroLEuQTIWY2F5mW88d5SjZ84zdGUCy4KQFqS1qZ2muhR7t/fT3tJAMhbm3OURnn7xKLcc3M3p82keeup5XnXTIe677SZuPrCXpoY6RFHGNFyjr+nRdjsFBRHLthhfnGV4doJbtu7lxNgFDMvi2OgF7txxkLPjw5T1MqK4lnEmCgKlcplCqYRt2QTD2hqqq2Ga6LqBrMgk6pKceu4Yjm2z7cBuwtEIVy+Pcu7YaXZdv5dgQ6NrNFXhjtMXOHP0LO7GL65Oxl2zO7kXYnWsdbXsFggGKeTzmJZFU1MLlUqZSCRCIplkZGTYC8ltvzUYBE6dOsmevfsIBUOUSyXCkQjljfJnx14zaLNq/KuOwPbltTOZDKm6OhLxBNPTUwA0NjXT7NGKX9IovftlCeJREVWpVjtW0/uqwVan/dS2/day/5z19OMa9uHG7cf2Gmmx1fNsewNR3M9VJQpVW6sVRaaltY2R4cveRKFV46+yC91z7fDud/8D5XL5NcVi+e/z+cLL0pe/U9dv/MYDL+kAnsAVQEjZtkUiHqWno4WpuQV2J+PMLiySyRVYXE7zy29/E3ffsp9iqcyl0St8+HPPcObCEMNXJ3GQ0NQArY1tdLQ0sWugl+72JgIBmaGxK3ziSw/yxAvHWMnm2NrbzU//yBu448aDPP+ZD1OfSiCIktfkYuA4uh82qrKC7bHOzo6PUKiU2N3Rz5PnjpEMR3ny3HFu2rqHbLHAvz3/sNtzr2rMZ9N0N7ZT0SvYzioNVtdNDG83rZbvquh/NBJhYOcWzp0YRAsGmBob98t3U1cnef7hpxnYvQ0tGMBy3LA/Gglz9vRFBo+fW5X/XiegWUXPqxdqNa8XRRHLM2otEMDMu40+luWy8to6OkgmU2RWMt7zTa/PXqRctjh3dpDdu/e6JSvT9IZuFtYAW7VdcbU5//ooAByW08u0Vtqoq69nfn6OVKqO9vZ2P09mo7C9BqwXBIFwQCAcEtyUQlwbJwiCQKlUXFdyvLbJ6JsZf61DXZUWc9Y4gerPoVAIw9DJZLKeY3A/y+XLl1FUzXcIoiitMf6q43TPtc673vX39+bzhR/Tdf1ffcLV94gDmAcOA/dXJa2297uz1G3L5tLYOMVSmeVMlhPnL3FhZIjnTpzhyuQMsqQSUAP0dPTS3tzA9v5u+jpa0FSZE+cv8Hcf/yTPHD1JZR0xZ//OrQQ0DQG3dFipMXpREFE9MLFi6Jy6conWpAvYHBs5RzgQRBAE7tl7EzPL8xy5PMir996ILEjctft6jgwP0hBP8dzF0wyOD9MQS3KodweG5ba+2rZNoVhGFMQ1DsCxbYKaxvZ9Ozn69GFymZzLZRdFxi4ME45FaelqIxAKcPzJw+y+fh+7BrYwP7XAmWNn3cETooBtmr5qztqGGasmDHdn9KmeeIgsyzWEliyWZVMu57h04QK2bVEulzAMHUmSfRBRlhXy+TynT59ixw63jTWWiLvdfqbhX+jVdKLqCKqGX/t71UGUCwWWlhZpbmllYGALgWBwDYj5zXJ2SYRkTERVBGwbRGF19686Anc+oe6TmzZyJrwMJlCbr68yE511v9trnG44HEHXDQ/8E3w84uqVMTKZDOVyBU1TvdLmqvFXCVJetBIIh4MfCAa1BcuyHvtucwDfjAn4hEf5xTRN9mzt44uPPEO+WCSfL7KUySBLEg8/8yJzC/O0NTezpWcLHc2N7NjSTW97M4LgcPjUIH/6la9wbPC8p5nXzS++/S286sZD7Nk2QDwW84k51VHP1d1AlmRM26ZQKXJ+coy2ZAMTS3MMz44zNH2VW7btQ5FlSnqFTDHPzduvQ3Ac6mNJnjx/nJCqEdaC7vu2dDG1NI8qJ+huaFmj5lssua22VdS7ZnvCdlyAKBAKcuXiZe5/+5s48fQRFqbnqMwtMD81y/DgRbcxJxlnrLuPSycurYpdeC22bAC+re687mcuFIqEvc7BandbtYxV1fZLp5dJp9Pk8+4U3Ugk6glurNJwV1bSDA6eZtu2HYRCIUKhIMvLy17+vlZ2e9XwHb97r8rqsywLQ9fdIZ9AIBjyy5f2S7QCrzVWUBVQFIFI0NXgl8Ta3bnaQ2FSKpV9Ca9rsIQN3sPZ8Hza68BN5xoCVK0TiEQifmdl9VoAAUVRPdEQ2UvddO8913Y6go3jOGHHcT7tOM5bgMdA8CslVQJVlVxVbXdffa/vbAfwMPBngGxaNs0NKVqb6hkZn6RQKiF6ffsBTePeW2/i0K5tbOvrJF/Mc/jUIB/89Gc4c+Eylm2zb/tWfv0db+fOGw+xo7+XaCQKjo1u6GuIOZLoioAYlkm6kOPqwgztdY08euYwkigx0NKJIAiEtCBzmWVCaoCQGkBTVBazaU4MnwYHOuqayBYLdNQ1cvjyWa7v30V7qom33/Y6FElCN82aFMAV6BQEN7eTPGZa9cITBJG6+jqiyTgTI+P80Nt+hMnGBm8GHsxNzvgnLD29yNCZy34zj10zWqtWMnujPnnHccjlssS8RiLbwwuqY7IikQiLi4tYlkUgEHT19eJxmptbuDw8RCWX919PkiSWl5e5ePE8guBq+gmCO1Czytxbz/xb3fmtmklABo5jEQwGfU49CGsYetc4gTU8AYdoWEJRQVPBtmyQNwrbHSqVcm1f1ctGFut5CK5RXourrA4ZsfzzXi0PVs95OBx2J0OpGuWyy15MJpPoeoVEIklDYyMjw8MUCnlXBt7nSFj+61uWlbRt+9OO47zRts1narsNBQHq6hqIxWLouo6uV/wx6t/pDuCCd9vtOA7BYIDtfV3821cf9wdmBjSVX3zbD3LTwT08+Ojj/MIfvIvLV1aJOf/rl97BndcfZGtfF6FgGMeu9qEXfS8oiCIePMxCNk2hUiRTLHD48hnS+Sw/estrCGtBTMvk2Mg5DvRs48TYBTrqmnhu6DSWY7OtrYfB8WHKRoW5lWVypQJ3776B1mQ9A81dLphnmQgIlO1rw7RVr7wOxwIUScIxLSrlCiCgIdLa2kL/tn40TePCiUFkRSaoBejr7MUs62vryLABQLU6Wnt1B3Zls+fn5+nq6sKyLQRWQ+1AMEgikWB+fs4jC7n6/D29fQSDQZ555qmaHd1GEESWlpYYHDyDqipEoxFmZ2c9A65Fyl3wr/q8VZ1BE1EU6O7upaGhCctaHbq5key3s+EuDZIEyALhoLAuN3eqOyiCwJoJvs4GDMaNmH52jUOtfp7amQLVtGD9wJHa5zmOTTyeJB5PMD8/jyDgtRTb5PN5enp7aWxqZGQ4CwgbGX/1tZOWZX3SsqzX67pxuqpCLIoCHR1dtLa2ksvlyGYzayYjfSc7ABN41KMEY1sW2/u7CAeD3Lh/N0Nj49x8YAe333AQgGVPP/9Pfu0XuPOGQwx0d6BqLjHHrdEXN+wYzBYLNMQSnBi7yLMXT3Ljlj3saOtloLmDR84cpimeoiGWpFApU6yUOXx5kLpInFu27uMrJ57hhv5dPHfxFPFwhL1dW9D6Vjn8ZUOvmfEIL11E2ni54J1NNpPlxntu5cXHn+f84AUO3HiAoBZgcX6BG++9jYAWQMhbRKNRnwZr1+5wNTuTZZmY5irivgq+uRfl8vISqVSKRCKBjeNr4eGBV4lEgpmZGSzLpljMcunSBXq6ewiHIywvL60xCIBMZoUzZ05z/fU3EggEyWRW/By+So9ddXwCmqYRiUQIh8PU1zcQiUZrdlJnDVlnjTHXMvhqIoB4RABVQFMFLNvxd+D1tfpKpYJTVV16SYey8QzB9RHNaipg1egS2uuc8OpnEgSxRkp81ThdufFzqKriYTQuV6MWE1iLP9jtjsODgiC8QRCE07WgprUuGvxu6QZ8DPj1aq28r6OVhlTcZdY5Dru39mJZOoZu8LY3vJaf/KHXe0a/lpgjCJ6wpyCge2QLURB44vwxzk2Ocn3fTjRFIxaKkCnmqZgGQVWjqJcA2NbWQ6FcJFMqMLE4S29TO//63EPs6drCtrYeepva/aYSw9upNswlv41l2RaSplLKFVmeXSAQdlV+bcuivaONN//ID3H6hdPk5byvO7/RpJyqwVV317VKOasOQdcrpNNpYrHYGoOqXjzRaIxKpcLMzAzgcP7cOdLLy970nzIg+n3uAE1NTXR2djEyMsKOHTuRJJF8vuCOSVM1QqEgWiDog47V/gVBFDENw83/PSq4dyDXIvC27bINN1AD9hodCQVFRMFZswPbNn7HZfW8qKr2kjv/Rjt5bU6/avyOX+Kryo3X8vxrux0Bf5RYIBBwiVSlksdTgJWVNLlcjlwu56cH1etrLVmoiqnYneA8iNsy/F01Z28jB3DYqwg02o5NNBqmp6OFydl5RFFgKZ1BFFyPXfXctUZf9X6GaXJhcoxCpciBnu1IkkTFNFjMrvBjt7yGh0+9wLa2bnTDoKxXePTMYV534Fb3i8RBkxW+cu44JaPMq3ffSH9LJzvaewmqGoZpuO/jPXYNGPXvcVIUmcWZeY49fZgb7rmVcCLKxOws7Y2NSKLElXOjpOeW3bydjXvua3Pq1RC7Gg2sOoTqhVQul67R4asNWWOxOOVymdnZGRzHZmJiHMMwiccThMMRcrksmqZRX99AR0cnmqYhinMMDw+xe/cef4erfmeWNwnYsix3KGhNedDxZ/vJhMORjUtv6wg5q87TJhYBJIFgAERx7fSfWqOsCpdWKwHOy7IXrw351+6wll/D3+hxte8vCKJHwXbpvsFgENM0KZdLPl6ieVTlUChEc3Mz09NTFIu6B4iuMf5qytHpOHwBd7bFxHezA0gDTwE/guOSZnZt6WFk/AUioRCDQ2PcceN+f1KOs45AoyoKoiDw2cOPYVqmf1HcseMgON6YK1Gis76ZiqHz+oO3kwxH+eRzD2GYJtFAiIphkIrEeOvN92JYFmEtQMXQCSjqKrf/38Hgaxljoid/LUoS+UKRJx96nN037KO1q51Pf/CTbDuwi9n+bqyszvLkIoIoIjir+T7VEVn+zm/6xl6d1Vdr/NXdvzp+W1GU1VJbTb5bRedt2yGVqiMUcunWmqb5TjcUDqMqMvl8wW9fNQyD+vp6JifLXLx4nm3bdrgS3d5k31qjqp7L9Yh1uVxGkuSaYRvrxDk24OPblu1GAEJtBHDtGDD3tgqG1nIMrp0ZuDpBqPq7ey4dRLEavts1Yb9FVbhkfcRgmhbBYMgXrqkKgrjjxlzBlerHq2o7dHV1k0zV8eKRwzXfyVrJce/nPY7jfFkQhNcDk98NDkB8ifsf843aMNg50INuVIjHIgxfnSJfKLocdz+LFFAkmQePPckjp19A1UKEA0G2tnVTH0sylXYHq6iyTFM8xdnxERpjSUzL4vTVS3zwiS/RFE8SDYa4Z8+NRAKueq4syYTUgG/09r/TLu8iwUE3/5Rcyms1KZYkiTNnzhIIB+nd1s/g4ZM4OOiVCmeePE5uLgOCW8aqVCoucu/1nVcNv6rb747nNn1O/erOb/mDPMrlCvF4gpaW1g17FsDxjTAUClFfX0/U0xhwuzVFVEWlobHJJRGZpr/rVSoVGhoa0HWDixcvunj+t1CKchy80Jg1KcnLjQIzLItEVAZJJRSQEAR7A1R+NUevHZ6yWiGpOj1zDW/Cj6osC1236etUCAXcDWa1p8Gq4VtY14Ts1ZFp7ugxzaf/CoJAPJ7wRF5N/7NWZdfi8TiRSMQ7v85Gxl99zl4QPiUIJL6bHcCTeDrolu1Ql4zT0lBHpWJQruhcnZpzZa3XoeeCIHJhaoyrcxPs7hzg4VPPY1oW+VKRcxMj4Dgc7N3O6PwkT188QVOijrt338DrDtzKXbuux3YcQqrmOxcXTLP/3T+047hEJ1VVasZTVbsgKzz/9Ats3buD5x95monhK9x2/92UMgWSsQTLK8t+3lwulykWizXh/uqQjqqQZ3V4h64b/jQew3ClvAOBAF1d3ezYsdPfbfwvRhSRZZlAIOgDdNWxWcFgEFVVEUQXyCoWC8zNzVEuldyR2VUtAcc1pPr6BvL5HJcuXlwVLVnPtmNVpKSGEuFO+PU4Gj6+8pIyY24qIQgSECRUyyFYs4s7NTMAxTV6gWtxEmdNlcNxbEzLplw22bc9gKY4LK/oYNcSm+w1Jdf1wKAoioTDYa89W/IlxKrlu1UnYPkU7bGxMU6dPEGlUqFQKHr8DGsDlqX7fyQSuaWurv4ztm0nvlsdwGXgWPVC0TSVbX1dZPN5wsEQp84PI8nyGujctEwUWaK/uZ1/ffbrJENROuqbuW/fTUSDIaZXFkEQiARCvOHQHbx2360MNHe4ElepJiSPr2//J6GlsiwRCgSwndUQVJZlrlwZZ3Z6hmAkTCwa5ffe/fvUBWI0RhsIhkJcGRsln8/7PfP5fJ5cLodhGD7BpNYJ6LqOaRreQIoI9fUNtLd3sGXLVnbs2El3dzeBQMCfdVjrAEKhEJFIxDV8b9qRoihIsoyqaf5AEtu2yedyDA9fZnJyguHhyzW5vGsMDQ2N5PM5xkZHrlHCeTlHadu2xyVgQypubYjt8hFskokgOAHCoQCCYGPZ63d/23e6rk5g7SxBew1RatXAXNC0VDK5eX+IUEjg+Nm8S9vdQNVoFQew1siVRyJhVFXxHI+9hgpd7bGIxxN+63I17ZienmJxccFLF6L+jMZalqVpuptVY2MDwWDwHsuyPgPEQPiOdQAvpQnoeKzAWwAs02Tvtj6eeOEk7c2NDI1NUKlUruFwL2ZXiARCNMSSPHXhOLFgmPd99ZN0NbRwoGcbuhdaxUIREqEopodcm85/bn3UcRwCmkrACwFNa1X2anllha37djJzdZK7X3cPelFneWaZRDJJsVRk27YdaJrmjiALBCgUCmSzGVRVRdMCNSU/V58gFAoTiUTQNBXBox1X80/JEwRxagzA8CS8FUVx9fSr+foGoJuiqtheU5EoijQ2NlIul72WXNE/huruWe9x+6t5bS1nYcMyH6sOIOjRgZ11Ofsq3941UMd2kCQFCHjiKB7q79hrtABN0yQajRCoUWKqjcZWe/tXHU2haHHvLVGiYYHPP7KEKODxGewNgcG1OIq7wycSSb9jsDovYNWZ4SsIJRLuEBO3J8XBcdwKim3bbN26Ddu2OXLkiB8JVB1JOBymrq6+6lDusSzr/woCPwXo300OoNod+HtVA+lobSQUcvXeJ2bnmF9Mu7P6vFHKlmMTCQR5w6E7CCgqz1w8ydbWLm7ffpC6aNwXufQJHd+sHl+dJ+AZjCfL5DcE/f92AKpKSNOQJJFiqUwyFsXBYXpymksnzyIIIu1t7QhFGxywHHfIhyLLpOrq3IvYMIjH48zNzZJOpwkGgyiK6g7DUFSSySSBQMA3elmWkWTZT5/sqnHWGLVLkrJX/7ZBqF4LgFZ3UNMwiEZjhEJhFEWhpbWVubk5lpeW1pBoksl6ZmZmEASBjo7O1d39JUpwLm9ff5lmnFphEQdJckgmw+AEiERtBKrin2ubkARBoLm5xZ/wU538bFmmH33UgoH5osFr70jQ1ijz4c/O+XtqdXBsteOzyrxzHeFqOiAIgjshyTtf7q7NBpwD25NFk0kkkiwsLGAY+irGYegsLC7yqrvuZmp6iuHLlz1nayHLIpFIlEAgSKVSqR7Lj5mmaYPwM4Ig6C+FwfxX8QRezgEcxtVB77Jtd6zVtt4ORifmCGgaZy6N0tHW5DsAAYEd7b2Igtum+qqd13kGX+V/v7IwqCrxbFsmS+kMc4uLlCs6kiTS3FBPMhbz2jt1/72/nSUIEI+5EuSlogGC+95LC0tIsszWfTsZPz9KV2e32zPglcpMyyKXy6F4QyqDwSCtrW3Mzs5QKLipQSqVIhyOoAUCaKqKrCirQpi27fcK2LWEGs/oq1N6qjvZyxl/9WdVUdzZe56hum3DJrFYjJV0umaXcvxIYGZmGlEUaWlp9UeW1xpBrQNwcQtjjZjnWi7+aujuOBaSpIKgIYg2miZhmpbfD1ANm9vb24nF4pirwzdQFJVisXRNipEvWrzhrji7tgT5u08sEAqFUVUZVVV87n2tEa1WV9xmK9txCGiBNSPP3LmJyjqHV8sadBWD4/E4CwsLng6Di3MNX75MfV09TU3NjAwPYxgmsuymbJIkepOcDAyj6gT0t1mWaeq6/lPlcgVwPNl1Hz0jENBW28f/E53ByzmAkhcF/HT1jh39PZy5OEYiGuXS6DivveMGnycuiSJ7urb6+Z7p/S8KAulCloqh05yoe0mJLoBgQKNYKvOlRx/n8ReOMzg0Sll3R0uZpoksCXQ01XPbob288Z47aW5s8Dz9t37CLMumuT7FifM2ggiGYRIMBUnEokTiUbSAxoJTJtEYZ258FkEQXZYeUCwUyOVyHk/fpLGxke7uHt9IRFEkEon4Cj1+TuqVC531Utgb7ADVC3HNjvEy8tguW83y2ogtxq+Ou8aTz/kKPO7z3BJZPJ5gfPwqjuPQ3NyyZiinZa9VAK4SZaoDRX3Z7fUtxraFIkEqGQVbRVYcwiGXUeeILqCoKCotLS0kEok11Nhq2hOJRCiVil5kJGBU4GfeHGP/rhjv/ZcMkUgSxdMZsGzXqdUesw82IiFIAoGg4uMPxbLby+HgOtqybuG2+nspCs6a9m1XOMRNB/L5HOVy2YtELQ4fft6TGHfl5UKhMJIkUy6XWVlZQdMCPgbkRQI/WSqVFrPZ7G9UFYxrJy8lEgmXK1PRv2McALgqQT/t1vgNtvV2YtkWiWiU4fFpMrkC4WAAq8rsskxkUcIRHDLFAvFQmIVsmi8ffxrTtrh16352d/ah13j9argfCAQ4PniW9/zTxxiemCOZqiPZ0OKXuhzbwbRMlgtFPvC5h/nXrz7Gz7/1B3nL616N+W30ZVu2TUNdAkWRqehlKrqBpqkcuP4gFy9dJhAK0L29n5ODp6mP1SE7kh+u4zgEg0HA7R5bWVkhGo2tqgILAoqqrjF+Z53x+2O61kterVoEtmn6YOuG8/HW3e86AdObkuMKYCwuztdIZof8nBoEotE4Y2OjSJLstcDaKBJIgkOu4h5b9fFVAY9VB7ABI8+0kATBjwAkSSSgSti2QzCgUVdXTzKZXLMTr/8MgUAATdNcgy3ZvP0HI9xxXYDff+8yhbJLLCpWbETbQsBCwiYk2wQ1h5AGqiIQ0gQkWUJVBDTJ685zJEqGg2m4JcRC2aJUNsmVbQoVB92RsBwB0xKoUrEEPF6D7KYD+XyeQqHgnWdX1ETTVJqbW4hEIkxMjGOaFlevjhGLxf30xgWEdQzDeKeX2v7G+tFrtbfvlBSgWg7MAjHbskklYnS3NZMvlbAsm8tXJzm0a6s/S12WJMYXZnn64gnShSy9jW10NbSyr3sr4UCQy9Pj7O3e4rUc1Bq/xme/+gjv+edPEE81sG3bdm+WnENFr2CaLitNlmVSyQSpZIJsLs+ffeCTXBwd4/d+6X/Aqn77K8YBIqEgqViMYqlMqVwhFgkTjUc5cNv1VMo65WKecycGCYdDdHd009LS6oeskiTR2dlFpVLxZL2DZDIZ/0vM53JuSOg5hVoG3RrgrVa2e52Wn2XbiBsJZLzEoE5BENwxY6bb0huJhJGkZkRRoqm5iXw+T3p52d8RRRFisTgjI8OAQyKRwrZMdg2onB2yWErbCIIbBZRKRQzD8NITcw1RpwrEGYZJQFNIJRPgaEiyTFdXOzNLEtGIGx6vb4rZuOnHoVSBN78mwmtuC/Hbf7lEeqVCLGCS0AxSMZGmOo3mxiiNDWESqQiRWJhQJICqaSiq27iG4CkTe2mCUM3xTQtDN6mUKhTyJXIreZaX8swtFJieKzCzUGJuWWchZ1PQQTcBbAJBd7rRysqKC+JKIpblArrXXX8Dsixz+fIlbNtiYWHebzVeO9pcfKcgODngj77TQUCAKeAF4D7HK5Nt6+vkycOnXVbgxRGu27N9NX8XBLKlPKZl8hO3v55/e/4Rbt9+gGQ4zldPPk1Lsv6aNwgEgjz46OP88T98hN6+AX+E9vziIoJjEtIUbMtiJaNj2g6WI9LY2EQsGmH7th186UlXvPP3/+fP+Xn0K3UAwYBGYyrJ/HKaQqmIYcUYGh1DVhRC0QjTo+Ns7dvC1atXOH78GDfddAv19fX+SO6qio7tONTV15PL5VabW7xdMxgKrZHtru0VqGr117LxalOCapvvKzH+6nNFUSIcDpP1GIayrGDbFvF4gra2do4fO+qnTVVprGAwyNDQEP39/YTDMS6OVrjtUICnj5rMzOtIIn4aEA6HyefXNtlYpoXtWGiaSkNDHWogDKigONSlkmjqMqLAmufU8gD8/wURy4ZyxeJN96q87TUy7//AJFuT8MZDETo6UzS21ZOoiyFpAUxHoFwyyOVLZFcKTF1Jk8+VKBXLlEoVDN30RqS5TkCWJVRNIRjUCIWDRKIhYokwdd11dO4KENAkJMeiUiyxvLDC9Pg8l0cWuTSyzPBkkem0gaJFSaQ0lhZnEXDP3+LiAsvLS2zbvp0rV8b8TaDqFN2uStEDtgUchwdwB4z89Xe6A6hGAfe5zRsGe7b28dUnjtDb3sbQ2CSlUhlJFLE92eW+pg6evXSKz7zwGIVKiUsz42xt6WRuZZm9XVtZzKaJBcPYjoOmqlwcHuHd//Rxurt7iYRCVCo683PTtDck0A2Bt73htRzYtY23//rvc/2enbQ2NvD5x54hFEvSWF/P1oGtfOEbz7Ott4cffcNr1zDXvtkyTYvutmYGh0dQVJnBsxd47vFn2HvjAcqFIgFUGjo6aWhs4sUXD3P06BGuv/5GEomE5wRc5Ht5aQkch1KpRLlcJpFI+MSecqnkqh6tn65T4whYFxVUjb96Ea3Xzbvm5xqMoIq7RCJhVlbcVMCyLMbHr9LT3UMwFPIbg2pDUE3TGB6+zMDAFkpljaODJd72Awk+/qVFxmeKiNiUSiXi8TiappLP5/36tyhJJCJJVDVIS3MSWQ4CCsgCoZDmVnxq5b991p/bCGQ7AjYiomMRpMQb743ws29Lcu68wJve1E5jWx22oJDJlJidWeLE4EWmJ+ZYWcq6AKVuggihcJBQOICiykQTESRFRJJriE2iQD5fYGFhiUpJp5gvUS5V3ME3qusYGprqaOtspK2ziS3X7ebgnSp2pczC9CLDF8d58eQUQ+MWZysaM4s5j7/h8Pzzz9HX209raxsODu2t7Ti4A1pXVtIsLy+Ty2V95qMgCH/lHdZff6c7gMeA/w1IpmXT0lhHXdJV9FlayTIzv0RXWxO26SLbkWCQZDhGf3MHvY1tfOXkM5imwfjiLF8+/hS3bztAKhLDsCwQBP7vJz+LqIWIRaNUKjoXLp7j3hv382vveBtv/Ll38sD7/4mffNPrGRmf4uLoFX77Z3+Kf/qT3+I33vO3zM7P09zYSHd3L//06Qe588ZD1CXjr7g6YFoWzY0poqEwuWKRxUyG9t5ObNuhOJ8looVdgpMic+DAQY4ePcKLLx7m0KHrPdVey68nz8zMsLAwBwjMzs6wc+cuPxIoVyou4uw5yo2YdGwwtKNaDbhmjDbXquesL92Jokw0GiWdTgOQzWS4ePEC6bQ7h1CWZcLhsL8bu+w3haGhIbq7u5mY0fj60yv88o/X89cfnObKZJlIpCqoEUGSXG0CSXYlzBRZwbQc19lJGggKSKLHf7jWAQAYFtgVi5Bi0h422NoZ4Jbr67jx7hbShUZIOCxOzfH85w8zMTZDPldAEAXqGhK0djayY38fza311DcmSNS5g1hkxQ3/ZVXyQ3CxWoESwDItX+3YMi2KhTJLCysszi4zO73I9MQ8J49d4PGHjqCqEslUnL6tXfRv6+TQPTdy22sFlmcXuHB6lCefvczzp2e5PFMilyuRyxfQDR3TMFhcWqS5uYV4PE4sFqOpqYV8Pus7gkKhgGEYfwUsSpL00e9kB3AauAjsdByHUDDItt4Ozl0eJxQIcubSCH1d7Ri+0Qn0N3dwbOQ85yZGaEs10NfcQSIcpbO+hYCiopsmmqpy5sIlDg9eoru7H9u2mZubZlt3K19+4hksx6anvZUnDh/jz/7vh5FEt17/W3/+PrL5PH/527/Czz/w5xSKEWKRKLNzc3zhkcf5hbe/FdMsveI0IBYJ01SXpGy4NF1Jllm4PIXsSGs6+wRBZMeOXZw+fZLjx49y8OAhVFVbQznVtACqqvoSX1XDtS2LiodyUyPU6azTEFg7cdc1tipgxiuoBqztpnPBq3g8xtLSMgDFYolSqYgkiX4/QS6X8yMNdyCIxdWrV2hra+fMRZloyOF3f7GVP3zfOLawOh82GAwSCIW8WZHuuHK7bJCIR5DVoJt/qyrRaATbst382+M+GBaYhklCKrCzU+a6/U307+iipz9FLFrmG49d5dnnTrEwu4ADdHQ3ceDmHQxs76Kzp4VQKEAwrCFIErZl43j8EL8cCJi6tVp5FmqcgIc7qaoCmkIoEqSppQ72D4Ag4pgm5VKF5cUVRocmGL5wlaHzYzz35HFCoSA9Ax3sPrCFA3fdwK333cDU6ARPPznI4ZMLlNUm0kUbARtVdZmdVVk3x3FVnVpaWqirq6NYLJHLZchmcx+oVMqm4/DJ71QHYHhpwM4qX3vnQA9HTl2ksS7FxZFxN6zxTrxhmgw0dzKfWaKvqYP+5g5EQaQxnnKNpbpDSTKPPnsESQmgyDJzC/Ns6WgiXyiyvJLhI5/7ituOGgqiGwYtjfW8485bqeg6I1cnmV1c4uff+kbe94kHGejro76+nidfPMVPvekH3IvxFWIBlmWzo7+bqzNztLU1cf74Mooj+yWhVY6/K9q5Zcs2Ll48z6lTJ9m5c5ffV14l/2iaRlNTEw2NjZSKRXI5d5akY9tUdB2lBtXfeF7AqqJNtVVWEAT/8t0IEHSjitXyVu3ry7JCLBYlnXaHY8RiMQzDoL29g+aWZo4fO0ahUPC65ywURaGjo4u5uTlCwSCPH9apTwX40Ht28BcfLjM+bRHQ3OEj7mxI2R98Yjs2siIjiAGP96Giqe7x2w6YtoBlWDRoefZskbjpxq307e6jZElEQyKhoMmH/ukFjp2cZfvuLu58zQG27uohmYyiBjS3kcp0YdRSseKH9S4LpUo1EdY1qLgO0xYE/LPonavqr1bNoB9XB0Gmpa2Btu4WbrvvBiqFEjOTCwyeGOLk4fN86sNfJRTU2LF3gAM37uBH/8cbeONKmlNHLnHkxCIXpgXUeDOJRJzxKyNeA5jtN4VZljvDMB5PEAqF1VKp9GFdN2zHcT71negAwNUK/KVq3jzQ3Y4kCYSDAUYmJlleyZKIR3ymXiQQ5AcO3ollu51bpmNRS/0TRIFyqcjZ4TG3HuzYVEpF9m3bz99/8rMea05yx3X5U3Rt6uJxfvpH3kA8GuHC8BgD3Z185IsPUSqXiYYjTE6MMTEz680JNF9xGtDZ2kQqHmXo9GWsvI4oiNgeT7xWLkvXXUJSd3cPw8OXGRw8w7Zt2/1RWrbtduC58lLW2n4Jj1JtW9aagRjrOfW1febVOrJcU0lYe+wOpmkjy3hKxw66YSPLbhtu1aFU6+srK2lPEchhOb3Ejp076erq5uzZMz63PZlMUV9fTzAY5PLlIXp6enjxfIT+Hps//uUov/f+HOOzJvGohiIrqw7Mc3KxaBiUIJgOoBCJRkAUMQyLOi3HgS0yt9y2je6dfWSLDk8/e466qMp9r93N6JUsnTt28Lq3vZFUfRwcV5TGtGzMfNGPgq7hRghu0U5wwPvnGifgeI8TNnIQ6xxqlRaMF9VKkkhXbyvdWzu5/4fvYGZinqPPDfLis4O8+PwZOjqbufGOfRy46zoO3lLk7NGLvHBshqmCgWEJVHQD0WM5rjoCy+8MFUVRlWXpH03TWKSmE/c/Y0kPPPAAppn+Zo9bwp0gHHIcCIcCDI1NkM7k0Q2D5oYUPR2tmFUeN2Da1kt28smiRCaf5yNf+DrhaMLlUKsC9ckYn3/kCfq7OvjdX3wHc4vLTM3No8gy2UKBLzz6BB0tzSTjUV7/P36V3Vv7EQWBkak5opEImVyWPQPdDPR0vWIcQBRFAoEgzz11lKe/cQRFcXf/9cZf2+Rj2zbxeMItq3lKPoCvNBuLJTAMg4X5ebLZDEtLS4RCIT+nX9Ndt0a9dr1Ap8tVD9QM0qxe87rhUBeXuOuGFK++vY3bbmrj4K46kjGJucUi2byByyquTvxdDUkBCvkC+XweRVFYWHBHk6uqRldXt0evdUeOTU9P0dLcwOkhi+YGmZ95U4oT5wxyJQlZXgUSsW30isG2rZ1cd8MucARQNMbH5jh15EVu6tN5yxv6ufP1N+AEYjzx6Ek+9/GHiYcDvPnHXgWSihZP0tPfhiJLnoaC7Yq+eIYqVMHEdU6gdvffMBKo/XW9E3gFJFVfWt0wwYF4MsqOA1u4/e6D9G7tYHZ6kScfPsLpo5eQQ2F237iLQwdaSIqLLE1Pc354hnSuRDAQuMb4q/9blqnZtv0DluUcwx3T9x+yfuu3fvPbigCWgOeAN7g0RpFtfZ18ffIokVCI88Nj3Hbd3m/pQAzTomKYyLJEqVSmLRVndGKKPVsH+PjfvJtMNs0f/58PrFHsbWts4Or0DENXxllYTmNaFm3NDVROXvIVgpZWMniTKF4BHdjNBT/zma/xlS8+jqYp3k59rfG71GM3CkjV1XHgwEEuXLjAk098g5GRETo6OnzevG1bXnnPYm5uzqd+NjY2eXRY15m4Sjis6XirvTmOTalUJBqN1nTwuS3ae3plXntLHd09DcQa6ok2JDArOvu3xTi0NcBHvzDExXETTV3lR7gKP2GyWXfs2eTkJJZlkslkEEWJvt5+IpGI93iBhoYGDENnePgS3T39/OOnstQlw/zp/9vDe/5+jktXigQ1sYa3YBGNREAKgVkBW6GnJcTP/nAze27aRcGQ+PpXjnH0+UEisQA/9JZ7ePWrb8SRLHLlMoJgo5f1VWMWq9bp4NgCiG4tv4qjrFZHvPFj/46RwMvhRqZhguEKqO67fjv7rt/O+MgUj3zpOR5+8BmefewYt997PQfuPMSuQwMc/PIRPv21yywYJoZpgmOtN/5qt2PSts1PmqZ9oyD8xzmBNRvgt/DYh33jNUz2bOunouuk4jEujkyQLxYRxW+BxeSs9eKmabFrSz//+r4/ZWvvHp568SSLaZdwIQoiqXiMAzu3k8nlmZ5bIKCqnm5ArTz1K6dQVglIn/vcI3zgA5/xRUVrDX8j43cch5bmFmZnZhgbHSEUClEsFhgfv+p/oe6IKZtoNEZfXz+9vX20tbWjaRqmYWCaFuVyxSulrQpYrJ/X53biuYrK1YqCbtr0Nhjct08mEhQpVWwqukW54jLbShWLVFzlx+5pIKGkKRQrHn3V7VJ0NQEjXinO1S+or29gz569tLS11eAM7mDSnp4+mpqauXplBMs0+KsPzjMza/JHv9nP1u4QhaKxWsGwHU/fL+jyAEyJ9p4Ott98kOeeG+Jv//RjnD5+gde/+VYeePev8pofeT2OKJMtFf3GH8fDM1yjrxUNd1j9k7MBAOr/g7PaOMFGpRM3Zdn4b9/Ksm0HvaxjVHQ6elr477/xo/zx3/4quw4N8OCnH+Pv3vMpRidzvPEn7+e97/5BfvSOBBFJp1Ay/KlQNcZfFY1ptCzjrdV28n/v27eLAVT5AAagWLZNQzJOU10S3TAplMpMTM+ztbcD3X5lubciS6iKK7ygyDLT80v84S+/g3/5/Jf5yTep3HxgDzfu3Y2qKoQCLkdfFAXyxRKnzl9CVRTKFZ2ZuUU0TcXx9P3rEgn4JiIiVerxo48+x4c+9HmvPVd8WeM3TZNsNoth6Jw+fYpMZsUfbKIoCrquMzMzTSpVR6VS9iSmTF9cIh5PEI3FGMpfxPLSCJceahIMBjw23erF4LbXrh4LHjlVpcihLhvLDlMumyhlnWK24JJxTItyoUShUEZTRF57XYz3fW6chuYOn3DkVhZchSHLMmlv7yCVqkOWZRqbmshlsyx7VQO3d8EkGo2RzxcYG7vMwMAO3v0P47znd+L84W/s4YG/GOT85TTBgASiSDgSdTkAggqyTKEk8cH3f57F+UVedd913Hb3fpqbuxClJGYuR7aYdj+ZWHXmHg1XcCMA0WubdhCwDMOlT4vCf0okIAgCkqquznvwnPU1vsPB5SLoJo1NKX7m197CPT9wC5//2CN85B8+z849A7zmh27np3/x1ezafoIP/dtpDg+VfLrxtVqR1sB3GgYA7tzA1wNtAIFggKnZBa5MzqJ6LZl7tvev6e56uVY8RZL4xgsvUtQtQqEQcwsLHNrRzz9/+kt87cln+ekfeQOXr4yjqSqa6o5xRoBYOMSRU2cplMr8t9e/mq8+fRgtFEUURDLpJX7yB19DMh57yaYjQcAz/hd43/s+6s3Yk17W+KutnbbthuS6XvHVfC3L4pZbb2NgYAsjI8MUiwVSqTri8cSacL5cLiGIIqVSiUKh4AN/ul7ttJN8BWGXz+82FtXXN7hy4baNYUF3LMP2VhFHdKmo7sVpo1d0ysUSpUKJcr5IIV8gqsHghUmm0w6RcNAHJ0VRJJFI0tjYRCRS0+DjDe3U9TKqpoEn3Z3LuROJ3I7HLOFoPacv5Lnr1lbuvK2Li0MrjE9laAtmeO2919PUucMbEKCxND/PzPgJ3vYz97H/uq2Ewy2oWhu2qZMtTGLbhlupr5nTIIoujdeq6KSnp5kZusz4yTOUc2VS7S3YlvMfjgnIqsL4mUtceuZ58stLGJUKiqohaxqiJK2ZY7C+icsyLJJ1cW666wDd/a0cff4sTz30IqIa4Ma7r+PWg62I+SUuji2TLbo9DWtVo+0vOY7zlPOSykvf/u0P/uD3v20H4AA9wG0u7dflwz93fJD6uiSLy2luPrDrFedRoVCIiyNjnB2+SiqZoFCuUC4WeOvr7+Uv/ulj5Asl6pMJMrmcS+FUZBzHZiWT4+T5i9xx/QE6Wpv5xotnaGxoIF8sEA8q/OSbXu/vDBt2HAZDPPXUi/zVX33Ia+N8eeMvlysEAhq9vX1oWsDX4a9UKliWzU033Uz/wBbCkQiapjF+9Sq2bdPW1u4j+Y5jU6noZFZWmJqaJJ1OUywWCQQ0X3CjavDVi6AqLJFIJolEIi6XQK/QGVymKSnjILpyWw7YpoleqaCXypQLJcrFIuViEXSd6fkcx4eWSSXjnhxWxI1GolE0TfMbXEqlEqVSiampCWZmZlAUFVmWKZdddmO5XKZS0ZmamqK5qZGKIXP+UoZ77+rjjlsGMNNT3H4wxME73oCsNQISIBIOB+jvM9ECIqrSQjjW7zUqDWNaJbd93CvVCaL7czmTZe7yECNHXmDk6DEmBy8xceoKnXt3kOpsWTU+zwkIntKxsN6+X4kTcMc4e/95DTkIiJJIfjnDcx95kPmxYeYuX2BhbBS9UERWVLRI+GUbd2zLbSRq623l9rsPYlomD33haYYvTjKwbyv33LePrpjJ0NAM4wtFWE0DC45jv9NxnPn/CAfwh3/4h9+2A8D7Vn+8mm9HwkGePnqKRCzGxMwcN+zdTiQUeEU1eEmS0FSJrzz+LIlEinA4xLHBs9x1/X629Hbx/o9+ismZOXTDHQ2eyxcZm5zm8KlButpa+JWf+FE+9LmvEU3WEdA0Jqcmef0dN3Db9Qc3zHWq5JWjR8/wl3/5Ycrlso90b2T8rqCnW4c/eOh66uvruXD+HNlsDl3Xqeg6111/A9u273ApwKUSyVSKkFc+03WdpqYmTxLM9HfZUqmMIEAoFCIQCFIulbG9mr9h6KiqSiKRIJFIEovH3a5Dx0GSZcqlAm3aIomQ4jUVOTiWjWXoGBUDvVSmUixSLpaoFCsY5QoL6RJHLizS0dFBfV0dkWiUUDiM4mEopZKrcWcYJoVCHlV1B6Hmclkikahn+C5Jqlh0HUUoFKalpYmrUwVmrs5z9x0DXHfzLrR4DFvqJBxr8xpxRHBsssunCYbaiCZ34jg2mfR5dH0FQZTxTVR01Yby8/MsXhljZWaKwsoK5bxJOe1Q19XJ/jfeCY6DWdE9MVYPkyqVXacgCIg1vRMbOwHvd0+JyawYmBV3sItlmJiGgW3aiJJIorme9OQii1cWUQMqiBZWuUQpm8MxHYKxKJIivyR+4HiVA0VT2HVoO7v393P0+UGefOhFIok4d95/E9dtSzBzZYoLVzJYllVxHOsdjuM8/h9h/I7j8MADD3zbGAC4IiGTQLvtOETCIbZ0dzAxu4iqaJy7PMa9t16HaVW+6Qvpus6hXTvZu6Wby9OzdLS10dbWyZ/8w0f5g1/8Sd73+7/BX37gYxw/e8HtDHQcouEQb3ndvbzxnjv4xIMPU3Ek6iIRCsUiChY/eM+dvnDDtcYf4MUXT/Hud/8zuVwBRZG/Sc5vkM/nqaurZ2pqgtGREebn53wt+4MHD7HdM/7qZCDTMOjp6cWybE6cOAZAb28flUrZr+9Ho1EMQ6epqZm29nbOnR1kZWUFy3JTobq6eqKxmD+/rhqaC4KAJMsUywaVcgXJ9i4w3URSXCYeVeKSbqCXdQTLpFjUcRwLWZbdFlVJ9GXHHU+aqyqzHQyFaG5uIZ/LealAxXdMhjcwxC2dCugm1AcKNMgllhcXqO/eQX2qG0NQ3H1CEMGxEBCJJXYRirbhOJBJn6ZSWkQUFa/92cv5bYdKNkc5m8O23HKbrSs4FQtRsth9341Ikkh6fBYBgUAsTCAWQQkEUIMhbMtCL1YwKxUUTXY/54aYgBttVIouoKsGNM+IHUxdp5LPU8kWcByo721j7/23MXvxCmZFxDE0Fzd3bPLLS8iKQqqnw+/4XIOuSyLZhTSF5WXqujtQggH6tnfxwHv/J5/+l6/zmY9+nauj07zhra/ib/6ynuZ3f4HjZ2d/v70x+K9OjcNyfECUDRWi/rOIQNWV9cDAt1dR/F1bergwMk40HObs0BXuvuUQa2ZzvUwaIEoSv/KTb+Ud/+vPyBcSRMJhnOY2/vD/fJj7b7+Bd//mLzM0Ns7k3BypeIwd/X2kM1ne99HPYIkarc3N2LbN2JUr/Orb3khXR9uGzUDBYJBz5y7znj//ENls3lMDtv1e7WoPfdX4DcPwUfpKpczU1AS6bnjGX+HAwUPs3r3H3Tl1fQ2BxLIsenp6KJdLnD9/DlEUaWpqxrKMNaq16XSaga1baW1rZ3FhkUQyQUtLC4qioiqKi0t4xu94iLMgqixkTdfATU9DUDGRZNEfdOpYttcObCI5FtNLZb8EqAY0l8fglQXLlYqvcBsKhdjlDRBZWloklUqxvLzsYxTZbMbnEETiKerERd5wZ5zr7rmeZ549jvPEIG96248SlMpgLoBcD9gI6IRivSAKLIw9RaVyFUULraL8btLv6R/oLhPStNFLApYhY+kGvTfuoXlrD+MnLqLnS4RTcV8yThBBkgQEs4IililnVigWQ0Tqm/zKQa0TEAWR4soyZn6RUCIOZhCHgHukxTJ6rkgpW6CwtMLK7AIDtx9k92tvYfChp7GNCEZJxDJdURW9XKa0kiVSn7oGaDINE0GUUMMh5i+P0LxtCzgOsizx47/0Q/Rv7+JD7/scs1ML/Ng7Xs8fvuvHef6Lj92Znp7/uKKpM6LodRR6fSKW5WB58yT+q0DA6ooDP1iFP8OhAN947jgtjQ2MT89y68FdLkPsFXgqy7Joa2kmElT54qNPEI8nCYdCxGIJzgyNceTUOcqGTigYIJMv8uTR0xw5N0yyrom6VBLbdrg0PMzt+7fyG//9JzwRzGt3/kuXxvizd3+ApcUVVFVeRXg98M8NgV1HUM3H3em4lr/rVeWlDhw8xO49eymXy+iVyjUMsqoTqG9oRBAELl8e8kP+Vdksm2KxSGZlxd+F4/EEsVjcpwA7juOq/tYShRxYmJ9loMHBssA0bSzTdi8Mw8LQDfSKSaVsYOkGFd3kq8cXyJUstmzdRiAY8jkBhmGwuLiErhtkMllCoQimaTE4eI7p6VnC4RiWLTA1M8/c3ALFoktp7usb4I69jbzlta3cdP/dzMwYXD6/iBaqY+vOPYgCYBXdKoBdAbsIooplZiiuXEFSg4iSuAZaEkQBxzTQC0UqxSLFdIVyrkI5lyfe2sj+N76K0kqO/PwSsZYGYs31RBqSaNEwgq1jlTMYuQX07BJSMIwWb/Dy+muBQccRkFUVHAMjO4etu/m3KMpo4QhqOIQcUJFUmdJKFkES6Tm0k8zsIvnFNGoohKwE0cIBFE11FZrDobWphyBgmxbZmUUCkTB6IU85myOUSgDu37q2dHDg+m0cfuokzz9+kq4tPey5Zd9Aem75nvmZxccsR1w2bbfsa5g2umGjGxa6absM0G/zdssP/c+1vspxHEqlb2mcWRfuBOFg1Qu/5x8/gWXBzMIiP/ujr2f/jgEquvEt1eP/+VOf528/8XlaWzupSyV9bkDZC5/dllUVTVXdxpZSmZHREW7bv413/9avoHmyYdUvW5QkVDXA5csj/Mmf/APT0wuoquwz7kDwyDUC+XyO5eUlb7KryM5duwgEghw5/Dzz8/M+JffAwUPs2bOXUqlExdsN1zThVJt7qscgipw/f47Ll4doamqmvr7Bmwnglvo0TUOWZQ9TqNDX00c4EvFDvar+v+U1FAmCwIULF7ihaYYbtiRZybu189qRbFWjigZFHh9M89kXFhAljde85nVIiopl2pTKFVbSi1hmBV0vkl5aoKmpAUURSERlErEA/X3ttDbXo+sFRMGmvi5JY2M9vT09tDXGCKcagQCmJSGrEdAiLv3XSLvVYjEAaCAq7tmwK+79dhnHKmPbRWyzgG0VcRwdSy9QKWYorGRYnlokPTOHqZu07d6KrElIWoBQNIoWDaEENGQPq3AsA72YcyW9pACioiLU8M7Xzzlw/3Fl22zLwDZK2KaJEowhyq62oqnrWEYFs6xTKZYpZLI4psOV4+fRSyXiDXUkWhuJtyRRNJVYS/P/x95/R0mW3fed4Of5F96lN+V9+6ruBhqNBggSICgaUCSHICkOVxyKojRmj+ZIGs3ZmbO7s7vSWe2uNDKrkThajSiJThRBkQRBAoRtAG3QvqururtMl82s9JkRkWGfu3f/uO+9eJGV1QBIAAJHynOiMitNZGTE+/3uz3wNdt5VVujx7ww8n9svvUVxooqdtxl2OrjlIrXF+fR1sl2bTrvHP/t//QZvv36Nn/ovfogHHznCS3/09I3rF9/5Mcsyzye4h6QCiKI/nU/G3/o3V/5ULQAoodAXgQ9KKbFti9NHD/LVly9SzOW5cPk65x44FUMGvrGNwHDo8Ys//RMcPbDA3/vffoO3Lq0xNTlNuVRKIbRIJTm22+myubWJIQP+6sd/kF/8+I+pJzxe59m2uuB2Wm0uvf4m//pf/i537mzgOFaG3KOAGIah4zgu5XIZXddZXl7i0KHDTE5O8eqrr8SKsKOT/8H45B8Oh/t62WelvRJV3xMnTuL7Hrdu3cJ1XWq1Gp7nUa0WaTTqaJqergWtOLklCaATqwrZsTy4kJL5+UU+d36J2UaPU/MldnsRfkiqG2CZUMwZvHajy9Nvt5mecHn83FE+8N48OTuikDcxtRDbrFEuGpSLUC0/QLVcpFwpYtl5LCuPbuXAzoFbAj2vgD3SBGHFN9XnmZYOhOD1FAXYLEPUU3MA3VQ9s/DU98Ty75quYWgWhp6P73MItk2hkKNer7BwcJIoPAQyIAyGDHs9Am8XU7ZxoiKybxEOHYTmILDRTRtNN1TnGUUKMRhH/P44AcW70DQDwymi24IolITBABErJQXDkK2bqwzbPQzbJF8ucer959heXmPY6SEiiQx1tLxOFAR4HYnpWmiGng4b+7sdpJRUZhsUJmoM210G7V1y1YoScR36lKpF/sb/4xf4l//wE/zaP/89fuI//wHe80MfOmyYxievnb/8Mc0wzn87RcLMP+HPfR74ICha54OnjvLHX32Jg7OzXL25zHDo7TmRvn4SGAwGfOh9j/PIfaf4gy98mc89+xLX79xSRpZCQWk1CY1KgT//PY/xY9//vZw4coQo9JXUtmWyvrnNS2+8yZdeeIXzFy4hmgEOxp7gH3n1DQY+nU6HYrEUtwMBa2urXLz4Bq1WizCMCIIk+B9Ws4HBYLSGyur77RHlyJJ3Tp++DyEEt2/folKpcPTosbHvKZVKAExPzyCk4M7ycipP3Wq1KBaLqehnPucwfeAM/+hTL/LDjw350EM1yjH3PRKSVi/g91/a4VMv7jA/k+fJx+f5nqfuZ6IOlYKklA+YrFs0qgVKFWuEfjFzYDlgu2C4IG0QOngeRBKkr051XY6OU01XiUDTYsCNTNF4aGoNiGaB5oPwVQKQAcgwcwuAKP44AlNDs0zM0IAowDTBdQxEqBN4HQbNFlgFnGoDXQYIq4rQNBTbLA50wdcFC2m68oLUpFL6NW0QQsfUdYadHnfevE7kB+RKBdxSDrdYwCnnqR+apbu9zerVW+i2Rq7s0Gtu4+0GNA4v4BTdGMSkeB/dnRa6aVCcPIym6fi9Pk6pGMu/S1bevk5jcYa//Dd+inKtyG//6mcIgpCnfuCDB9D45NVXL33MMM3z320J4IvA/x3QwihifnqCcjGPYRisbzVZ29xhYWYioxHwjb0NBkPyuRw/9+M/ysd/6PtZ39zm1soqzfYu+VyOg3MzzE5PUiyUiKIAzxuw02rz3Gtv8MXnXuLKjVvMzUxSLRSxexqabt1F6018+xQeX0302+02Qghs28Gy7HQ+IWXEuUcf5/77H4jBPMORPPZeau67ONtKKTlx4hSe5/PGG+cxTZOFhcWUWJTAftvtFqWyEltRcwClv7e9vU25XMbN5QiCgNnpCaLwYX7rmfN8+qUdDs/mKOUNWt2IG2tDugOVoJZWfS4vT9F5WsMP+vS6bQJ/iGlKjh87Qr2e5/aNN9BEl2rFplp2qFVzFAoWxbzNZKPKwsIch2Zr1KYmgZDQkxiWhmZZCkguUYFr6BANVPQZThyQAjQTjBrIIUgPTB0iIIqQkYcQHkRDICAKPZrrSww7bZxSEbdYxC5Ng2Uz6O3S3WnRbfbxfZh/YAq3EP8eGSFl3PNruspD8YBRnfgjsJAWD9c0XX2vlIBQhia6rhP4IUvnr6KJiOp0mUKtQq5WA0PH73bZvLbC1q0bDAd9jr/vLEF/iBQBuhsiCZAyR3+7TTDwEGHIsKOcsyePzONWynjdLpHv45ZLrLx9jStPv8RjH/8BDMvip37xRzANg0/8mz8GJO//6AcOiED8/rU3rnxQM/Rb300J4BWUfdgJBerJcfroQS5fXybnuly4fI1DCzPfdAJIAm8wUOajs9NTLM7NoOnxaYKBiIb0+j0cy+KXf/MT/PvPfJHDC3McWpjnwdPHaLY63Ll0Bz2USsb7HsE/mvoH1Ot1NE3j2rVrTExM8sADD7O1tUljYoK5ubk0QJOJfzKZTVVuErGL1N32bptwKSXHj58gDENef/01DMNkdnY21dwXQrCxscHm5gbNZpNer0c+X4iHhxFbW5vU6grzEAQBU5MTPHbuLO9cu87r17bI8q0Nw2BmZpbTp89Qr9cJgoBet0MUeXS7HSSwttHm7cs32VjfJAgV1iIMIrq9Lv5QkYVKlQZ//oMn+cs/c5Ijj9zPH/3uszS3d/mxH/8AxVIFTbfJlSvkCmX8pkaxNqmGf1KogMdTsGCrhmSW7toz7K5fQTd0DFfi9Vt4A59gMMDNF8k1KgxCjfXldcoNQd0uYxgubqGEboKpNXG0Fp3tNp0VHef4KRACw7LQTXMs2MeQeX6A1x/Sb3fpNNu0tnbo7OxSKgjqdZ3y4n1MHjqMbhi0lq5hBe9QrhfI1SyciRxWsYQ/9JBhhD/00A2Tk+9/P4blsrG0DIbEcAx2lu6QK3fpbXbw+n2cYoHm8joiiuhsNSnPTsUrXZU5b716ifbGDqEfEAyHoGn8xM//OQB+5998FtM0ee8PfvCg7/m/e/Pt6x9F0zb/Q0KBx+IUuB94FMDQdfwg5OWLl6mVy+x2e7z34TP3hEt+g9NBTMNAN2zCwOfm8h1++48+w9/7F79KIe9y5vh9DAZdyqUiUSi4dP0mF95+h9eeu0i/1Vcl3j2Df0TGOHb8OA8++BBbW1usr6+zublJuVzi0OEj1Gr1jFON0vfLlv7cQ9UnufDU2m/kcyeloFqt0ul0uXnzBqVSmUqlkgJthFAkoc3NzTiZaDFeQVUrvV4vlUn3PB/D0KhVq5TLJaamppmfX+Dw4cOcOnWGY8eO4bouvu/T6/UYDgdEURjLlgmGgz69Xgddl2iaxNA1kD7dTkshNYtVvv+J0/zcD81z5v1n+dQnvsrzX3mVp95/inLRorPbpN/dIV8BGKALB7vSiKGJSVnvI4JdAs/HzE0SRYKt1S9j5gT5WgnTNpEixO92aK7eQTdNKrOzlCam0HUD3TSwc6qkDocDAj/AsPPkJuZx61Mpl820bUzHUcSx9KajG7pyTfJ9Bu0uzbVt1q4vcfPiVd557W385jJRpGMVp5hYnEFEEW6xQHFqEitfRrMKaIaLbjlIEeEPh5iWzeyZ09Tm52neucPq5beRYYBbKJKvVrFzOQzHRrdCJg4dYPXtGwy7PeyczeShBXTTwjB1As/ntd9/Gk3XmDt9FDvvpnOl+8+dRArB7/7655iam+TcBx+Z3b6z/nB7q/UJIPzTSIfv3QL8SRMAgAt8PAEJFws5vvD8K8xMTHBjeYX3nbsf17G/KeCCrmvYloVtqxXcOzdv85t/8Gn+2a9/gt/+4y+w2+ly//Gj+EFIpZSnlM/x65/8DAfmpvm5H/shOnfatDfbMQjk3ie/Wv0NsG2bmdk5rl+7xptvXiQI/NhEo8qBgwfHTnRN0/CGw30lrfd65SWCHlmL6oTkk/T87XaLpaXbTE5OpiKjg8Eg3ck7jsvhw4ep1+tsbW3G8wg1s7As5SnoeT6e52HbjlL4mZmhXK6k84IoUmvMwWAQW14rM1OQWJaTPq4E2ry9vUUYhhRKFT7w2AP8hY/UefJHnuRLn3udL//x1/jYj76XkycPEATqOahO1alOT5LPzeKUp7j85S8iQ5/CRF0lAQ1Cr83Lv/8vqM0uUpg4TnViDje/i1MsYLk5jMTWS4Ts3L7FsNelPD2LXcinkl/+0FPGm2YBzamiWcV47RabuloWhmmoKXmMcxBhqARYQhHbwOvYOYdircLEwjSLpw5Tm5tn6DuUGlUmFqdVG2CYmLkyZnECwy2jWco/UjN07Hye4kQdt1jk5isvc+PllyhUy1RnZyk26uTrNdxSCdsGgg4YGrlyjZW3rxMFAbPHZzBsG92w6G63ufi553HyNtPHD1GcqBEMPXTTBCG5/9xJtjdbfPp3v8Khk4e4/9HTR9duLE15/eEfJFZzf5Lb+/78f/MtaQEAvhwThOqRENTKJY4emKe920ND58qNJd778H1E/tf3RLRtC8OwGQ77vHHpKp979gVee/MyO602hxbmOHv/KSRw5dotvvrK69imybn7T/HI/Wf413/v/4Zju/zDf/ArvPTCBax41Xfv4FcrPaXtrtNut2NariL9HDx4iOnpaSXpncvdZVqRSHxllXrvdsvde8vaXYdoGjz5/qc4//rrvPjCCzz+HqU0rOs6zeZOyjIcDoecOn2a7Z1tlpeWUkGRlZVVGo262imLiEajjmEY+J4XK2BrcVAP8bwhEpiYnERK2O3sYsQIwyRheZ7H1pbaeBRLZR4/d5Yfeszkgz/yBK++fJ3PffKrfPjDD3PyxCKDgcI+2I5FeaKKY5TQjRzXnn+aZ3/r1/jQz/8Sk9oR1e+jI0WI0NrcufrvOJz/C9jFA4TDJls3voaIJP5gSOR5qp2oVGkt3SL0PObvf1AlcqEqFHSFwENIZJYApI24vFEYpShCmXxvctLZFgXbolAtowGGabB6fZnttfOIMIypx3EyjyLQohQynBxyyeN55/nnWXrjPJWpWQyrwKAzJPC3sFtqYxAOu9iujhb2KUzmOP7kw9x8+U1ay8s0DupY9Wl6O20lMAIEA6VF6QuB8AM0xyYMQn7hv/1J2q0uv/HPP8lf/e9+hid+5EN/+cu//emLvc7wH49jKb7zMwCAzRga/IOJbNKpI4t89plXKORzXLxynSfOPvAN4QBee/Myn/3q87z+9mU63T5HDizwvnMP0usPuLG0wme+8hyVUpEnzz3M//Hnf5qji/MKTBITxP/u3/1f+exnn8V17a/T8wcMh4PY8txhd3c3pd56nsfi4gGOHz+B5ymEnwIDjWS5Hdcliq2475aQEmPmkurjiDAcTwRhGFBvTDA5OcX7n/oAT3/pi7zwwvM89tjj1Gp1bNtme3uLVqvFzZs3FGfAcWODyhiG6/usrnqUy2VqtTpuLj9uN5aaZCpA0eTkJIuLi1y79g6OZRNGYao7qABBKvjdXI6HHjrLk8fgz/3YOZZWu/zub36Os48c5oEHDzPIbHcK1RLFfAlDt9m+dZnLX/sKIhJ43Tbgq9WfpqNpQ6aOLxCFHpu3P8f0oe/FrZ7A3Fjj8vO/R7/dxesP8XsD/KGPiELcpRaV2UUq05OpToAWw4ZVNaYEhzSIyUTaGKlfMi61roRZExcmVakZgU7geURBgGErTTUZiXg7sD+VWNN0es02l77yKq3lbdbsFrZj4xRc7EKefKVIsV6jMjVJrlynNFVl0O0ycUSSr5XQLB2h2fSbHbxuP/5bdMKhSqoKpxFiOLZyJNI1/su/9TP87f/un/Kb/+JT/NJf/yke/tDj/58X/uiZixLti98KE6E/TQsAME3sGaABOdfl6RdeY25ykqXVdd5/7v4xz/v9CEG2ZfJv/+Az3L6zxtn7TrE4N8N//0t/kc9+9XkuXrnO+849xH/1sz/JL/zkj/K+c2epV0pKfNJQZfA//ae/yR/+4dO4rgO8+8DP83ymp6eU9v1gQKezixARg8GAubl5Tp06nc4IivFqzo+HdMnjtWJL7+FwSBgGd1lQjwe7yFhXR8o5J5djYWEx9cKbnpnhzp1llpaWqNfr5PN5SiVlgDocDrlzZ5nNzQ06HeVNlyj4JvbsC4uLmIZiSspUXEX93f3+gJ2dbUqlEuvr66ytrhCJkdpQEASsr6/j+x65XI5Hzr6XB+Y1furHj+FOzPKr/+vvUy3afPj7zqqfiecfmqazcGiOvJtHGJL1W9fZurVCt7nL4pkTTB5eVIpARIiwj5kzmDx4EF0f4O1exdLzlOZOoUU+m7cuE3gBIohwK0VmTh7lyHvPUZyojhyTY/x+whg0bRPbdbFzLk7exbBMNDRV8id2ayJTnaWne1wZSIW2391uUapXOP3EWXTTiLcG+zMKk8RqOTYzJ47iloqEviISJahSK6fWhfmihuMKNMPELVVjYXKBZrmgGXRWt+m3Oqxcuk6ulKc2N0VjcVatDmM8ixY7Y+UKLmceOsZn/v2X2dnY5cmPPmEE/f5TW8sbv6Ubeu+bbQHe+7H/+ltWAQB8TiE8MMNIMDtZZ6quePDtTp+l1U2OHZon2CPQ6dgWumExHA54/e1rSAmdfp/f//yXOXX0kJL//is/j2vb2E6OMPSIwojBoKcQgbbF6uYWv/s7n+dTn/oSrmu/S/AH6ecmJyc4e+4xVldW2NhYRwjBcDhIJ+aK46+08ZI+OnnhvRj8Y9t27AJr0Gq1GAz6Y+60ez3zpFTmIUrPQGNufh4tWTMKQaFQ4KmnPshXv/plXnvtZR544CFqtTqlUolCIU+n02FlZRXoMDk5RbVaja28NBYWFpRwqhjp8kXxqR5Fgu3tbTxvyPr6epysZOp8GwQha2treN4Qx3E4e+69zNVsPvq+PIv3neY3fuUzDLpdfvgn3h8bvipkYyglE5NFKnkLYUhCfHVyahqaoWPljHgdGMSleKCQe66N5dTxd24y2PwkRf3DLD70fqT0aG7dJF+tkKuUsFz7Lt9ADQ3TMrELLpbrYpiGWuPFCkIiEiOHpVhFKFEIkhmj09HzJAllSG1mkvJkPf0+wzTQNAOZvH6Rur+RNJl6K03UePAHn+LM9z1Ov9Wlt9Ui8Hy89gautkalrIaBdt5GMww1oDQtmssrdC2D6swU9fx0KhorwkjhWSwHwxhnF/pDn7mDs/zS3/xp/sH/9CssHp7hPR964sj26ub/d+vO5k/ppiH/Q7UAoPwC3gIelFLi5lyOHZrnwqWbFPI5Lly5xqljB+9KAK+9dZnPPfMCz716np3WLg+dPs4Pf+9TPPHIgyzOzeD7QezMqjMc9sdOAse2+eOvPsc//ie/RtDyMU0jXR/ea+CX7PpLpRIvvvg1bly/ruC83pCZmRnuv/+BtGwGjXqjoRiImoam62rdHYOVJMqO23EcJicn2d3dpdlspiX6aBgoUuegxEL7wMFDuI5LGHvsSSljD78iTz75FF/60hc4f/517rvvASYmJmJdf8URWFhYAODY8RPYtoPvezQaE6myDVKtJxNmY+Ip0Ot1kVLEJ8rIyGRzcwPPG2JZFg8/co5quch7jnR5z0c+yHNfucjFVy/xoz/yGK5rEfhKsCSKJOWqw5GjDSLLIjINpN9PA8wt5LAKNgR9ECIuqz3CKKC33aKzsUFr+Ra7q0v0269y7kd/iQPnnqC0VKLT2QIkQXyiKvKOGvDlykWcgquEOGRsp+aHsSNxHPRylDSE3OOzmAa/AKGeJykEhmnQ2WzSEUJVFo6NnbNxYz4AQOgHyFAoxqURJ4coQgwiNF2jUK9QnmqgGwZvfPJ3eeeVt6guLFCe8qnMR5Tn5jGs2P7MMti49g5BMODoe95HdXaSIG5JxXCAYSluQRa3IIVk0O7w6FMP8bGf+l7+8BNf4sDhOR79/id/8ou/+Uef8b3gX75blf3tbgEEcAR4Uq0DVf/0/GtvMlmvsrXT5Imz92VKfp1ur8//8Pf+CUJKfuz7P8Tf/Ms/x8d/6KPcd+IYlWKRrZ0mpqlK7c1mM/XwkxJcx+Ff/s7v87/8s9/EGqr7e/fgD9I1WK/XpdPZZWtziyDw8f0hjUaDhx56WCG0fJ9ICCYmJ1Xvn5S8jBtuBL6fVgaJr16xWCCKlHWWSgSjCkA50ATMzi/QaDTuYg8mj991XSYmJllaus3W1ha5XA7TVCu/xLrLtm3yhTxT09MYupHOJsZ8+qKQUrlEozHBYNDHMq10eJi0KUrZR60Uz549R31ihkPFLX7mZ8/SGuj8u3/9aR55YJETx+bxhj4zB2cgAp2Ak6dqbDdD7NoUuiYIhz26Wx22btzBbdSYPTFPuZRTyD5d0t7c5NKzr3PjpQvcfu1tbl+4xcrVbXS3QGWhRLFUolio4A06eN5Q9fTx85IvFSlN1rBzrqITRFE82Q/jE3/cYBU5brqqvq5AQcqJOTu0VT2/FMpYJIxCvN6AfrtLd2cXrzcATcOwFCNx0OkjhcRy7XF/gZinIaWgNLXA+q0ul569xNbtLbZurbFx7RatlU2iIFLbCsekuXIHu1jAzRfYvrVCfWGG4kQRy7bRDAs0GWtAwvqVm2gIrJzLmYeP8fpLl7j4yhXe+5H3YBnyfavX73xCStnabyC93+2JH/1vvqUJIKkifjZ5AJVigS+/+Dr1cpnbK2s88cgZCjk33ZVbpskPfs9T/PhHv5fTx4/hWCZhFGGaLq+9+Rb/4t/9HvefPMYXn3+R3/6jzzM3OYHjuDiORRBG/KP/5dfornewbevrDvw8b4iu65TLFfr9PkGggm84HFCrNXjkkbMYhpFKftfrqvRO+8i9GP9Mj62EMoYEgdL5r1Qq5PP5OOH008cEkvnFRaanpwn8e3u/i7gdaDQmWFq6zfb2NqZp0O/3UxquaZr0BwOaOzvsdnbp9/o4jpMmlCiKsGw7dS1u7jRTwdGEhry2tkK320XXdc6ePcfk9BxW0OLjH53i0EP38Vv/6tNokccHnjxDEIRYrs2xs2fQQ4+pmsf2TsSdpQ6N+SlMPSDyBnS2+nR22tQOTVAouVTKOTQiMATNlU1Wr98hGA7xBx6Wa3Pw0fs4+YFzaDp021u4OYdypcpw0CeI7cbKU3UK9Qp6krzCEBlXMGQ4F7EsSvociAwpi5RFKTPBL9JqQArVDqi2YKTvJ8KIYXdAZ6fNsDtIp/+7G028wZBcOa9Wl8lrqakkY+cdDp89TXm6Tn+nhT8YKjxCHMyG5VCbnmP6+FE0LcTMWfi9AMd1qc9X0XSJZqiEbdoWyxcus3NrlemjC+imieXYHD11gD/8nS8jI8njHzpX2Ly9cqDb7P4W3+BEcO8a8FuRALaBnweKEsi7LldvLLHd6hBGEVONGkcOzqU8Zk1Tu37Vfw9Via1pfO6ZZ/lHv/IblIoFWu1dFmdnuLWySm8wYLPZ5L4Tx/jUHzzNM198OZYHe/ee3/c9arUajz3+HmzHYXVlJVbYHVCr1Th37hymacU/41OMQTn7WW3JPRdVdtLe63Vptdqpc25jYhLDNInCkEKhwOKBAzGgKHrXBJD8PcVikUq1yu1bN+l2O+RyOTqdDq1WS9lxuS69Xo/lpSVM06JQKKRqxrdu3cTQDYaDQfz3+ikQSUmUr7G7u4umaTz44EPMzS8w7A95//GAP/eT7+e5r77NK8+d5yPfcz+uaxH6IdOHFpg+NIsTLnPlldusb4YYtkHk+5QqJsGwx+7WAM3VKdTzIDQajbxKAEQ0N5q0N3cxLIvqwjQLD52mvjirgi8uzfu9DjnXolx0CaKI0swkbqmgBE+iUBF8RmLAsQlJ0m/JVFsgW+4TzzqyQS5lxqE5kjHBSsRlvRglhUSBWAq83oBes0MURkqQZG2bznaLUqOCaZl7koAaxE4dXmDx4VPkKiVkpLYWds4lVy6iGxqmaVFs1BChj1vKIYWB4wpMAzQrj+k4DHa7vPbJL1Ks15g6sohmmkRhRH26gWUZ/NEnvsSJ+49z8Njc6VtvXX87DKM3vxGA0LcjAQyBczEyENsy6Q6GXLh8nUI+z2Aw4PGHzhCJcVhwGIb83ue/zGS9xt/95X/Jv/3UZ7l2e5kf+tCT/B9+/GN84bkX2Gnv8tM//FHed+5xvviFZ/mn//Q3EGJkmvluPb+u65w8eYowinjllZdotZp4nkexWOLs2XM4jpu2CaZpUW80yF5lWavx1NI7YfqNldyKz9/pdNne3iQSgunpaWq1GtVaDcdxRuKgCbCIrNj1uHZKFEWUimUqlTJLS7fp9/sUCsX4b9Jid16HYrGk1oB1tTrc2tqi3W5hWXYMcVZchkSDYH19jVarlQb/4uIB+sOQ+fwuP/tT9+EbBT7xq5/hzIkZDh+cwg8iNF3n0P3HKLs7rN9qc+XlO0Shj1stM9jt4fd8JIJer49h63h9DekHTM3k0TQBMmR3N0DaOcpzU5Qm6+imiQhHtNlEmqvX65N3daoVFy2XVwPLKEzRpOlQMDEKiVk/Mg3WPUO/se/NuP3E7YAa9CWfl2OqyaNNjnp1RCQY9AcEnrqutu+ss3VnjamD8xjmeCVA3BJYjs3EwXlmTh1m4vACjcUZGocVFXhnaR3dMClU69hFG93WcGwTwy1j5ovYOZfrL15g6bVLTB9ZpH5wPtVLlJHg2KmDXHj1CpcvXOd9H3mccNA/t7W88auGaQy/3hbgiR8d3wJ8a9AEihyUCk2cOXqQoedRLRd55/YK3V4/1vAfbQE++8zXmG7U+ZVPfJKNnSb1aoUf+tD7+cWf+nEMXef7P/AEf+ev/1ccWjzI5z//Zf7hP/zXGeHMe/f8YaiMLnZ2tnnjjfN87rN/zNbmFr7vUyyWePTRx8jFpBo1FBNUqtURe3EPizFr3z32fo/uvB6zv27fusmNGzfS+4tiF+QkiSD39q17bpBKhp079xieN2RjYy32+TPTNWAul8MwDJo7O3S6XXq9Lt1uLz29RlsIUn4BwKlTp1lYWGTo+WjhgKceKTJ38hif/8PnMQk5fWIWzwsQkcDJOZQLAyJKeFEVw4TeZpNSucTc4QXKE1N0OyGaLmgtN2leX6KzuoUIYx5ANCTwAzTLRNMUczTpl1VfnoVPS8JcAalFGL01RORnxF0yiXjUfI88BLIn+9jQT53+Yk8FQNoCCGQkU0i3FDGNO0qSRJR6HiIl3tBj2B9gWCbrN+7wyme+orYUujZ2cciMNJtu6BQbVQzLYu3yTdYu3aC7tcPVr77Cy5/4Y5Zfu4GmCexqnWFX0NtqMWh1uHPxWno9JgIjMkaYGqbBz/7Sj3D75govP/82p588e6TcKP81TdcwTP1db3ehb7+FCWBITN1t1CrMTTXw/QDP87mxtIppmOngbG1rm9Zuh0fOnOT5197g0MI8p44c4n/6a39FXShRyOFFpbX2xS89wz/8h/8q9uUz3jX4Pc+LJcEkg4Faf6mBn4fr5nj00UfJ5/PplDwMQ4rFUmq9pcUnf3pB7NMGZI6fsYs3efU1TWN56RbNZjM185BCYMTDvCxDcL9b8vUgCJienuaBB5QAye5um0qlmsKSk8Tk+x63bt5gdXWV6ekZXDcXn/4CIWBjY52tra04+M9w6NBhpWnghxytD/jghx/kypVVLr52mXMPHkhXlFEYUa0a6HaRyKgrCq2m4XX6FPIuOrC7vc2wt8vqxdtsv7PEsN1h0Onh9TvAECmGyEgivCgFR2WDcbQulVRnJ3FKBXy7AjLAHtyJT3htdLpnzT9kdqhHZugn0+SSNSyR8bZADf6Sx0EcVDIdKoq0LRDpz8l4q5MIh2po2K7D1Zff5JXPPqc0BcdzVWo+ItHYWdpk+cJ1Wssb9Jq79JptBp0evZ1drjzzCteevUR7fRN/2GN3fZP1KzfZuLE8ul9Ni+XAAtA0fM/n2P1H+J4feJwvfOpZAmlw6rH7/1roB4eHQx9vGNzz9u1KADdihmCMsrM5c/wQrd0OhVyeNy5dw4jXdZqmEQQhmztNPvmFL/PzP/4j/JWf/nH+2l/8aQr5HEEQYts2X3jueX7hv/2/8vf//q/E4h3vHvzD4RDDMDhy9CiLiwfQdS0l19i2zblzj1IoFMaC37RMiqXSOI1XCEVk3edkzm4Fssg/kbmgEpvwxNZbRJFSmpUSO1YAypaZeyHE2f8nLr4PPPAgg8GAW7duoutGajueYBYWFw9w330PpM6/Uqq9/cbGOhsb6wBMTU1x6NAhVWGEEVbU50NPTJGfnOaLn/4a0/Uc09MVAl8N25ARdr5CKHNIEcb0WfX33Xr7HdaWb+P5u3TWWnQ2dig0ilTnGlRmJtENIBogoyGu1afstol6rczfNkoCURgpVGGjjIwihATPmUaTPs7wRhpE48E/PvTbO/wbGwAyOt1HwR8nA5EkylESUN8TxfZu8WON1M+KSKSVhVvIYTk2L336GW69+Q5WbCuXvVA03WB39Q7Nd17B1ncoVnTq8w2mjh2ktjBDdW6CynSVjWu3uPPmLbAD7JKJ4doEMeoyWYlGUUTkBem1KPyQH/2ZDxPJiGe+8CqHHjpdrU3V/0c1c7h3C/DtSgAi2wZEYcgDJ4/QGwyolktcubGcItekEJSLRR48dZwPPn6Oj//QR2hUK9Rr1RQvYJo53rr4Dq89exERijT4s6q9WbujxEb7Pe99gpMnT6UKO8PhENu2eOyx91Ct1tLgT5B6pVI5DSYywZ/q8MX9p8gq/ewJUjVgE2lSsSyLk6dOjyUbJZyiSCq2badSYCPWoNi3CkjcgxYWFjl9+gw3b97gzTcvZExFfHZ3dxFCkM8r+bBk17+1tcn6+hqAmkdUa+n6Ukidk9Mhjz/1ABfO32D5xjL3n5pTWHoZl8Wahm5aRL6PkNGozAX8IMIpOUShQEqf+dNTHHjwCMff9winvvcJnKILUQ9dG2JbHaKwg2Y5aVmdJAEhBLppUpttxFr6iQiqztA5AKKHM7w6sgRLhn9yn6FfUglkh35J8MpR4IokYWcrgaT03zMfSGcBUiAi1dsnMxXDNCnVq/hDnxc++Xk18dfHXYaEEOSqVeoLFYqFDpNzNgcfPMKJpx7jvg8/wamnznLo7EkWH5xH1z2QOr1uh+rcZFrd6KbCHsgwHOPVhGFIfabOD/5n38NzT79Ka9fjzHsf/Fld1+6TQo6K0r23b1MCSFSC1IOLIg7MTVHMu1iWydrWDhtbTQxDR0iJ69h89Kn3sTg7zWDoEcZDskTE8+233+aFL7/OdKOWXnhJBkskrBNlH8WQ28VxXLa2tvjiF77A0tLtVN/v3LlHqdVrBKGfBray7LJwXTeVxxbx0E9m3u8V9Rg7ecQI5qtWbUpi7MiRo5RKJaIwZK9CaZK4lCnpKBHcm0QkUpLS3Nw8R44c5caN61y+fCld73nekK2tTTzPo1AoUiyW2NraYmVlOQ3+xPrLMAyEhLIj+N73zWGWK3z1Cy8xN1mkXHIJg2jsNAWI/BCEUnBWhBgDy7Xx+kOaG30EDlEUw3QBw7TQTB3kAN/3WG9W6ETzSN1Kgz9NApGgMlnFzufSgBzpK5gM3ZPoUQfHuwFSz1QA+wz90pnCnvZMZkv57IZAZIaAqlITMg725LWPRHryj1V8cYtUqBQp1cqsX7/J9Vdfj5F9MrvbxXRcykfeS/HoB8Cpp47Zuqn0KJM5xszJE0weOopp24SeT7FRRUZCJQApUmv5sevJ8/m+H3qCUjnPM59/hYXTx9yJuYm/rulgWAaGpd91+3YmgJeAm0mWLuTynDi8SHN3F9dxeOPKday4p5FSMvS81E48K9996dJ1/vbf+WVW1zZToE+CCnQch1KplHrYT05O0mg0MAyDO3eWefaZr7C0dDsdFj766GOxEGeYZvmkjXBzubRHTyb0UZbbHyeCNDGMndDRGO4/DEM0TefQ4SNUqlUl573PIJF4XaegusqiK0kEWevwLJU42/YsLh7g4MFDvPPOVd555530Iu71ety5s0wQKMTjnTvLSAmVSpV6vYGUkomJCaV4E0qO1D0eeeI0b75xk7WlNY4fniQIojQZqiQZ0dvtIiJ14WmGqozsUg63oNNvDShVyxw5+yBHn3gfpakZAs9n8+YtdrdaYGoEAXi+hoZIe+z0JI4idNOgFGP+RSQyhp0SEEgsBs59GKKJFdxCyFjBZ+/QT2Rag8zvEUnQR3LstJd7XuNRAlH+f9lZQFItyiQZpJRvde3WZxsYpsGNl1/C6/XiMjvz2guJiALMfInc9GEwC+ws3Wbj2nUGnQ5uqczhx99LfX4R4Qc0ZuYZ9ltMHp4nCkNMR3kWRL5/l9KxiASFSpGP/thTvPK1CzRbQ06cu++nLds8bNkmlm3ddft2JoBBUgUkw7MHThyh1xtQyud5++otolCwnw+zbVnkcgUuXLzM3/47v8z6+jb5fA5N0+/qXVLihWWRy+WoVKrkcnkmJiY4fPgIjqMgnI8++hgzM7OqJ96DlEvYgFKozBplL4ikIsic/kl7INPAFGMrQIDFxUUqlYq6MO4lhJK0A3v8Bg3DIJfL4ThOyhjM+MSlZWcyE5ibm+fmzRvcvHkTzxvGPP8OFy++wZtvXlRsvUKBiYkJbNtmYWGRYrFEJASW9HjPQ3Wcao3nv/w6s408pYJSCxbx6SaEIAxC9bmYV2/YagNRnq4ikUweOMgD3/MYU4dmcVwHTZN0tja48dLLrL1zG0yTMNQy85FMexOpFV+hWsLJ59LSfzRAS4Z+AonNwDqDKZrY4SpCGkrzTyZrOnWaKipwlEkM8TBXjGYESY8vEnYgGSRl8rjGqgWRDgmjBFCVzgIUKrFQKlCqVelsrbN58yq6ad1dasfwZcMyuPbSBb78v/0mN158mdbaCsgIXdMIfR8Rhjh5hQhsHJzAyecwbSPWN4gwLGvEfIzfh57PUx95lGKlwAtfeZ2FU0fzxUrpv/Q9nyiM7rp9q7kA+2kE/GIi6X3iyCJhFFIplbi+tEKn1yefi0vGVBI8x9LKCr/1yT/m6c98jWAYks+5+wJmsllf13UajQZDz2N6eppSqYymaVQqVSzbolqpMvS8u3rrMAzRdR3LNNNhInu5/fHOX+5R/InEuNBHGKPTZmbnKCfBv89j3o8CnfTwyfxCKRrbCi7d7aVU3uQKyu6l5+cXCcOQtbVVtrY2cd1c6uID6uQ/fPgIxWKRQqGgSn8h8EM4VPY4+54HuXZ1lTs3V3jioTkltqEBkY7QgSjAzuU49OBptHCICALsvHIwdl2b8sQMx8+dwev16DdbdLa32VleZfvWGmvXN7GiGiff0yCKNEQk0Q2BFLEBCCAQaGiUGpX0JNv7fIl06CcQmsXAPEEueIdI6vhaA2QUozhDli5fo9fc5ejDp1Pwz3hrIEYJRcb4Dk2jtblDFIaUG9V0NqBAQvEgMBIpYCi9FqLR50W82SjUqkTtFlvX3mTm5P2MnHH2zASikMX7T3LpS69w/aUbdDZ7NA7s0jgwR2miQa5SwSlq5MsTROKGsorL5xBhhKZJ3GJB0dPjazMxhi1WS3zwo4/x2d99hqc+8iiH7jv2c6996cX/t4a2xdfBBunf4gTwJZR7EEJEVMslDi3M0BsM8IKQly9ewrbzGLqObVm4rsM//7e/zY//1b/Bb/3aHyJ9maL83u0WBAGO41CuVgmDEMuy8bxh6se3sLCI47q4rpvaZSVe7EGgEHpqheaPnexpcGcHcXvmAbF9c+zkG1CfmKBWq42pB+0X/PIeSSGKIrrdTqwGJDAMM2YCFuJqIYh/Z4ZbEAXMz88zMTFJGIZ0u500+Ofm5njPe97L3Nw8lUolHXJKCUQ+jxzPUZmd4cVnL1AtGJQLTnx6Kh+7A0cmqE5UmJifojrdwM5ZtJdXWbpwGadkkqtUmDt6kEG7xe7GOlu3Vli+cI3VS3dorbQUwGV6CqRqAaJQncTZ51EKgWGb5Er5FI9/t8JS5mMZIbDo6Yex5RaGaKLpNs31LV78o6e58PSLzJ84RK6UHw0S0+FeBuknRihATYPW1g4Xn3uVtRtLcfDHA0I56vsjGaXJQMSkLiHjm1Cnqmm7mE6O3uYSXrelBFIld03dRCgoTlT54C/+OMVGnZ3lHdaurHD7/DW2btxhd3OL7vY2SMnk4YPkannuvHWNjavXcBxD8UIkdLd22F26mhYDkRfwPR99HKnB+Zcvc/D+YzO5Yv4/iyKhaNGZ27e7ArgTi4R8vwQs0+DM8UN8/plXOX3kEP/uU1/CsSwePHWUZrvLs69e4Fc/8Rncnk61UleWw1+H1NDv97hz5w4HDx6KST59hFDMsInJKTRdx/c8dMPAjcEyvW6XwaCv+PiuQ7lcjjX4RCrsmWTULPZ/vHWIMsEfEoY+hWKRiYnJNPjZhzyUVQveT0YsKUETl17XdXHdHPl8Hsu26HS69LrduLoY3xIoCbByzBWQTExMMjc3nw4Wx0QcJdTtIWcfPcXGVpd3Lt/ivsWyOtE0pdTrODoLB0sMwzq6q9Z/3gA62z1uvfo2tYNVRGSA8Ohu92mt7tBa3cEfBCAkdsGlvriAVS5BFBIEhpqeG6BrEqGDHj92t6DITgodKcc88LJIv2S6L0WExKbHAUrmOiuru7zwRy/T2WnywFOPcuC+4+zcWY95HNydBOLTOkpmA7HXhK7rdHZamLZNsVrKDP3kqOePodSjmYLIbBFiqrSdIxy26G3cwilPqLnJPqIioRfQWJzlQ//lx3np332G7uYOwTCgtdZEYBAFkSL/2HlK0zXuvHWVaHiY6twCUgi8vkdvo4lrNYm8HoZbIooi6tN1Hnvyfl569g0ef/J+Fo4u/OfXLr7zz01TF99OOvC9QEHfn7QB9x8/zO9/7lnyroOQ8P/85V+nWiriBSEIwYSex3d01PJH+zoAGcW4k1Jp29m2rfj2kaBar1PM5/GGw1Rsw83llClmpYKbyxGFYVpuh2FIlFH7uZest4hf5HEjUTVknJqaHtMMTIZQY4ngHrLho23CCNoaBIqroOttCoU8xVKZWq1GznXpdHYZxpqEtm1jWUqXIJ/Pp/eXlPpqB62PibJGoeDYAY0DJxb5wpfexgg9amUl3a7rCv3WODBFrpBDDF0CPyIKQqJhm2GvS6FRYmd5h8lj1ViJW0eEo52zVSpQalTQnRzeIIIIgiBZwekIBDo6UidGGboKIRkHUCrYkWyVx5h94zOBVr/OcOtFTN3jwKljnHzswRGzLyb2jA39MtN+mSH/SCnx+kNypZLiUyTDYpFpAaTq/9NNQDooVFRkGQ8NNc1GRoJhczW9BkbxP54EAs+nNj/FB37xJ7j0pRfZvHZbORMPA4QnkUIj8gd0t3ax8g5RFDBsbVCcKNJcWkP6EVg64bCL4ZZT6Pr7P/woz3z+VW7dWOPAfceeuHnpxmNo2gvvxhH4diSAzwJ/W4mERMzNTDI9UePTX3keifK72+31QUiM3SFh34uhHu8e/CJSdErLslNYbLIKq9brVOMePPn+fr+v4L+lktog2DbEzjsyFvZIyu79evZk0jte9o9chKempsdEQ7Kl/t6VobzH/avfPZpoJ69TEPhsbfVptdrU6jVKxRK2PZFuHcaAHbF68giSrI9XIGobhYXPw/c1CHWHt16/wlTNTV2X1SmmMXVgFsPJE3VCOptNyhNF8hUTw9GpzJQxXQPD1Bh2fLrtDsOhRygEWt7FsW0000CEIYGvI0WSAOJ9PyoJaFKBekzHSkk8pKs7xh2XknyaofAausbtW1tcemGd+x6ZorxwGs0w09eeDCArBf+M4f0TfIeq/Aa9IcP+kJnD84hIMOwNRgIjUgV4klxEFI2GgvGAVsb4AKSBppsE3S1lchqrC91LXiz0Aux8jgd/+INs31yhdWcDwzRxSnlCX2DmNCYPzeD1uxiOQb5iMmy1aK9tUaqX0XRLyaQlg2U/5Nipgxw4OserX3uTn/iZD+mVRvXjra3WC4Zp3NOsV/82JIA3gSvJi5hzHE4dPUCtXKJSLCKExNB1ZKuH3+mPUTnfLQE0Jic5eOgQlUqF+fmFlAFXqVTT4M8KXSYCHq1mc8xgM7nZtk2hUMCyrLQXzw73kt5buQIH6f+DwMeyLErl8ljykGMw4btpxHclBXn357P3p2THBiwvL7GxsZ5WGcoRV8c0TbVCtO1UQupe84ZQwFTB5/QDB7h1c4PtjR0mawWlhxgpMJNpm0wePAC6YjKGXsD20gr+IFR+fDkLdINcsYQUgluXr7O5sUU/DBCGEYNrEqkxQRRB4DOGtksCMhHlHO21ZWbol9nfZ2C/Iln3aRrd7SY7mz26wzKl3ABN+qPhX1oJkGH7iQxiM0H5xevOIEQzdaqTDcr1KrbrIGLL9GTTk/T+MrsFiGR8+seHRKQjNYOw30TGwqQjujL3mAmoamLyyALHnzrL4fc8yMTRBYadHrpmUqhWcQsOxUYZfxixs7yK3x8iwhDNtNFMZyxhmq7NEx98mCtv3WAwjJg7uvDDuoaj6zqGoadr9W93AvCzoCAhIk4fPUg+7/Leh++nWMwTtHvIvq981L5O8HueRyQi8rlcbJjRTQeBuVyearU6Zr6xF0jT6ezS6XSU0MQeCW/DMGINvlKqAJx4Bvh+kOoEjFCHyjm4UCxhWdZdzkDZ3zvW599jBpAFoyTil1nBisQTcWNjg16vl57wruMo8YjMzGL/JJPMMiKOzpk05qd58/w1XF2Qs410sCjCkFypSLHRQDPdWFlXsru+jT8MAEGv1afV6rN6Y4Wly9cQMfBHuerEt7gvjiKJ58m0ApDpgG309yYTbCnGJDwzSj6MuSKnLYAQSlEJSShsesMchmiDDDJDWznuqpy0cik5SBCJ0WGhaTrFWol8uRhvBGSGfBOlOIBsAlFV6Ug6TEQCIXWEPyAKBmiJ69DXSQII1RL4gyGhp2TnTNdB003m7zsF2IS+R+iF7G421WMPQ0zHUf6N2bvyA84+cR9RFHHtyhJzxw6d0E3jfam2xXeoAki0AknXgYcXMWLN//c+eD9GKL7umi/5+vr6Gqt3Vrh58yZ3lpcJY+FHIURK4tkb/NlT3PcDBQ1G3vU7kw2AWke6lCuVeDswzAS+n9KMg0CdDKVicXzIJ8T+972frsBY8It9VYX3S4TJAW/ZturvpdxXs0DuTTgCLALOnKjhRTrXLt+iXrLjPl4FYBiElOoVLMdFt3OAwugPOgMGvQGtjW3WbuzQmJ5m9uAMhYrD9HSeciFE9jbZXVqOBTkTpJzA9yAIxPjpL0cIO5Eh2ai9PhmW5B5Bz4zIJ1KSLykH5dbGBkFoEkQumvDiSfeI438vWG86FxCjDYFuGjgFh/JkTRmKRJmh39ihkkGBCjFqFyKJkLpaWwaDcdboPZJAksBf/b3P8sy/+jVe/f3f49KXnmbY2WHY7aKjM3ngKL1WD9/36G534oQbots5dNO+a6M0OVPn6MlFLrx6mWKjRqla/uEwiMYOpu9EAng+FgohkpJyMc/hxVlurawyPVGnnM+Pce33SwLJqW4YJs1WkytXLu95AaLY7ELcpcy7Vxkowd1nIcXJpjbK/C7TNKnGKz1l2eXfdV+GoeO47ojae48KRmQb8D3fcy9GYBYwkwROFIXUanVyufzYRbPfdkHu9zwKSdX1OHpynjtLW7S3m9RLLmGCeItPLzOGphpOLqafqoDeWdvEG0Y8+L3v4fhDxylXC5i6IBj2aa3ssPnONlGgxU5MUQyQEXiexPflWABlT2aRadfG6b3j77PYDISiFE8uzlKolFi+cpPlq9fQzDxCy8UkKLmn5Bcj6q/YsyIUcXURB6dumYqYVCvH/gqj+UMKF45EDKoJR++DkDAIiCKJhkD4g7F+/15JQEolMebkS9x85Sab11bptbYZNLfpbKyzs7REqV5i4cwJOq023iBQYB4NDLegSFIx3Tz5Pbpl8fB7znDr2gqeL5g6MPsRISIrhUF/hxLANvCV5FHpus79Jw7T3O2ArlGrV9UEdU/wJwEexb1VEIRpzz85NYXrunHQR6l9dpbgszf41W48dt9NSuqkbM7wI5JBX4IvqNcbBL4iGSWBHwQ+vjfEdhw1w/g6wZxWB+zvGZgIhorMxZodCCbBX63WmJmZGcMk7BccWWvy8UGmZKGhMzk3wdXLt7FkiGPpCl2YCQTlPR9i2DkM2yIKQ4KhTzj0OXb2AaqNCv1Oh2GvQ3tjm1vnb7N2dRuJTWG6MVqPxX1zt5f4I4gxlKXasYd4A6X0LLMw3gTVK+QYzTcZ7EkhicKIXKnAiUcfIAwjzn/5ZVZvLClhjmg09d9L9km5/all26gdUTRhBSxC11i6epvbV2+p91dusXJtiZVry6xeX2bj9h02b99h584qzfU1djc26De3GO42WVka0G4Fygn5LkzIPklASkQYcup7HqWxsMDGtRZLF1ZobewQ+gOC4ZBBZ5d8uUwYxurBQYBpW5hOPl5HhmNYHxkE3P/IcYIwYOnWBtOH5s9omnYfyH1Vw75dCWCMHBQEIQ+cOILv+wyHHkdOH8FyrLu8A0eT9yhV0lWCmDaLC4ucOHkyVdhRq8Be6qN398mvbLPy+Tyu6ygSRrLzv0fZHPrqZ0rlMo2pydh6a5gyDoMwpBwjDvcb4I0N/vbxCpR3sQn3vh9XGqpUaszOzo1+X0wR3hv8Yp9hYzpHkAFHDxTAdrlxdZmiY47p40UxVl5EIsabG1h5NyUzzR47hptz6O/uEgwHrFy6zdXnr9LZ6GLaJoXZOpplxq/bKNj6PVUWJ+i7NFEJSRSK2D03JoFlh35Zeq/Yw/pLqpog4NCZozz0gceQUvLCH36FbrONpmspEEhkkJsKvCPH24Eo04LE0uNIyTO/+wVe/Myz3HrrOktXbrJ1Z53t1Q06zRZev4fX7yuNfw0syyBXcHEKDrWpGqWpWbb7M4RaHk2Kr58EYnCQWyrwyJ//EHbeobcz4NpL11m6cBO/38MfDAj6Q6YPHkqZtk4hh2E5REF4F7gnDCOm5xrMzE9y9a2bVKYaRqla/IBhGuO6Bd/GNWDy9oV4IGhHQjBRqzA72WBtc5tGrUJ5YZLu0hZhFKarq+yAZW8rsLW9TaVapVgs0Ww20TQdISK2t7cQQlCpVMaMPxXhJqRcLqfQzCzMV2QDODmpgSgm6jTqdRCS5eUlNYiMQhqNCRqNxl3BfJdy0D0qgsQtaHT6iMzfO/IVjCJBva4IT3u3AwmUOaFWiz1YgzEcg5Q4esDRIzO02gO21reYy5tEkQBdU9lfVzDg5sY2Xn+AlXPJV8uAiDXtXfx+n2A44NqLb7Py1m10y8R2bexqETNG32m6hkBgCJ0I6HUjRKQhIxCahq4Ldd7Ez9mgOyDwAqUTkch9yxFlN3XxGQv+0QxBk5IjD5yk3Kjy5vPneeVzz3Pq0QdG95NCgaPx/j1KVqkgolgsFNB0jdbGDl5vwPt/9EOYtollmbjFPBoSN+8qRWBNI1cqKkanY2Hnc4BEN81YKFQxJqPkZNa0dOiZvETKxTheEcZ4/gMPn+SBH3iS83/4FZCwdOE2Xi/g1AcewLItTDePlXMRYYhdzCOlRhj6WLEobPYQtfMuJ+8/zBsvXUa3nqBUq7y/2175x9/pBHAVOA88lngGnD52kBfOX8IyTdq+j17JwXaHKIrGpKuzJX2CtPKGAy5fukSv12FnZwfDMCnGw7jNzQ2CwCefL6Snv+8HgEY+Xxgn9exB+Y1x/zPc/cD3KVcqnC6X6fV6GLqW8g2yCYpsvy/ffaU57iB0Lz/BiImJBrVa/a6fTfTf07nGPkCjbLIJIsl0LmD2wBTLS5v4vQFuJU8YCXSp/LV0qay1tle32Ly9SqlexykWQNMoNUoYpvraW198lZ3lLSzXwrRMzKKLWS2lSUhHB10nQqBrOrvtSF3gmq5KVfRREgD6u10Cz0NEpnIJzgQuYtzFR0YyHeIqCa/RHKg22eCJH/wg26ub9Fq7imKbAfOM2gAxukUSNJHZvChwku06vP9jH8K0LaXBl+D544GRpjOyhCf+upZB/mt6qhAshQRdQ8vIzN0zCUiIgpD7v/9J+q0OV597Hdu12bixxrA34Mz3nmXq0ALFRpndtW3sQoEoDJGR8ivIsk1jEAQn7jvEM597md3dAY35qcfWbq/mdF0bfCcTgIjbgMeS0uWRM8f53c9+haHn8QNPPcpjD52hubbNP/0nv87QCzLMsVHwJ6sXUCvB4dDDsmxqtSqmabOzsx0ngU3KZQ/XddN+Ppdz0wHgWN+/Tx8tMiCe5NTtxWjDRr0+FojJMC75I0cwtrtlxEYlfpRR6JWpY1AUybGyv9FoUK3W9t2ShGGIYZgpCm0vinF8LqHMMqdqBpV6hedfuYVFhBmr+qDpyk4rEhiGTuiHvPrFF6jPTTFxYI58LQ+aYPXKNS5+7lX6zS5O3sGwTcxCDr2cRyIQEWiGBiIObV1HIgh99XndACE0UiygrkA4/mDIoNPHybnYrj0SvkiktLL9OaPynbQKkEgEQajaitpUXWkOpjTeGMOflP6JCnDc7uj6qB1MBpJo6jlLDiRFvx3NjrQYYUnyObJfV4W90q/Q0g0MOt9QEiAS6IbBYz/5/Zi2xZVnXsUwNdrrTV75vWd44CNnyVXy+AMXO58jGHpqFRhzPRK3ZBGpucKRE4sIJGt3tpianZoXkTgWyvDCdzIBJG3A/0nJUIUszk7x3/+Vn+XQwgwT1XL8ZCqU0j/4B7+SUmv3Bn92OOi6OUzDoNGY4MjRY7z04gtsb2+jaZKdnW2KxRKmacYVQS49sdNSeZ+hncgkhL1DtMRAI5H4Ghug6HoMBhFjg8xxOLGM1XmTuca4mlBWt79er1Otxrp/MZR3L2dASnX6y9jNZj/4cjL8QwTMTefQLJuVpXVcSxudrLrO4qFJtjdadDselmWycWuNz/2bT3Li0fvBVBf0nbfuYLsu+qSpcBuOibAthJDoRBg6oKnwToZKQtfRNIEuVDLAACGMNAlomjoQus1ddE1n2BuQrxTv7eITtwVpQhAZCe+4eovESJpNZFV/x9Z1o+sJSQzyCSlWihiWSTjw0KQYBbkeB2fyr6ZB8rzHQzUpU7wfaGpdObISkzEL8t5JgFhrkfg6NUyTx37y+5k+doDrL12gubKFDCMGnZCpEzX0nIOmGaCF2DFr1rBMOlvbLL3+Jsfe9ziGYVOuFpmcrrN0c5UjH3rAKtVKZ6NIfMcTwIvAMrAgJViWybn7TxBGEV4QpGCXH/7hD9Jsttf+2T/7jZmE2LNf8KcTcylZX1/n2PETHDx0iK2tTXXiRYJWq5Uq/RiGEYtgjEwj320lt1fye2TrHaWIwSToRHxKGMbImmxfIM5Y8Gftwke/Q0pBrVZLRT91Xb9rn58g5oJAzQD2Qn7vGjDKCF2GHJirM/AEO5tNSo6RJkPbcbAiwdGTU9y63mR7o01jpsG573sfr33ha+QqGlJoPPZD38v6zWVeefoFHNfEMA10LS6AZVxBAMjxJIAe/18oEHA2CWCov6/b2sXNK46GnXdTxae7XHyEVGymWK5M7NFvEPEJn93PywwLMHnthRgp/EhNvTbv+cGn+J6f+UFM28bvD/GHQwLPj4FAEl3XkHE78O1IAomVeSQUYKu33UfTNWZPH2H2zFEA2nc2sB0HLxpQdhwGuz1kFGHaFpbr0tvZ4fLTT7N2eYXFBx/Adh2cvMvikVmWbq5hOo9hOfZ9u6s7fKcTQCeuAv5icpF6/pgy6aqU8o+l5I8eeODE52zb+i86neH/nPjYZYNfBdiod+52u7zwteep15We3GAwVJJjMfpvXDkl2ndFlnx+v4ogysiUGWNlFmOWXkkSyHoVjPf2+wW/GMMyVCrVUfAnj/seQKIEo5AOAveZNSR05YIRMD1Xpdns0O/0mKmY6fDLcW28nk9ttsSZRw5x8ZUbIMF1LIqVAkLrY9gWl158AxGGzMxNUalVKVRKuIU8dj6HYUB7a5XLF26iy7srgXdLApqu4/UHdNsdLMfG2mlTblRT8s9dLj5x/y+kGNsOjKH+MlLeMlMRyGgE6U23H4Ey2Zg5eoCdlQ3KEzVy5SK5SpHQ8wk8D3/gEQyV+anUNfSkKvgWJgFFQgrYXbqJiCyiUMMfDPCHQ9A0io0qpmXFGA2NYXeILnt4uz3cUoFg2OLmy6/g9foEvqTf7lCaqoGmM7c4xVuvvkMQScr16snd7fZ3PAEkGgF/MfP/m8BngE8BXwV2lcJtgJTyHwgR1TRN+z/fK/izgbm9vcX6+hr9fh9N0zFNIw5A8LxhWqbvLdHvhdPfi0MIwwjLMtNTPivHnfXlS17IhIabKP3cjR67O/hzuTyVSlUN+TIJRu4VyBAjBZwEr6DrWjo8E2Nw24ggFBSLktpEldvrLYgCXCeX9q1mzFn3+iGlqRyPvP8h8vUpXv3Si/jhgHI9z623VqhPeiwcmGNisoHlOliuS67okitq5AoRO+tDwkBgWhqKePqNJQG1StPotXfJl0vsrGzh5FxM2x5fHb6Li4+I5JjA6JjhRzQa+qVfjzIYABkBGrfffIdbF65guRbVqQkmFmeZOqCARrlSEX/oqcpgoJiYmogrtG9REpCAYdkUakWk1yYSOYZmDk3XGHYHbF1fRiKZPXWE+vwc2ysrlBoTWE6NQbvL7voaIghAGISDAL/XVy1MFLF4SBnt9roDyo3KaXvZdpRhw3c2AXwFeD1+/wexXkB3P5WceLDyfxFC5KNI/I3kJN4b/IntdsoQMwxqtTonTp7kjTfeoLmzDWgMh8NUIVhkXXn2wngzAZasIhMZrnw+p3gE+mjgpnG3m09C1EmSQL/f36edGK8CEvWe7N+/H64/uyZM1qVK99AYrcb2ICLDSFAtaBTKBTYvrmBpAtPQiIfT2LaJrusMOh6aZpAr5HAsA9MxabeGlGs58sUCR04exjBNTMfGKeQo1vPkSwa2q7OxtMXl83fGWpDYB/frJgElEqBw8MNuH03TWLtxh/kTh9Kg3tfFZw+MOvs3J+1hduKfDP2IdQEiEcWfi8E/OoQSht0BS9vXuPXmVeycy8TCNIunjjJzZJHSRI3A8/B6A4b9gdIz/BYlgUT1yKrMEHZ1LALsgoHTs+hYBoZp4PUGrLz5DmbOpVCtoBsGYRDRWtpARAFWLsewu55KuSVV7/TcJFEU0W52KVTLs73dfg1Y+04ngBvAe2JMwD3fgiDMUmv/phBCi6LorycZe2/wZ+W4dV2n0+kwMzNLuVzhDz75e4DEsmz6fWWgeS8hjrvFPsNYdUfNDWzbQcZaBdk9PxnhJ+LyX0o5plfYjYU8Ruiz7FwjwradlFRkGMa4YxB7T/a9Q76spn2iTDz6mghDaiUT23HY2Wxjm2CaGkEoMQyUaaVlMmj3EZGG6VgI4WEYkC/bDPtD5g8tYDk2hm1Rma5TmalhOSaGadJrd3jl6Yv4Q2X4KYRMFPa+qSQgJHixp8P2ygamZTJ1cC6mAu9x8RHfgIvPnn3/2MkfjRiJURRBFrwE6KaODGDQ7XP99Utcf/0S1akGhx86xZGHTlGsV7BzLsNuD68/VMg6/VtTCaBpGPkGhB62ZWAXIVct095osbupFJFbS+uU5kq0NzdxnAKmrSOEQRhodHd6GKapVpGo565SLZIvuOxstTlxpJGzHevQ3gSg8515e9fg13WHV199g5s3b8auux5CRH9LiOjXkxLbsixs24n7fJmi/pJA7fV6vPTi1ygVi6kMdqlUzmwVxF198l7YrbrPKBbgDGP5bit9ge5aue2zpgvi4abtOOQLhVhLfoRsTIJWSZMbqfDpXi2BcU7A3vdqaamEQ0cSZSIrWhqFVCoOUjdotzrkLEMFvk48rwjRdJ3QC+k1exi2Tb5SojEzTeQHzB89xOTsFFbeYer4AlMnDpGr1rEKFcLI4Lk/eIatlS21htqzcx8vx8WY2nCqvReNqjgRqZMr8H1uX7rGxu0VDF1PNwJZrb59XXyyBJ9o5D2QGHpEcsT+S6i9pO3eSOxTRqONkGGaoMHm0irP/vvP8sl/8mu8+cyr6IZOqV4lXymmYKzRapkMhXmkaZDqHmRUj6UYP0yIZ0xStxHYmLkyTqXG1PFDzJ0+SL5RJvR8+jttxdsQOk4hj+XYDHcHeL2BAi+5dgqdtmyTSq3E9lYLO5cznJxz4D/EDOAbehtVAOnpF2ma9gtTU1PYtvOzo/iTeJ5y5d3dbdPpdFI3nEuXLtHt9mLVHIfBoE+xGAdhvBXYu64bry6ijN5fSKGgbKDvovJmcQV7dACCICCMQhxHaRLKSoWdnZ1UjThB+yWqRQmAQ+5DHMoyB6NIZtCCWbGS6O6ho1A03VrFIRSSfrdPwVYthqGD0OPZha5IPO3VbebPHAE0Zo4ucufmTaqTdQzDprYwTaHWUDTcMCLyA57+zU+xdPk6dt6Nh3Lxqa5GpvFH33w7oGka3mDI1VffJPQCpg7OIaKMvJfMSIuLEcFHwX9J5bvkXqhvcvKL2KA1S+mNCUKp2GdSRUQqoWmGjmGa7Kxu8flf/SRXX3mL9//ER5hYnMYwDPq7XaVroOvfkkpANw2CoU/gBTiFHJquk6vVmD5l0ry9zKDTQzc9CpVSfJ8mzZVtpJAYjo1bzKc4F9syqdRLtHZ20S2LfDE//V2bAPazLtI0zc/l8r+gaVhCiI8nQ718XmnmNRoT+L5Pv9+j1+sxGPQJwzCVCx8M+qn+WyLFbdt2OhjM9uXjevzqZHUctZral9ab3OI+MJsk/IGHN/TI5dTjBNja2mI4HMRALdXquG4u3gPrYxj4u7cI0UjRVmR74Oz6cXQqhlEEMqBScfCDCG8wpOHoan2ngWFoeMMBRqOOaZm0N5r02z3ylTKOpXPw1AECf0h5ZgJdt/C6XaIgxHJsli/d5Nbb1xV6TyjrLfUE/SmTgDQwTNWy7O40efP5V9ndaXHg1FEM3SCIgnd18ZF7iFUqoDOrwlTfL0qBQYoJKWPVnyhdJY79TAw00g0dXcLVV99i7eYdPvxzP8Kxs2dA1+m3dr9lSQDAcCz87oBhR1V6hm2haxq5UokgGDDs7lJqTKOh0Wu2ad7ZxLBMbNchXy2lKkuaaVGulli6tqpQpJo28V2bAO71JqX0gV8yDKNuGOaHs0QcAMuyyOdz1Gr1uILQ4vI4olqrUSwW2drcJIjJPJ43xHVzaRk8OpGjjA6/GqTkcrl0Oi+ypqHZ8n8/fz8g8H0GgwH5fI5yucLMzAzNZpNut0MYSmq1Oq7rxqIf+wV/1oBEpj1+FJfOIzELjTAMxoRFkpapWnIZDkMCP8At6WiaRNfBQENEIZFUUNJhb8DWzRWmjx1Ax6BSL2O6Zbobu4RBQOPAPJbrApJLL11QNG1pKkRdJrj/VEkgrqycQo6CEOysbHDpxTfYWFrj2EOnqU7WkbpAhtGI3LPXxScjzjGqnKI9BKBRoEdRdjYj0zlBggxUlWOk+AwxgtO0bVqbTf79P/p1/txf+jEe+MCjICW91q46ef+kSUBhgdB0BZQyXRsNTcGSNY3d9Q3aK9sUpxq0PA/D0NANm42bK/j9IXbepTRVx8q7MdVegq5TKObo9Qag1saTf+YSQBzsbcMwftKy7d8WUfThu3v3aGwHn6D2ojBkYmKCXqy4K0SE7yvhzUKhSII3GCUAkX5PsRjLhWV7//3agHsw8RIt/2azyXDoMTk5yURGQtwwzHvu8bOryP2DX61NXVcBVUzTSpOPrpsICU5MYPGGSsrMtXU0HXSpLsUwiugP+uRtNeRbvXKbo088SL5aQ9N13HyBQPdoHJhHNy0MQ+eVzz7LjTffwXaUkYi6qBIZzz99EhCxdF6hVMRYMFhfWmHpyi3Wbq6wcPwQB08fpVApoUuJEEEcqOxx8REjDb90U5D0+NGoOkg2SRnrr3RYO1YdxErC0Wh4qBkGURjw2V/5HWzH5OR7HyaKIgbtTsoB+KaTABoiiBBRgGEZWK6D3x/GbYFJeWYKv99H1wzyxRyW6+D1+6xeuoluKnzH5OEFhWgMQjW0loJKtYQIVQvp5N3Cn8kEEAdcSwr5k1KIzwopH9sv+JPBoJre2+zu7nLr1i18X9F6kwD3PE/RfkslQEsHiclASNmGF1U2ZlwUYmRHJUbcgrvcgjNcc6DdbmGaJrVaLYMe3KN8cxdvIBxzIBqVt4l9uD+2fkwAS6pl0SnZ4Lg2vudDXMUo80qZwlC77Tb5qWlM06S/2+Pma5d48KNPITUNw9Qwq3kM20ZEIW8/9zoXv/Qi1YJLNAjJFwRm2WS7CYapfesqAQOiENxCjsUTh3Hy66zdXOXNF85z8613WDxxgPmjB8hXymi6+uakv0+GeCOHI5kGfioIGsWw4SiKgzsR9xRjmn9RRuknfR3iKkKTEsexkFHIl3799ylP1Jg5fIAoCPB6AzRpIDW+qSSgGya7K5d47ZNfZjBwmTk+z+SRBaozExQn6hiWRXGywaC9SyTAtG1un3+bzmYLJ+dQqFWYOXk4FiiV6vcDhqErZqwQmJY1+Wc2AcR7t5aE/0wI8ckoCh/aG/wjYc8hdqwAvLO9w9bWBv1+lyiSTE5Oous63W6Xfr9PtVqNy+golf82TYtisZT2+HfDbO/W/N9r8S1SzXgZ6/53qFQqd6v47Nn3J4ktisaBUCNmZLZ0jVJykOM4I49BoeHqOqZl0g9CTF2qIFUEQLS4DQh8j3a7TcF2sWyT269dZvGBY2zcWSfwJJNz86xcusHm7TtsXVtiqlGmu9MhN+1y32MOr7/eUsxCfST7/a1MArqhM3d4nupkje07a3S2t1h6+202b96gPjNJY36WQq2GYdpIqSGj8N4uPpktwFirkM4JMgpJsQBoFJF+fxQbgiJluk0xLBOv2+W53/lDfvi//nlypSKBFyBiyvY3gxOQUuIUixw7A2+93OGd586zee029cUZFh48SXlqAsII3xsqn4tulyvPnle/y8xz6kPvoVCvEAVhLHisoNOlakFdE2GEZZvan+0EoN5uCyF+JAzDP4wi8cB48IcxoSiLt5YUCkV0XcNxXOr1Bv2+MuBotVqsr69Tq9XSdaFyF5qJPQfuVvwZ857fA/QZV/mJ7vqe/XwBxpNHlDnxZap+lNxftk1JkIq6bqTegqP7E+lEOfJifIIet6dSmXUilTpvZ7dF6OYxdZN8LsfVF84ziIYM2z2kr+H1BgS7fXJ5l9Zul+p0lYc/dAi/u8T2VgToREKqYf63OAnEJFDyxTyF08fwB/N0t7fotbbob68yaK3i5vPkq1Xy1QZuaWJcpDNG/CnviFj0JJ34Z16rTBWQlP6jk1+mc4HkeTR0LX4+Neycw/q161z88nOc+8EP4xZy9FqdWGTkG4cNQ4RZaFCZneW9HyvyxjMbdLY7+D2FBgx6Q9xiARydKApYv3YT3TA58OBJjj35CHP3HVUzGcNQegSh8sQ0TSOdcXyn9QC+nW9LQsifCMPw82EYHsgGfxaLn2wXarUatVoNw9A5cPAQ6+vrrK2uMT09Q6ezS7vdxnFswjCiVquloh8wEqQQe9iC48o+0R7Az/hwKSETJczEbAUw1qdm0Ih75xJRLN892laIFDilLM+CdM0ppMTUIgxdS6sQQ1eJoTI5EWPaDYZ9n93tFu3mDsVajY/+xT/PjTfewhVtrr56k1475NHve4LVKzfpN9sU6lUe/MjDFJ02F694DAYKAqzILPq3JwkIkIGawtuuy8SBg9Tn5vB6bfzODkG/STTYwWhMAdrYayCz5J+4hI/iViGpAqKsxHeYJIiMj0D8PEsh0w2KZqjgTysBw+LK8y9w5JEHKDXqDLt9oiBCN/gmkkCEZtjgTGFpQ87+8Hu48IWL+IOhwpXkXIqTNTRLY9DdJueUePI//xj5RgUn7xJ4HlEQMmi1aK+vM3H4MM7UxJiQStIq/plMAFkzjJgIc1UI8bEoCj8ZRdGBrHPP6I/WKBQKFIrFFJDjex71Wo1Ws0kQBJRK5dhc06dardJoNMZEP1Q/pd0z+EfuvaNbFvWXBGmxWBpjgWVpw4nS8X6wYVUFZHf/o2STXWf2ej01t4gZdRpR4n2R0YoQFOsVDp0+Ra+5w8TiQQIhufrKBUo1hS1vb25huAGD3pBDp4+imyYiirByDmc+/D5KNZPhyjbLt/qEQYRpaEhNB/3blwQQBhJBFFc3um6Qq06Rr04hwwG6oWM4JQJvOOrj7+HiI8PxRCsyVmBCCGQoRpLf0ej11VDBn1RSukH6sWGYDNotrr/yGo/8uY9gOTZR0M8E+TeWBJASszyPaF2nWC3xyJ//Xi5++hnF/HNt/N6A/GRZ0YENk0FzFzvnptqCva1t1q9dAySzp0+P2K/c0xfkO4YE/JOriuxx5M16AEgpzwshPhaG4Z0k+BN77SAI0DSVqUXm51ZW7nD79m12d9tsbo709nO5HK7rZsw0M0Ih+8p3JyViuAdDEO1RKQ5TPIBpmrium/brhqmIRoloSRgLc4Zhsr4KMy1BOMaJUOQjFW6apuH7fgo9liJiEGpEMXFF1zU0XUGBu9tb1OYXyVfqoGnMHDnE9/yFH+fE2Ydprm1w6/I1vN6QR773LPXZCXburIOUHHr0DNW5KYgiWs0BSzd30XRiI9URhz+6S5Qz47X3p0AMjrz64sANA6UZaeVAdwm84fjuP00EWbTf6FRPgjwMBFEo0gorra5SjYFoFPxGEvjZRKA+bzkWd95+k8FuB8t11HRfiAwiUH5dxKAUEWZhAqwSoe9RbNQ49r6H0U2T3fUdwiDA73dASGpzU4RBQG+7lRqtICVuocTc6QcwE98KCUr6RdtXZMb8bj7xATY3N+61GkzenxdC/FwYhr8TRWEtgfOGsQ9gFjufJIlut8v6+lraL4ehQ6FQZGdHVQWJKMfeYd/dqr5RuoXIBv/e/1cqVWzHwY7Xk0klYBoGelxdWJZFFIV4XniXLFqirhtFI5uyXC6HrusUCgV03aDdbuN5nkoKuqkcf0MR48M1DF1VTl63Q3tzE7dQI/TVJsFwHKKgiy7aVBpFipUitlug2GgwaPcoz9SpTDcQYYhh6rz9+h163SFOzknVjNUpz7enEtBELCdokMCydD0WDImDKtn9p2pAUTS2+0//nxiDhBG24TM55bG1Y9LpSkQ4EiQZDf3AULKFqgWIS39DV0lBj3lihqkzaO2wdfs2sydOYZgGYRCgS/lNiIooPUGcSZUUw4jSZI2po3N4HZ98uYzhmhiWRq/VRAY7yFBiWHOIKCLwQ3TdifUkZNoOarp6/aMw+t9HBZD0tZmM9qUoij4ehmEzDKNUxjvL0U9w+r7vo+s6ExOT1BsTnDp1JuPwK9jdbcf0YlU/i8yJPi4/Pgr+UVk+XqKHYYhlWZTLZcUS3CeBJTRiwzBwHCVhNrIo2wtRDmNZMCO1ELcshWxMRFB831fBL5RmvWWZhDFYz9A1RBiwefMGmqbTWt2ms7GDYZmUJ0scPFGjUhA0N5oMun11gZgS01FyXZZjc+f6Om++dA3d0McFOYT45iqBxGcvFHGyihBBRBiEhH5AFMt7SZRGoKoOAhBB3NePSEJRGBIGfjrcSyuF5DWJ5B4Xn5hMFgWU80NOHulxYNbHtUNVEWSGfro+Cno9CXhdyZ1petwGGKCbGlKENJdvo0RC9dSkNGt28vUrAYlVqhOERsohsFwLp6jOavXa6gStDcqlIdXZMqZj0dtps3VjVc17TCOWL9No73TigbBOOK7F8WcjAew3A4B93U4/H0Xip8IwHI5EQUktvsZtvnzFKRgO0TSNhx5+ROH2haDeaKhgSnTh9k1C4djJn7AHRx+P8ASFQhHXdccUfMZWgfHflqj85HI5bNuJ3YiC9H7U4w8xTZNGo0GhUBwDACXqRwkpyAvB93wc20IqoS50HUzLYPPmdfxhHxFJrr3wBls3lhXaUcLRkxUQIYE3IPSH9FqbyCjCybu0N7Z5+t99Ac9LkuvIC+AbSQJhEOINhgx7A7zBgND3U2suwzRwCjnKtSqVyTqlWkWxOTt97txcpn3nJrnwKqVcW4F/MvJqUaiShgjD1NVX9f1ytPNPPz9a9yElu7s67RaYmsf0REC5NMIQJEM/fc/QL+n/DU0p2qnZgIZl6XS21vCHg4yoqPzGk0B8rRmmiWYX8LoD9TwHId2dTSLh4/X7yChEdK7H14xBa2WDy19+GX8wRDcMzJydeg8kRrqGrhMG4db/XrYA94ILfS6Kwr8UBMGvRFFoJ/2fH5e6I6PPkdnH5SuXKJXKCvYrBcVCKYWaEtN0NV1PE0iUos/u7vuzVUCib1gul1VZt5fqmyUSZYw/o0gjn8+nWAXPG6YDw1KpSLFYSucVWcxBAgFOfv9QCIYDn6pjYZgGQSTJGxqGNBh0Wmwt3SJfbBAMPV7/1Jc49dR91GomhZJJrarRbra5deE1hq1d8pUGN9+4zEufe46NpVUcx8IPIkJfJSTLtePyXb9nOxCKkMpEjZkji+QKOXLFAk7Bjd/ncPM5NB38vke/1aW5vs3G0gqDbo/mxg52QyK0OoOwoeTO0IhlAwnDiMAPwFFVWwLYGQP3iBEuILlhSXQtwijMEIk8zes3kCLAsTQG0Z6hn0Z6+iczACNODApmr+jEw9Y2wWCIblhpEGqxUvC7aQxGYaj4BraNFALLcQh8H+ErVuqw02Xz9jVy9Un8zjold4Bh22zeXufyM5fQdI3K1ARW3lUJQKg2qrvbxzANDF3HH3j9/50nAAB+I54D/EvAUTp6QXxqjxx+VTJQJfr16+/QarXSqmBhYXF08kuJZZrojo2h6wyHQ3x/uEfiO4r781G5HgQBhUKBfKEwJvSRwvD2VAF7AUGOo7QCksGmpunxKaRahXF8QQxR1RhtDiLBbsfjkGthWhZ+qLj+QoDUNdZuvM3csYcxLYveTouXf+85Tr3vCLMzFo41pJKf4cJXX8GyXN558zat7RZhGGGYOrqhMzvVYO7QAtvbTW68dQPbsbKyoHclARGGVCbqnP2+91GerMeghCidxEdhiN8fEsW0YCkluWKe2cOLNOamyedNwgi8zlCV36ah1IbjCXgYBOimwbicnECG4+3HaPKvTmZNEzj5HNOHzpKrTHPxudfw/AGmpaNrUp32xijgjYw0mGaM5gOqHdARoYfX6+KWanEPruYgijp8jySAJPQ8Bq0uummSr5Wx8y6WYyNtC902WSwW0Uyd5sYGUXcds2Sxvtzjza9cUC3hZA3TsanMTcSXlqpwet0+uZyDpoM39Lb/Y0gAAL8hpcxrmvb/Iz4hssGvZgEeoFEsllOzjXy+gO97sdqOHpdQEV68x7dtO1X86XQ6Y6g8FfxhOqgTQlCtVrFjyu9dYKI4EYwDjbhLflzNB+y09Ul+f+KXkGXAKUfaWOsgFLR3h9i2Yol5/gBTV8xbKTUQId2dNUyzpCqcgcern7nAycenMd0iu+0OUsBDH/4gty9cot/eBWB6YZ7DZ07QmJtm/tRBvvbZZ7ny+hVM09wzxhtPAhLod3psXF+ms92Kd9cBYeAT+qH6OJlfRBFIQaFcJF8qxEM5QXN9i95uByfvoBs5ZZSpS8JAVQCmZcVKzfFzEI0kwkUG5CPjiT9IDENh5qMgYPLAHPfrOldeuQhIvG4nHvyNpv9pC5BJBtnv0Yjwex3sQiX14pN6xgx0nyQgIshXy9i2zc6tdTa3WriVIo2Ds/TbbfrNLvW5GTTHwtzZRtMNlm5J3n7hIhqSYr0SE4h0zFgPACAMfFrNDqVKUa0SLWPrP5YEAPAvgDLw94UI75oB+H5ArVbFim2t5ucX1GTdMKhUKrRbLYbDocrOYYTnDTEMtcYrl8v4vk+nszvmXpTcgiDAdR0qlepdvf8Yz3/PUDAr+xWGoeqNDQ0pE7y/ljIVx3kESQKIB6cxlHinOcAyNXK5HANvR51UEWiaxC3kmT5+mM76BvNnDrF5c43lt65z/gs3WTw7j1MxKFSKTMzN4Lc6BP1d8tUp5o4cwM655KslrFKBYXeosABIRMS9k4BUCaDf6zMcDHFiFSGZmR2k3HyxZ4gXShIPrGF/oPQeHFfh3bXRDCByIjBkqgswGvrJzDBQaQKkCUAnrZyCoU99usHCsQP0d7s0ZUA4HMRlvtJSNNLBn5ae/ElCUK2CIBgOlapwmujHZ1l7kwAaRIGgOFkjGAS017fobbYIBx7d1ibdjV3yxRK6a+EPuwjnCOc//TwiDJk/c5jZ4wcIgy66Jcf0K3wvYLfVZXpmChGG+ANv5T+mBADwP8cinX8/SQB+bPoJUCgUUmGOZGUIkM8ru6vBYBCz+lSJ3+93aLdbFAoFyuUK/X6fwaC/JwmoXf7ExEKKzZd7vQgY1ya8i04cB7VyADLSPn+vXkI2ASQGI8nmw48E280+BuokHWyK0cpKh8DrUZ2dRUcnVypy6NwDLD64xPnPf4Hlq9c4/NBxNENy5aVXOPHYOZxinnAYgqajmyaVuUlAxxsMFTpOS5SW908CGkp3r99RZitOzs4w4UYlq5DjEmAJjh8hsWwLfxjQ2x1QqBSVnJpQHgOBFyDy0UieLWUEZkv+KAb3yFQkRJ3aMh76SaTUqUzUsV0Xf9Bjd9hDN4y0BdDibUCSONIZgT5KDlII5duXtnr62Lj9riSQKlTrVOYm8bp9ojCi3+qgaw7Hn3yUMPBxDY1wOOTgI4/gFupEns/C/Ufp7+zQWltn5sSpFMRmGAb+0Ke1vcvp+48RDD0x6A3v/MeWAAD+5yAIGkHg/w9Z09BKpYppWrHwZz5F1YVBwMrKHQI/oNfrKiqtm0uRd0Hgs7GxjuO4FIslBoM+ftyzJkzDel1ZeyXQy7tMPPezv84o4WZPRN9XGge6vhfKuZdBmKgGy1goRLK5MyAKAhoTFW4syXStBTqhN6SzsU5lcp5BrwWaxskPPMrEwQZf+fXfxuv71OcmsG2T3c0mURDvkw2d6twkdiGPNxjS2dnBtAw0Qxu5HO2TBHQ0Ak9Bj/PFAm6xgG7oGRcfEZuB3O3iQzzVN0xDgWLWtqm0KtSm6qrcD1QCiMIoNVRJGYHRSOEnZVXGCL9sBYBQXgECcPI57JzL7sYaHU2Ohn76+NDPyMwAdGM0F5CRWmfKKBqZtwjumQSIyTqRH2C5jkoCvQGRYxH6IZ3NJuiC/iDCzhcIBgMO3HeAXG2SfqtJ6IfkirXYXUkkxDn6vSG9Tp/6RIVBp+v1u4Pb37VrwOTizq77vtHb11sdAv9jGIZ/X9l9q0l+sVhI8dFJgCt7KA1v6LG5ucHy8hLLy8t0u910up6sXnZ3d9nYWKdYLCmAjTdMKcYLC4sjr4BEYWaPa7C4S0gkaxIq9lQEpK1FFvKcxSKMqg8RbwRgq+nT6wyYnKrR9xQyMLlYQdBaXVIS6t2A0A8I/IDKzCSP/8ATaDJk9sgitlOgtbJB5PvolkmuWqIwUUWEEf32Lp2tbUxTT4ExWmyRJfasCCOhWqlOs02/02XY649mIELu4eOLMaOPKB5ySqDSqOA44HXUUFIiCUMRS8qJsSn/aPIfpeYhUSTGDGh1Q7VEQiYiowIn51KqloFopKO4Z+iX8gD00Uow+TgKQ0IvHHkEZgxO7moFM+5Gw16fKAjIVcsUGlX0WDOivb5Ne30HGYVoErT+GjoRURASBRG9ZhfkeCwYus7ayiaarlOrl+m1OutOztn4rqwApBQMBsO0lP6TgIXu2q/HfXTC9RdC/M0wDDTP8/664zjkcvmY91/CMIw90lqCUqmknF51LZYXGxCGXpw0FAOv3+8RBAFzc/MMBn10XR87+VPZ8URW7J6GoSPPwGwgSCkxTSs97Uccgv1NSBOYsGoFYKcT0NpuMzFdZRgZBJEgZxupYGRr5SZTB88QDANW375BcaKBJgXViTKLhyfotfvk3Alk1FOegJZJeboRX7iCjZvLhJ6Hbih/AiPmtu9XCeiaRhRCt9lRbruFPLlSQRFx9rr4iHEXn2QbI4KQUrXMxFQJGfYIB7tohZoSFPUC1Xebxh6Mf1IJkNqHRZGC/44qokQeXmKaBsVaGRGGDHZbmKYR4/1HQ0At/X988meTgaEjMQh8H9s20bKUX9i/Eoj9EKNAJWLD1ihN1ek324gowoxMJDpe18OKNrHri2i6gQTuXHyHQatLrlxCM430OjNNg5Xbm1iWRbFcYPPt9lXT1IffdQnANE02Nzf45V/+1zSb23S7u3/CJDIKCtd12d1tc+lSJ/Vfi4PkbwVBMD09PfOziYBGMgcYBf8IzJPP5xEiYmFhgSiKuHjxQlpGGoZKAp3OLs1mjqNHj+2RLpdEWdrvPnZk4/Zjd7sIqSGgkeoYKo3D/S3IspLjmqajSWgNJGt3djjx2ByWnaPd9SlOmfEWQicc9ti5c4NceZ6tW7e59PSLnHjyAQQwOWWztdNFyDqGZaLpBoV6GbuYIxx6oMOdS+8gogjDtmJsxLskAUMh0nudngoga4v6zGRGKXlPAowyir8Z6i6a2ncH7SUssQ1RnjAQ+H44wmFE45VElHAKMko/UazLaOggkoqFiMpkjcpUnVsX38LrdbFsczT0S3p9jVFS0EbgIJUYDIQ0CTwfyzJGtOB3SwJo6JqO1xtgmAY2EtMxccp5gqGHsCxVQQ5WqdSD1Lvw6jOvsHn1NpXpSUzXiYlbSuxVSsmd2+uUK0Vc26C12Xy73+nL78oWYG8F8M3evpEKIP56ZBjGLxSLxV8XQqQQ3dEqbRT8iiMQ4nk+q6ur2JmqIQk0w1Ce8BsbGyMiTmaKL/cYko4P+eQeq/ARgjCpghJ/gXw+j2WZOLEXvLr/iCzvIVk9KkCRhmmZRELn1u1tSgWbYrXCTjvENDMnlqnTvHOFYNjDtG3uXLzC+U9/FSk1DBPyzpBBbxfTsjFMHadSSCuknaVVli9dUT4BKddgNBjbrx2QUuAPffq7fTaX1xn2Bwr9luD1M9N7mdXmSyXBVZujOyXy5Qql3ICSuQbSHxGMMuu+ESBIxpP/KCP4kSD91BAw9ANKtTJTh+aQMuLma6+nX9djMRXdyKwA04FgpvzXJLqdIwhVGyAzRKmEY3CvdkAiMSybQbtD6CtYs1spgKFjWhbDwYB8zsMwBOgGbz/9KtdfOI9m6GimTr5eTitDDY1Bf8jSzTUWDk4jQ59hf/CWnXP5j3UGkH3zpZS/4Lru7+Ry+XsGf5ZzsLGxxttvvUWr1aLV2qHX66UBZ1kWQRDQ7XbG+P5RFClAS1K+7uNLkGX+ZYU+lFGISS6Xo1AopOeEaVpxS0CaoBIdBAUWUjiBXC6HY9tIw+b60i4GEVNzk2y3FbNNTyGsOuFwl9baVTRdx7Qtli9c5/znLyJwsLQOhq6C1Cq6WDmH0AsIhkPe/MrzyCjENGOtQY1vIAmoAB30BuxstNhe3VT2ZhkXn2Q6v3d/nxB6ZIzjD/QGhpNjorLLgZk+tq2DjOIyf3zol+UCJM+1pqlTXdNV5Vefn+TAfcdwCwXe/MozNFdXsRxTnfxJosjs+7O7fz3+mq5HaHaRwBfp2nLkVvT1kgC01zfw+h5+r0/o+TiFHPlqXq2BtSG23kaz81z46jWufu0tNfSTgvJMHbvgjg0AO50+G6vbLB6apd/uiGFv8Lqua392uQDf2opD+pZl/yXFH4j2Df4sEUcISavVYnt7K1bh0VP6LnHf6O8hWkgp8H0vrQTGyURhZmC3l+ZMijcoFotjzEggbQeSJJPVQEjMUGzbjr0STZbXBnTbXQ4enmGnqxGEQl24aRIwGLbv4HXXQTcwbYNb52/x2pdX8QKLMOjTbe6QrxVAwLDV4vznvsD27VWK+TyWpcVqtt9AEhCjjYWpBzRX7hAGUap3MCbeucfFJ9HpVydqSBAZbHcn8SOXY8cEszOxcm8oRrTgzNAvyigFJfReXQdEyMyJExw7dx9OIceFLz7NtZdewXbMlAGY9vxGFgcwOvlVOyAxDJ2IIn7MlUiGfF8vCWi6zqDd5o1Pf47Na0sMe0N625uEXki+WqDb2sEfthkODd54dofrL11H0zVEGFGebjBxeCFNnCCxTINb76wQBBFzi1M0N7bXNUO/aNrmf0oAmbc28JNCRJ/fL/izrL5kZVSpVCgWixw7dpxTp8/Eu3olQTYcqnWgHgeeOqmVP2ESoFki0ejkjzLa/6TMvmq1uq+Ci5L8ThCJpJwDx3GoVmux8pCOYehYps56O2J9aYMDh6bxIpvuIMSMTzWVBDQ0TeL1biOjFqCIOddevclbrwqKE9MMhk3aqxsgJf7222zfvIZjFtANKBTdEQjmG0gCyd9ZLluE/W0GnV21FY2x+/d08ZHjvH5EyNDTeedOne12gePHNSxLMQujSI5tA6LMe7LcfulTWTzJwrkPM+h0eO63f5+LX/yK2mpYWgr2GSn/kNEEGB/+6ZoEw8aLCkS+h2VbSBGlAf9uSQAJm9evIUKP269for2yQrhzAR1Bd2eXfq9JsVHjxlWXay8vIQIfkEwcmefgYw+kK8WEeKSbOlcv3aRYzFNrlNhZ2XhJCtlLkIn/seEA3u2tJYT8eBhGn5dSnh1BeqM90lujvj8IAtq7bZ588v1sbW5w9epVdF1jOPRotprMzMykENCkJUh4+qp3H6n7ZqsPtbpTbMBqtZqZ+Gf9DNWLrExIFXjJMFTCmZiYwrYtgsBPRUB0XWPX07hydZXvu+8YxWqN5fUtpuo2Mfs5PgK0+HTdxM5ZSAmHFw4ycXCB0DNwcibN5XUOPnQfumHiug0GuwFO0cYtKu8Bb+ijJSWmiPsM5D6DQQgCiW5o5N2A/s4KpZmjsZ2XGA3rYkGOEV9A7nHxiQCB72lculaiXLAwTfD8mPknSNePauqvgjEJZCkEVq5EYfE9XPjy81x+9nk6W5s4ORvT1FKY7951n6aPkoGeraR0gU+Jfl9SqiqTExHJtDpS5JxYIUGMPqcZGn6/z6C9Q6FeorcZsnZlidqjVdyizc7yBoYRIUKbo489Sm12Ac2A+vw0jcML6IaetjQaiqLsDXyuXV7iwJE5DE2yvbb1NW/oEwbRf6oA7j5RaQI/FkXR+XsHv0hbAoDl5SUuXrhApVKNg1PBbScaE2oKPMYSVANBRUsOMwakowFeQvbJ5/OpdPjdZqYqmJKthq6rU94wDCYmJikU8pm5B7EIiE4oTd66vI2jSxYOzrGyqSqG9PTSkj13PDw0PYpVjYd/8PuZOX6MoD9QyjlhiK5Bv28jQgNNA6eQpzxRpzpRBUQaHN9IJdDtSgp5A9NfJRr2lLJQOvTLinhkh3t7XHwiATICGbHdNOgPSIFEkRAjoU8xWo0mxB7b1mk2JZ/5V5/khX//SXrNHZycg2loKdgnhfpmev0x5F9KAhJoukFnWCbwhuSK+XGodubU31sJaGgM2m0syyRfLmFYBt2dLrstA03XCANPzTM8gWnqHH30fo6//yyTxw6oayK200t+n65r7Gw2Wbqxxokzh+k1W2LQ7X/FdmxMy/hPCeAeb7ellD8SRdGF/YM/HLcPCyPeeOM8ly69nZqZTk/PUMgXYkMWPR0sJsO6IAjGTEhVgghShGE+X9j35M9i/UfrTNKWwjTNeF0pxoiGad7QNS7f6tDabHLyzAE2WhqDYTR+mmmqhFV9vI4UIcsXX+X2+RfZuHEeTYdcPUdnZ5etlbbSMDB08uUibqFEbUZ5C6ST8q+TBAwDen1JfwilfB/Du42q9DMOPqmLz0iY891cfDQ5Lv4RpcIfo4m/aWophdcwNULfw+/3cFwHyzbjx62N/Q1Gdt2nZcr/sd5f0g8KtHc1DEMjVyoQhdH4kO8eSUCEIZHv4ZZKuKUihqWQjLstwbAfojuSYr3O5LFD6I5Ft9VOBVMSC7CsqIhpmrx94TpIOHhkjs2l1RvAK2ZsNf6fEsC935aklB+Pouj2vYM/ygR1wPb2FrZtMTs7i+M4hNFoFadpegbQozQJwjDICImGKRIxny9QqZT3LftHUN9x2PBwOEwfn3JTFukaUrUKqtUwNMnydsi1S7c5fGwW3SmzsuljmaPhnRYDWEzTQLd0DEOyc/N1WqtXiIY9Nq5eQsPnra9+iUG7j6br6JqGWyhi5QrUpmewLDOtPNR9ynsPBmNizZ3VCNPUKTsrmKIZ6/BnA/tuF5/o67r4ZBh/Ma4iO/RLT/M4GZmWkX7e3Avq0cdXfiMpsFgMRIvVljHY3Mnj9T0qkw3l8ps1Mh1LAuPEr0Smyy2VcQvFGHOhMex57KxtYVpQmTtIsV6nOFGjPDuF5bjKtCaBGWfdqCLJhVevMjs/RaWaY/3GnS+ISAyjUIGfvisTwH7rie9M+T++JtQ0LknJx6IovD2aB4z36olKbBhGGIbB5OQUR48dY25+gUKxmNnjj4g5ySRasQuJhUDVSs91c5TL5X1P/hFuYHyFOBgMUtRhsVii3W4RBP4eRWH18xqSrgcvv7ZMtWSzcGie63f8EcQVJfVVmDlBr2fT62gMBjphZKLrpuL/47OzvKw2D2HMOtQ07HwBp1CgUK3g5vOp96CGjIPq3knAMjV2d+HWsqSQD6naN0H4I9LOPV18xjcFIpsUMtr/SSJQOnsZCe8Mrt8wtPFTPeH9a3tKfm0c76+SiIwVliSbOy7bWyFu0aUy2VCnv5T7JoEo8JFhfPojCQYeuVIRp1DEyRcViEvXiPyA1uoKupWjPDGpVH93mqxcepsrzzzLm5//POf/6NOsX7umgEExanVnq8nlN29x/yMn8DtdWpvNz2Yt6L4Lh4Aaw6GfYgHeba//rcIbjAtuRnt39OellB+LIvEZIaKZbPDvdQ8ulyvUajVMw0RKQaFQoNVsxhVClIHvjqy8dd1PTUd0XY/tye4++bNWYCNrMJk6DE9M1AFlAjocDmi1WhQKxT2OwvFFJ+HVtzbpbjd54Owx/vA336Y7CHHs2EfAH1Cbm0FoVW6++pKyxLZMDGlgWhqm7dPf3mTq4GFagwFDlIllrlzCyefRTRPDNtB1SRCETMzWOHTmFK8+/Rq+Hw8H9w4GDbAtjavvSKandWand6mFt1lvL4yx+L5ZF59oHxefVNBzrArQxkp8LdP3G5n32t6VnzZSB7JtSWvX5OZtHbSQuSOLaLqqYjRdj2HGQgmXaDpREGGKXfRcDiGKaLra4uTLRWSotA4Ny0J6QWy8GuCWZmjducP6O1dora0SDAf4QzUXmD1+ksb8fKqz6FoOb56/RhhEnLzvEGs3lzaGg+EXTdMcYQS+2xKAZTn83u99Fs+LmJqa/rYnACklnuexvr7G+vrdUOL44/NCiL8QReHvCBHVwnBcEzAIQnI5tadP9P43NzaJRESr1cTzvLQvF1n8eRQShnpqXpoo+94d/CNY8jg9WDEZJyYmMU0zXQHatovvB+zsbFMoFMcSRxRFGLrkyvKQt9+4wcnHH+TTbpUbyx0eOpFnKDSkJmnffosj7/kxbNti/cYVdYqbpqLCGgZed5eNm6tYuppTaLqGWyxi53NohoEWw3ZLtRLnPvQwhcYhlq9vsXTlMrphoe+zHTBN8H14/XVJ6f06tcImnm+yuTuRSQJ7XHyi/V18VPDLFEG418UnlffaI+6ZCn1kdv6pBJgxjvlPBUF1iWVBv6/z9mWdbmfIibMnKdWUNZcSlNVIzIKVYYlCG5YbZWyjTdd3iSIlBms4NrqjrgnDMgi9AE2HQQ/84SqdtWVEGJIv5hF5ByGhMXeIxQcfxHRzyCgCXSMKQ1567iIHDs9Rrxe59PTtP9B1vanp9y709e/GCuDbefsGKoDkgX1JCPHxKJLNvYKgICkWS7ium1qIR1FAv9dldXWVzc1NdnZ27vIsHO/TtTEz03GJsTAVGc1+PjH/yOXysW2hlm4ICrH0WKvVHLNKE0Kh0poDwVeefYdqweT46SNcuhkgkei6sowaNFfZuXGBxuIRpg+dpliboNSoU6rXKdaqTB46Qr5UI/BHIBfDMrGLBYIgxB/0MA2dBz/4KNWpKXxPMHv4EJZlxlTm/QeDtq3RbsMrr0qiSGOmukajsEEYku7z5RiyL24RUhxFTIISmaHfPi4+enaXn7D3sl/b0+MnCSGFCye6f7rEtqA/kLx2XrC53mf6wBTzxw7EFOCk34/GqNpREGIAUloYlkOhqCa2mqEqA7uQx3SddHgbhQLCAC3yKE00KE9NUZpoUKw1mJg/Tn3uQCqIKqXENAyWbq1z5a3bnHviPnrNFjtrm58YuSWJMVeq/zQDuDdUOPv2+SiK/kIUhV4SUErtJ4dpWrEmv54i8gzDZGZmhpmZGRYXD1AsFlPtweSWKBpLqfABOzs7tFqtWPwzykiOj1uBKTlzjWq1lswrFOo7kwSSaqTT6dzlVwCS58+vsX57hUffe5L1tsXGTpAOAw1To3nrNbzONoadQ9eLiMDEKZYp1CfIVWpMHZynOl1LsfqRELilMls3buD1d1k4fZzFM2cIpY4/CMiXyhRKZVVNvMt2wHE0VlfhpZclkYC5xgYztQ3Fqw8zRqtJEkgwARmlH5lx8UnBPpl+frS+0zKDQG0E8dXYF/RjZNalhqFO/tau5JVXJTs7PjOLk5w4eyalNouY2ZhNAlJC6AfIKCLwIjBc3GKOXMFSasleiGHbsdnoACklpUaZ2kydQq1BsT5JrlQhCkykyKObjtIajM1ApATTMHjxmQvkCzlOnDnI8qVrbwkhvmi7FpZtprf/tAX45puGz0SR+IUoCv0ExZbPFzBNM9UTTNB9iT+flFAoFjh1+j4cxyEI/FiOzE9BRcnATJmBDGNjDz8t3WViahmf/iMRE5OsxtRoW6ASWrFYIggChsPBWALQNcnlO32e/+pbHDk6zdTcPG9cHWKaxOKXOtLvsrv0SoJaod8asn51jWFnSKFWJV+rM3vqMKVGlcDzEQK8XpfbF17FyTkcfexxTMcF3cH3grglqCkjUk171yRg2xq3b8OLL0m8ABYmmxyZX8cxfXw/tkZLW4KRf9/XdfExxl189CxWYY+gxxi+Xx8f+pmWmiesrUneeksS+hGHjs9x5r0Poek6YWbmkmgcZKf94cAjDAICz0dqNrrlUqxXMEwDb+ApPwIhCDz//8/ee0fJdZ5nnr/vxsq5M9CNQASCBMEoMZOyKFLBQaRl2ZbkGWnH1ngcdjzSpD2754xn9x/bI3vWZ2XLtqQZS2PJ8ng8chAlkWKOICgSAAmCBJG7G527crrx2z/ureqq7gZIKntU30Ghqm7dqrp1+77P935veB5imQQTV+wkkc+TyOdwLJeFk/PUVxvBn0b6GIkYaEro7Qgq5RqHnnmFq2+4HFMXzJ6a/m8g7LUlUnAbAMB3sEQBvux5/sccx7UDYlCVRCKBpqkh2ehaYLBDPTYzPUOjUSedSYe05IFbXqtVcV2XaDSOaUZQVa1b199hIOr1BDpEIIqidoN8nVmlX/FNdpuTotEoltXusiF7YfFM0/a5/5HX8Zp1brj5Sk7OQLXhoemhu6upOI0LtEuvghAIRWDVm5w+9CqvPv4ijVKF1HCB8f2XkRpJUlmY45WH7qdVXiIzOkJhajtSaCiaiWPZeJZDJJZACXvqLwUCQVBNMDMDTz0lKZVhONdk385lJoarID0cS67JePlrhnYxFZ9+11/0uf5qWA4txLoy357aCDVMDaqqpNmEM2dgetpH02By7zZ2HLgy4JLodH6ul5ALg3Oe4+C22/iOG9CXKzqKZhJJJtGjJiCpLhWpLK6SyEeYuGo3mfFhWrUGrz3xIieePEqzXAu1Hn1i+TRGMoYMvR1d13jx0KvUa02uu/lKFs9MV+uV2lcUVQk0B3tvg1Lg79gT+LKUMplIJP6ko/bTa/y9br7vB274kcMv4jgBtZjv+xiGQSaTZXxiAtuyaTYbKIqKEAHtV0e1SNP0tRRSGPzrMA+ttQ93XmcDP0AsFqPdbvXUHXh4voeiSJ45tswLzxzjwO038OgDQ7x0YpU7bkjgeYFH4qPgN84jVQehZNEMA892WDk7x+LJWYZ2TTK+b5LUljiOVWL5zAkiiTjD23ei6kbQ/68ZuJaL3bbQDRNNU/E8P9Bp9AWK2nMh9gQGg4CmYHVV8tgTkiv2CXbu8Ni9o85Qrs3cgs78okKjHngEyItId6v96T5lnYpP1/PoDfL1pPo0da37z7KhWoVaVeI4PqlsjOzENhKF4fD8u0FOXkh8BZS1yF9Q5EUQc3ItK/jetopQNRQ9glBUzGiEdq2B1WyDbDNx9Q6k9Hjmrx5g+eQ0CEE8nUAz9ICcZmKU1FggHYYIgrGtZptHvvk8+67axchwmqefeOZ/IDnH+jniRzEL8I9pKIryp4ZhxBOJ5O93OgDXG38HEKT0qVar3Vk9lQoaicYnJjB0A0UoIUNxc40VRgbNQ6a5xlTbMexIJNJVCu7QkXdczN5S0A4xaDwep1Qq9fU24HusNm3+5u+PcNNPXM/bbtnPs996iOuv9NA1BdcNDMFXID2eZ+vYAdq1Fo1ShdpKiXqxROn8ApXZGYZ2Rslv34aezLDjqpsobLuM0vQ8WkTHjBl4jotr2ShhSbLsFOUIFbg0CJimwLGDwODsrGD3Lhgfc8nudZnaqrC4pLCwIFgtSlq2h21JFOGjm0royos+N369is/6st4+r0GA50HbAtuCth1kFsyoTmpkmGhuDN2IBvyDYcGXglzTQQxBQChKoMsXKvt4joOrqiiqilBN6qtVPNvDtRzS+Rxz585gmBaKkuD0My9TmV8lNzFCspAlUcgQS6eIpRKoMQM/5D5ECPSoxvNPH2NpocR9H343xdl5b+nC4p/iC1zXf8Nr+kekDqBFqVRGyjdHB2YYRpdxV1GUHzQI/EE0GsV13d+/mPGvlf56oeinz/DwMFdcuT9Q+wkLgmKxWHfJEMTyZOjy212VYs/zQtkv0ZfbX08FJnuqwYLWYINoNEqlUu3pQQjEQx4/ssxLz73C22/Zx8EnjvDSiQo3XxMPPiecNSrzF3BkllRuBM0wMGJRIrbN5IEsO67fz+nnnmP68AkmrtxBrVTF986jqCpmMk5hxxiObXd5/hVFYWTbVlxPsnD6HIqmgxAobA4Cvg+6DoonWFiULC7ByIhgx3bB6Ihk1w6X7VOCZhOKJSgVfZptnXLVxG4119b66sVVfNQwS6ApIYMO4HggHbrlxghBNGYizCxarIBqxgKAcJ0wzx/Iovs+XRHUDgj4jks0EUfTFcrVOr7rB3Rfmoqi6azOzGM1WviOiyd9FK3G8rkGumJxw713M/Pya9SWyxgxE83Q0SM6lcVZpKpS2L4zAHpF0G5aPHj/QS6/cidT20Z47u+/9bfS55CqvTm7+KECQFAwY/GOd7yb3/mdfJfx5o3G4cOHOXjwIJZlUSqVSCaTXYntH9CR/4HresOu6/67ixl/7+OgW7BFIp4ISUfb3XViJBLpipHIkEQieByKO7huWDjU7xH0Uor1k4yuaeaZZhTTtLoBwUAARVBswt/ff5T/88YruPH2Azz1zYfZv9sjYiq4XmB4mrdKde41pOsRTWRQdQXXslF1DYTATGTxphdYPVcmNwnLs/OM7jpAfnIcTQvYbD0nUOwBHzNqcu073sWrTz/LqRdeDCveAv28QD9XoGxWLBRqEM7NSRYWIJ0SjI0pDA1BLieZGPPZtjNFeu9P8fD/fIGZl19FNZSNKj5KfysvuGTGd+LbTezaQkBMIgVSVfAx8JU4Uk2AlkBogUvme4GLJIQStCgHvgzKOhCQriSVSzMyNcbMy0cRQse1A5B3nSCyOLJrisVT56jVavhOhdWT81TnKwxNbg2bPgWubaMoKkbUoF5apF2vMbpnf3ftb0Q1nn3iJeYvrPL+X7yH8vy8tzQ9/3uapgVswz+6ABBcqKoaR4gMN998JzfffOdb+oRyucJLLx3liSee4Ktf/VvOnj2LYRjEYrHvWwHRulLKf++6nua67icvZfwdma90OsPy8jKW1Q7X5y66boQgEO2mCzvDcZxuKXEvV94aAPg90udynfF3OAUJU5EWlUoFz/OIRCLE4jGOnhcce+44N91+JYeePsbBl1a4++Ykni8hrHyLyjLSb2PETcZH86RG8sy/dobDX3uU6nIJq9Fm8VwJ14PJq8aJZaIkChmskEXZdVysRgNF8bFqderLK+y//Q503eDEc4fww+61DuOPEFqoJLSxlVgQtPZWalCpwumzCtGoIBnzufln72DL1mtQeL6/uKcv0h+294bBPSElZizGqhUDbxmhpnGliS8iSGGAoqEIBUUCnoMvVBQpgjleCfuoO41LqoKqhYI8LqSySUa2jtGuVqkuLRLJb8VHBgatBQS08VyWxHAWx11l9vQ8pw+ew4yaWPVzVFcqxDMppq69nMxIHqtRw7XqFLbvRtV0fC8oGKpXmzzwtWc5cN1etm0f4eDffetvPc8/tFnTz8WG+tu//du4bukHOusLoaDreQxjCEUxvqPPiUQiTE1Ncfvtt/NzP/cBhodHMAyDw4cPB8imad/18mC9UAcEYp+JRFCz7Tj2g47j5FzXe3snAxDcry0LgoBhmomJgFh0aWmRCxcusLq6gqbpqKoaqgDRFSxZTx0W/N7ohu7A/ue9dOJrlYQgwuWE35U2y2YzYKRxyqvccdtOtGiCJ554nd1TKsm4GnAFCAG+A14bIz1BLJshPzXOxL7dRJNRfNei3WzhWi5Lp5dIjyexGmUiiRxGLM7SybO0Kk1ajWXAwojEiCaSKEInNzqKqmmsXJgN1JnfeRuGaVJaWOzWwgv6ejQ6hQ9dSrPAOxJUKpLJ/Vcyun2M1587THV1FU3Xuim9bsRf7U/9KYpPIj9Crebjths0/SEc30CKMPonwqYqGeaBOnUXa0UY+J5PKpdmdPt4sAxyXVLJBLnhAq7l0CwXqa0so0biIDSscg0jqrPlqj3YLYcLrx2nWS5x9JuvoKka0XSU0V1j7Lj+Svb+xE3kJkaCNHG9iRKJo0di3QkgGjF46JuHOH7sPD//sffRLq5Yx585+s8QYg7Z3xXae3vbT/6LH5YHsDbr63oWRYl+zz45l8vxa7/2L/j4x3+Fz33uczz//Ld55JFHupVzeqjP91Zn/F4Szt7ioUql0rvfJz3PzTuO8+GO8XfAoEPWkcvluoaeTmdw3aAwJxaP44dpPt8PWns7INDbH9But2m1Gn2ioIGhd2rN/T6l4N5UYafd2DRNbDuoM9BUFVWRHDzR5rnHX+Jtd72dbx88zmPPn+WD96QDbnufQIDTWqFx4RCJ3D1IH4xEhKlr9lLYkqA0c56lsxdYOldi4dXzbLtuB2defJ6pA9egGhqt2jJQ66q5S+nTatSRnmRk2w7qpTKLM2fZfcMB9t16M/H7H+L4s99ekwy/KKlIILQhVA8h/K5RapqLaYJp9lf09T7WOjX+gCJckEpoHS6g4PvBdyoEEUIfH/zAuUcF31cRoYpRfmyIy67dhxlRWDw3h+pL4okYrWo9ZOYN9nMti/hwAbvWQNVVWvU2F157Fd+psHCmzOSuIQqTGYa3jVPYPkV8aCuKEaNdr+O7XpA2DHkmhABN15m7sMLDDzzPLe+4lrGxDI//5VNfcBz3+bcy+/d4AMXva5Bv/awvhP49d887AcHrr7+en/mZn+baa69hYmILs7OzLC0t9ZUZv5Uqwd4S4ot4FL7rul9zHGeH67pX9QYGTdPENE0mJrZ2c/2e52EYBqqqsW3bNtKpFEtLi13Bkna73f3O3iIf27bRdSNkGQqDVPib6gz03ncCh2tLEhnSocdoOoLKUpFb376F4a3jPPLICQppj7EhHc8LjEQoKk6zjFVZJJLZSn2lyvTh45x78RTzJxdwmjbxhE+moNFut4mkYsyfPkersoTdWEDVFTzbQTOiZEZHMRMJGqtVXNsmlkoTTSSYuGI3+JLC+DiqECzNzgbr4O55ANcJlkdKuLiVrOkj7rruChKcR6seYuukwsQEjI3B6CiMDMPQkGRoSJLPS7JZSSYjSWcEMb1KudQOaMX9KEKKLlixLqvS8UikCJR/CsMFpi6/DD2ioeoa1aUyari/3WgRTceR0qW8uIBUTMx4kmg6hS98igtzKKKJ5ydIxDw0r0qrIVi9UGd5ukhtuYIX/t5auY7nBiAnwutS01T++ssPYVkuH/yn72X+xMnlU0dO/JJQROWNru8bf+rXNgKA51UD1+v7cAvKMmMYxhCalgS+P2W/XRLG8A82NTXFrbfeyn333Ucqlebs2bOhll8r5M373h2H53me57lfcxznGtd1d9t2EPgzTYNsNkcul+/rCwgYggI5seHhYYrFVRqNBp7n02q1ukKkACsryziOjaYFdF9Bbb3oNhmtlxMLSmG9DTTkltXugkGr1QqIRKIR5oo2Warccfe1FIttnnv+HPt3GRh6YHhIH4FAT07QbkcozSxSXSrSrrewWhb1YouV+RbFuRZes0qrvkJypIBuajRWlxFCMLr7CjKjWxGKQnp0mEapSqsSzG6FqUnyk+O4dhur1iaVyyGA1YX5bmNUKpdmy2VTuI4bKAoRFCkFSxWVQrqBqLwYGMqG6JcIJ6F1NwS+55GItLFck3ozghBBq7P0Q4n2iEluKA/IoIAnrKvPZjOMb5tA0VS0iIaZSGJV6tSWizhtC9XQKWwbo1kqo+km6ZFxbMvBcTwiUQ9VadFsRqgtVDn/wmnKyy6OJcOgqIJrO7SrdVzLCXUGAu4BEEQiJkcPn+TBbxzi3g/dzfhwgue/8eT/5breA5qmoqjKJW9ve98mSwDTnPw+19z/4AsOO0CQzWb5zd/8DT72sY9y+PBhPve5z/HEE0/SbrfDar7v1SpI2FLKf+I4zn93XeeuQG9QJZ3OdI2+l8XX930W5udoNho0Gg2q1WoY9Av2a7WCC71erwOBB5DN5iiViiQSyUAYU653+zdqDkgpQ5FRjWw2h+M4lMsl5ufnAhGVWJK/eeQC11x/gnt++kZef/U8Dx9c4mfemQ6Kd1SFwuV3YCT3UF5cBtHoCk/ILp+/h9X2aTd10q4EcZrJA1eTndxO6fx5tu67Bil1SnNn0XSd/OQYZw69DFKS8j2EoqKZBtKv4VgWWy67jFqxzOzp0yiKitduMDKa5bIrLmd+epYTR16lWqqihG3Y337yLEf1oMBGKEHdgaKIsECno+wrUFSlS30m1IDURO30/MqgbddzHcyIydjkONmhPK3yCqWFEr4aQ3ouMTNCbiTfbT7SDB1F04OUXK2BRDKyewo9EgR4E7lRMuNjlA4fRxF1zIjGyrJGbabI3Msngl6IqBlSoIWybuHx4UsiERM1GqVeqaIqgtJqha/+9eMcuG4vV1+/myMPPPFcrVT/jKproVfIW18CfL878H5QjT3rH/d6BaZpMjU1xb333ss111zDxMRE3/JAVdXvxgPoGHfbsqx/8H3/hng8viORSDAyMtp179e8ALevNHhlZRk7JA7tdd1j8ThW2+orMTYMA8tqhxe22tPltRYY7DX+gMvAZWRklFgsimmaRCJRHMemWCwSjUSo2Tqt1SXeeedu0sMFHn74JENpn7G8QE1NYhSuwWk2cS0LX/pdvgA9GiGRS5EdzzC6LcvErgRDEwaaJlk+P0M0myeaybB45gytcgkjGiWezZHZMkrpwhLVxRUS+TT5yTEUVcNrt2lWGriOQyyeoFYs0W41icQimKYgmswwtGWcrZdtw3VsSstFkOBLBdcNPALPE7ieCCjGPIHnExi2L0KiUIEftur6Mljzy3A94Xse+eECu/bvJVPIYrctKoszNGotUA1UBEMjBfSIGXA4pmPEchmEorJyfo6l09PEsyl2vP1qXKtNY7VIbXUlPM4auu5TLWvEIiqZyCxD2/IMTY2QHs0Tz6VDWrA4kUQMIxpBM/VQddkOEqVC4a+/8giVSosPf/ynqc3Nt1559sjPK4oyrSiiu2S61G1TD+DHopBXrslr33HH7dxxx+3883/+cb70pS/xF3/xJaanp7t19N8NaEkpy9Fo9Bc0TXswm81ds6ZQ5PUJefQuCaLRGKpisWvXHjRd48jhFwHYsX0nW7dM8u1vH8K27a7rnkgkqFarxGJxTNPYICbaGxC0bZtoNEYkEunWGmiaTqEwFHghC3MMj4zz6Es213z1GX7qI3dz6tVruP+J5xgfTpJSFpg59Hco0S2khibITRSIZTOYyQSabuBbFbzGCl5rCbsyS7NYQTG2kBzJsHD6FTKTO0gWcpQuLBOTuaBkV9OYPHAZi6+fxW628VwXVdeJZJIoC0Vcx0FRFca3baNRq4RddS6O3aRZVohnktz0nncwsnWCbz96EMtyAq08H4QIuRXC+jzRlSgXSBnM9NIPjEn6HlKoeK6PIgS7rtrL5O7t2G2bRrmG5zSDpqwg0EM6lUTVNDzHRTcMopkEQtHwPRen2cRutdmyfwdGLEKrWgmK3ByHbF7DaWtUKyqF0TFU1cPQjhLLxjAzI6jxYdTYEGo0g+dJ7EaDZrlCfWWF5elT+EJjdMdlPPv0MV46epaP/PJPk4prPP4PL/yu9P1DSjh5fSdX7Y+rMAgA2WyW3/iN3+DrX7+f3/7t/8Bdd92FZVk0Go1N6ZPe/Oezomna++Px+NGgEWhz4++LC3gBmchVV13N1NQ2kskkqVSKoaEhrr32OhKJRNeILSsQKa3Xa7Tba5Jq/cYfdBR2BFA3pmIFuVweVVVZXJij3HT5wt+f5vUXjvO++24hNTzF3z1SB98hHSsRkWeR9gooGqoRQdV1FD1Qr/U9D9cK5KzKRYVIbjfZLVcQicZZPXmM5XPnyEwMk8jGu1LYufECQ9uHqSyuBp6F56FHIkQzcXwnqCGIxOLEkyl8N5D+0szAaBuVGu1Gkz3XXsFP/Ny7iSeigTFvokrcjZV0SqjDxx3hEddxEAiuuuU6duzfi922aVVrSN9Dj67xHCoSTNMIOAccj0gygpmII32JY1mUF4pkJ3IMTQ7jhwG8SDxCuqBTXGxRKaloioKhCaKJNOVGDt/1AhmwsP5D0VRUXUcN4zwSgZkdpTC1k/PnFvmHv32aW+68lmvetoeXHj34dGW59LsBkMk3fdt0CfC/wrjUEuBS+wBEo1Guu+66bvagVCpx5swZLMsKy3CVN7sEwLbtjrJPJZ3O3u/7/rtc1x2+mPH3gkC1WkE3dFLJJIlEAtM0g6KReIJ4PE6tVuvSjgf4JLo6AJ20YW9dQFBfoJDPF3ooxvpThbpuUK/Xabca1B2DysIyd96ynam923nssdO0mk327ojge2381gJus4SiGRjJLKoeAa+N06zSrFqUVwXVSozKco2Vc7OU5xo4bYFVrVIrLqNFTZLZYRL5PNJzMA2bhVML5LaOYcZjYSxApbpYxG5Z4EvajSZWu0EykyRVKBDP52hXm6GcmGR4yxjpQpbp104TamxsyN13goGdpzJ8HMQ4JVffeh1Te3fSKFdp15vYLYv0cBbfd6gXV2m0PDShEU/EEIqCHjEY3bUFPRoPpM4qNaaPHGfXtRPE83nUSBKrUmJ5boYLZ0rUl9u0llepLxZplsrUlovYTgQpEqhGFCMaQ4slUY0YnuNRWy1TWSlju2BEIjQbbb74hQcYGsnz8x97L/Ovvl56/YVX7kWI+bfqra5fAgwAoMcj6GQPOnGCcrnEysoKlUrlknGCiwAAmUy26rreE47j/KTnuemLA4DTreFfmF/AsR0s22J5eamrPRCJRIhETNrtdshqRLcM2HWdbpqzy6ArwbJsUqkUsVi0y0K8nn0o4BVUqVaruI7FQk1BbxS5655riKfTPPjQKTIJl8nRCK4v8VpFGksnqc6+Rn15GilNWg2NStGjUQ1IQ9vNFu1qHc/xsdvQaqi0qy7luRnKK3NEsyOkMikUdxXPrlIruuQnx5AIdNPE9ywq86t4nkurVqNRK5PMJklksmTGRtEiJo3VSpAJ8HyGJ8ewW20Wp+cRqvKmQAAEnuOwdecUV9x0Dc1KnXajRbvWIDVWIFFI0yyXqK8WKZctdFUjFouCEAzvHCM7MRbWDPjMHjuJqRYZ25ZDSwxTXCxx5LFnmDk+T2O2SKtYxLccFFV0GZp0M4IvkrgyBkoUzxcszy6zdH6e6koZx/G6NN5//d8fo1pp8Uu/ei+q0+L5B578uOt6DyvqW2fQuuG9vzoAgM1eW79t27YACD7wgQ+Qy2U5deoUy8vLaJq2AQguDgA5pPSXHcd51HWd97ruZiDgdA3R94N4wMTElhAUFAw9qBb0vKCuQNOMLjtQ0Dzk9lCVdboI6S438vl8Fxg6acLecuGgQSiImjcadVpt/3BK935la8K+7to7byhYtuRbD59lckxhKKfhhYUz0rNIju1Fi2/BbtrYLQvXtnCtNo7l4FkB+YVn29iWg9XycFo6ntVg5cLr1EpVYjENQ2swf36BSDJHIpcNUl3JCJWFZTzbZ+v+3cQzKYYnJ5G+TzSZIjs1jmu5NIplFE1BUVSS2QznQ7nygJf4DUAgJFA5cOv1mKZJq1anVasTz6YYu3wHTqtJs1QmkS0QzxXYuncnnuOg6IKt+y9DNWIBBdviKudfOsLYFolPhDOvTXP8ueNU5hvUppexGy0IJw9FEQHZqq4FDT6GjhYx0c0ohhlHMXTqpUrA9qMq6JrKg9/8Ni+/fJ5f/Gc/xdRknoNfe+QPm9XG732nVa7rAWDQDnyJGIEQgqGhIX7913+dD33oQ3zpS1/iM5/5k5CBN76BxbhTMNTZ1tEEAHnU87yfcV33Qdd1h9aDQFhPhBAwNbWNfD5PKpVCSkm+UKDZaLC6uorvS6LRKNlsNkwbKmGloIXnBbEGRQmWLJZlhaSj6ppUeU9soENX3tlmGCbxePxYo9H+GVVVZk4eOVEyUumvv+f9N6dLq1W+8s0j/PJ9SQpZnVZbosfzNNtxmgtLCClRdQUjaoKQKIaOl4wj8PFdG0UDVXFRFRtVaeL4GslsltdfniOVFQxtG2b2+GEkCmO7tqNH4ozu3sL00TPEEkkS+/ZjREwWz5wM4hcIxvZuo12t0K63UDWNSDRKrpBj4cJCINIZVgz6HmGLTifkFdbxS59kOkkyk6RVb9Cut1A0wfiVO1A0NUypKaSHx0kNg3RdWoZBYXueWDqNRGNp+gLnjhwhNxGj3HIpnS8yNDnJ8BaJmj2F3DmM55m4vo5j+Wi6iVQCklUjGkGPmGiGiue2qa00kYpEN3WsZhtD13j66Vd49tlXufcX3sWVB7bz3N899EBpYfXfqvr3zmwHAPAmMwedgOHVV1/NH/3RH/Pii4dZXV0lGo2iaRqGYaDrOvF4outuLSzMd5l7pJRHfN//Bdd1/4frutneLkItbBAZHR0lny/0qAgFZby5fJ5isYiUwf6hsXaP0fP8kC5cIoTfTf8ZhtHVCegsMdbc/07dQJdGvGIYxj+xPTETgsMzRx57/jcSmeR/+8Av3cV//eMWX/i7E3zsvhTZlIbVWKK18g0cdYJYfjvJQoHseB4zncSIxlANA5wmbmMFv13Ca63g1OapLZdZWMkysm0f0tU499phtly+AzMuWDxzFN+tkxreQiybI5a5wEuPPko0FUHTI6RyWYSqIn0fMxFj5LIRZl+Zxaq3UBSBHtEhFOvoLRveDASkL9EUBccKvBfPdRjbPYwRMXFsD0UIfCk5dfSVIAXbtimM50kUClRXq9SW51meCXr4hZZh9swKw0OjjE9t57WVCjGjwtBEHi2ZQ43mEWYOLZ5HjaTwXB+n1aRdq9OulKmtrFBdLeJiYqayxGImzx96jW89+ALvfM/N3HbXtRx79JlX507PfFTVNPt7eY0PAOAtAsKtt97KrbfeytLSMn/5l1/mC1/4IufPnycWi6Hrepd1uOMBrEvOPOJ53gdd1/kr1/VyQccfYbNPhK1bp/qq+nzfZe7CBQzToN1u02w2upoCqqqGQUIXTVO7vQaqukas0VmWBIZOX2lw4J2sCZ1IKf+lEOJwABoB8NiO9xeHvvHU2O0fiP3eh//Zu/kvn7b4y6+f46PvzxAzNYTi4cs5pOUjnQiKVuhmCFRNw3dFUAtvu3i2i+9BaVXQKi8y9+rzjO27jlZ5hflXXsf3dPRYAqf1KqW5sziWT71Yw6NFtdQC4dOu1xjesT2si5fEEgb5cZPVORulqeJ7blDnL3hDEJC+xHU97JaF3bbJFFRiieBzO2Xfi7MrVEpFVFXD9zyKSy6vHTxIJAKtWpPaqoVlqwh1mWQixtbLdzB3bprl+SJeNEvO8RGWg1RddCP0EDU1SEF6Opqu4joebVsgonlMVSNqahw7dpb7v3aIW95xHe+59zZOHzpy4fTLJz6o6OoC8nt7TQ9iABd5/Y22xeNxbrzxRj74wQ+STmc4deoUi4uL64KFYgOAeJ53xrbtV1zX+YDruqoeunPbtm0nn893WX/WpMkcqtUqMzMz1Ot1XNfpkox0DLvjznue21f8FMQNIl0wWJ8i7LwP+H9A/r8ArifZMWywY9jAFwLfk88snLuQ3LZn8ub9b9vHkcMLvHRskX2XGURNNWgfdqs41Vmcdg1FNTDiaTQjKK2VbhvfbmHVKixNL1It+6BlkTKGppnUiw00LUE8VUBVIlTm66zMBt5ObiLH8NQIoFLYOsz8mfOUF1YoTE0Rz6TwWjUUZxnPt6kVHWZPnQljGqIbDyHsJVgfExAE3Xy5oTyJjEs6baNHcxipPM1qkxe+9QxzZ6fZdWAS04Sx7TliCZXqYpn5U6u0q5BI5UgmE5iqhgB0Q6e6tIrdaND2YjiWRyJlYMYSaLEUejSJokVwbZfK8iqL5+ZYWSzjuEHzk6GrvHZihn/42iFuuPkq7vvI3Vw4fqJ0+JFDv+A63qE1kdTv/La+F0B8N/nuH0VXvfN4/bbN+gU2W/P3Pr9YtmAzYCgWi3zxi/+NL37xi5w7dw7DMDYUFQX03hbNZot2u/UhKf3/apqmkcsV2Lfvij7dwA6DT4cPoB722OcLBVzXZXVlpbu/bbdpNJpYVrvvODuEI6lUuitA0nH7e2b+3wf+daeYqO1I7roizl1XxLH9oK1aSkkynfjMbR+451dbvsYXPvMPuPVpPvKTKXIZjba1JnQp0dASQ8TyW1G0KE67iV0rUVtdxXYT+GQAIyjZ1VU0Pch9K2oQHFNUQatep7K0TLvRpFltMLprmPRoBs9u06i1SGazbN17Oaap0l4+RWN1lgvzBseeOxmkBxEhaWjQMOT5dKXJBSLgCBRBT8CBG3ewfZtNIjtMdGgXjqdy4dQZissrJOI6RjRKdbXGhePzROMRjFiM7PBwEAfwJZ7j4nsB8UlA/RWqF0twhUBRXVIZg2gqgxqJ46NTK1Zo1RsBo6+moWkqZkTn6EvnePChw7ztlqv5wC/dzfKpM6Vvf+uZDzqW89D3ykr/5Z8dGwDA9xoAej+vWCzyla/8FQcPHuShhx7Cdd2uaEcvALRaTYQQH0omU3++b98+vUPn3ZEiW5vV1wQ+HMdhatt2xscnOPjs01Sr1a4732q1aDabfYxBndleUVRisVhIo0boYXhIKT8lhPg3neIgKSXVhsU79kb4yWtztN213+o5rpEdyf2Xd/z8ez5sofPFP72f2vJZPvKTScaHdFqWxJd0WXp918MLxT1UM46rbKfdMlFUBVULlGoVNWheUXWdaCpOJB0jmoyQysd5/emXufDaHHpEx3YtVs4ugipID8eI5kyS+TS5fALDUCkurVKpODQqbaZfnUEoGoqq4Ev6QCCQDZd4bsDZv+OKCQrjSaKmZHxyGMv2KBebVFertIpNqkstfNsjNzkUiL9aPkPbClzxE9fQrNm0Ki2apQbtWhPHttdETV0Pz3XxPQ/FMGl5HnbbClWWFFRdRQ35KlRVQdN1Dh89wxNPHeem26/l3g/dxdKp06XDDx38oOu6D3UyNwMA+BEGgM22P/bYY/zpn/4Zjz/+eEgFHtTgdwBASsmuXbt/ffv2HZ/uEIxuNH6vu922g1TggasPMH1+mpMnX0cIGQqJuNRq9bB2vEcttqfwR9MUDMNE1426oij/RgjxJ53jtW2Ler3Ozl2X87H3XYm+9BzttoUeiXd/k+d6+vB44Q9uufeu35CROH/5+QeYOfUaP//uOLu3mQEIBGnusE5BIhQNR9lNtayGZBxq0EcQMYnEYySGsiSHsiQLmZBuLCi7rS2XUFSdlbOzqHIFTbRoN6q06oLFCxb1ikW73iAS1UgNx4iPZEgXYhQXqizPrLA4W6LdDIwOFDxXYtsumqkzujXLxM4hhsdS1CoW9fky1aU6zYZDJB4jljQZ2RIhkfIx4wl84jRaccb27kLgkRzOIRQVBLi2S6NYo75cpLZSol1r4FhW4A24LtL1MFJxGraD57ooihY2JCmBMrFQePrgaxx5eZqfePeNvOe+25k7fqJ09NHnP+hL7yEQuI47AIB/LACw2euPPvoYn/3sn/HII4/SbDYQQqHdbuH7Pvv2XcGWLVs/0W63f/9ixt+7xnccO9T/k8zNzeF5bigQatFqtYLuNnpZhfrlqKWUlhDivkwm83VV1fE8l1qtxtTUJL/yK7/Cxz/+cbLZLGeOPs6hr3+OM0cfx/dc9EiQ9jSjJpGY+Z9ufN8d/zo+PML//NLDHH3+KO+51eDGq6LYDrhu+J1I1OTllIopVEUSTceJJONE00ni2RTRVCLQxQvFVAQSoWrYzRbtShW70aI6P8/wONSLFebONigvBW3ITquF03awWzatWgvH9YhPpLjsui3khjN4jk1xqc7Z47MUF2sk0lF27p+kMJ5G01UqxQbHDk5jL1TRFYVIIoIZ09FNAz1iYkQjpIcijG2LkRlJMT8tSY1OEC+k0aNBw44fsjUhQPo+ruXQrtVplqo0KzValTrtWgPfddGScYqr5SAEqSgYho5lOzz25KvMzJV43313csfd13PmhaOLLz3xwoeFojzcqfq8GAD4oeLTWxn/6vOvDQDgBw0Aa0DwKJ/+9Ke5//6vd9fr+/dfxZYtW2m1mv/Wdb3fXa9D2Gv8vQVHzWYjbGlOIoRgcWmxS5Yhugw8G4zfBj4mhPhyOp2m2WyRzWb5xCf+Fb/8y79MLpfbcMwdIDh99HHwPTJDhU7q8pM33HPLp8b37OKBfzjII998luv2eLz7liSaJrDskDIzsR0zt5tYdgjNjKDqgesf8n0E5ymMuquahuv6WPU22bE8Tn0Fq7rC6myZ5dka7VoDu9XEarax601a9TYSQSIXozBmYsRdzrxeoVZuoEdMJnaNkyokaZTqjE4OMT+9wuljF6gWWxixCAeuzmCoBkuzNrXVJvh+AATxKEYsWO9H4jEK43EKk1nMdI5YdoTSYgXd1NBMI5D86sRWvEB8Q4TxF99xcdoWtWKV4lKR4mIxbP81WF6t88hjr+ArGvd+6F1cfd0ujj3x3MlzL5/8oG05R7ROn8U6AJC+h+sEvRPJ3OhbbrX/1T98dgAAP2gAWP99Dz74II8++ih//ud/jmlG2L17D7Zt4zjOpzzP/WS/wfcbfy8wtNsWk5OT7Nixk0cfe5RKuRymIdeIMXpnfuB/A74MQf/DXXe9i0984l9x5513vuE5PnP0cQ5943OU51+jWS0iUVE17ecP3HbdZ/bcdG32xUOv87d/9Sgpo8x9dyUYLei0rUC0Uyg6SiSPFhtFi+bQImkU3UBRtS69tpSSRrnI6rkTJIcKJIcCjkerKSleKGE1G7RrDVq1Gq1qE1VXyG0pMLotTSLeRJdLnHxpnlde8VBVQbvloJomb7vnAO2GhW17vP7iWYoLNXTToN3yOXC14G23jGArozTbCZam66ycX8GxHMxElFgqQSSZwIzFyY5liKQM6jWH8mqV4uw8W/dsJzucCxmFJb4bSJe5jkur2aJRrVMr12nWW3iuh66raKrGiTMLHHz+NBPbJvjZD9/N+HiaF7755JNzZ2f+iWFGzllNC1VbK+12bAe73cT3PKLJHFv23MDWPW9j3633ompvjVPTjCYHAPDDAIDNti8vL/M3f/M3PPzwo5w9exZN0/B971Ou637yjYw/WPc7aJrOvff9LNPTMzzwzfs3/e7w+38F+BzAPffcwyc+8Qnuvvvut3yum9UVjj35PzjyyF+wPHMG3Yy+ffe1l//X6+657fKFxRr/88uPMHfuDHffZHL9FVF8CY4juxLfEh2EAVoMoZj4vhrEN5oNnHYd6QfcB5qZYuradyGJ0CiVaVaqtGsNtIjJ8I4tDE3miJgOTnURu7aI01jmxWdXWC0GSryp4Qz7b7uCEy+c5vTLs0zsHOHArXs48vRJZl5fRFE0MmnBXe/JEU0Po8WHMFKj2G6ElQsVls5cwKo3MGMx4uk08XwaH3j+4WeoFsvopo6m68QTUWLJOKquhnUHgZCrDAN3Ilzrm6ZBq+3w/JHznJ8t8fbbrua9P3s7tJs8/43HP7u6sPqJSDxSV1WVdiMAAOl7tJtVEDrb9t/J1j1v4/Kbf4Z4euh7lz4fAMAPFgB6v7+zT6VS4atf/Vs+//nPs7y8jGGYn/F9/1cvZfyd59Vqjcsuu4wr91/FQ996kFqt2l1n9hj/J4E/uOeee/it3/ot3v3ud1/UO3mzo10v8eJDX+LlJ75Cvbg0EU8Zn779A+99fzQ3zINfO8hTj3ybbSMW77ktwVBWw7IlrhdkCYJ89lqaLrhXgvSdJ0FobL/xfUQSo1QWl2hWaii6Qnp0iML2rUQSEezKMlZlCa+xgm+tcubYNK8fL+N6PpFkguvfdS0vPPoSxdlVdFOn1XRJ5BPc8r5r+fYjrzB3ZgUhVK66Osk1N08izAJqLI+ZHsbMjGDbkpVzM5RnF3Eth0gyTma4QL3R4Km/ewjHslC0MKAXLmWEoqCoQQZCVZW1QJ+iMDNf5ttHpjHjcd5z7+3ccNM+Lrx6snbsqRf/j2a9+UdCEeimjiKgXq4i8MiMbuOya9/Dlj1vZ+rK274/9TMDAPjhA0BnPPvsQT772c/y1FNPGe12+7+YpvnhtZjAeuMPFIM7ZcOFQp56vU4kGsWx7S6VGPBb99xzzx+uN/wO4/B3y9pktxvMnTzM2WNPcu6lb/3mll3Z/3vPjddnTp9c5mt/8ySr8zPcdp3B2/dH0TWFth1U5UmfME0nuiDg+4EbPXbgTjJb9lOancF1bCLJBKnhPNFMGs0wAR+vWcJrlnBqS5x/+XVOvDyL5/ug6Fz9zus4fug1irOrGKYeZkGg1XJJF5K8/e4DPP2NI5SXGoDgmreNcOCWvZjpEUQkixYvgGbgOxbtao3a4irNShVFVchvHWf69CzP3f94mMoMDD24D2nIlCDdp+sqtbrFS6/NM79cZ/+1e3n3+28jn43xypPPHz577NSvCUUclNIP1YZ84qk8+Ymr2LLnBi6/+V6iiez3t4BuAAA/OgDQGU8++SSf/exnefjhRz5t2/Y/j0RMbb3xu67bJQbpdPYB7Nm7lx07LuMbX/+avPvuu//wE5/4xL++55574p2yxM5+PccZF0Lk3nI4eePwG5VV6+Un/vs7WqVX/uPojviEGk/z5ENHeerRF0lHavzE26PsmozgS7CdEAj6QEAiUTDSW2jWHIxoiuTwGKmhEeK5HGYqiRGNgvRolxZZOXuS6ZePs3RhEYTAdSVGPEosHmVxZgVFUXs8DInngW15FMayuL5kZa6MlAqe57NlW56rbt7HxOV7iObHUI0IrtWmXa3RLJYpLy5SWligvFLCV6LMnV0E/B6ewQAEVEWg6Rq2Kzl1foVT50vkR/Lc9d6buObtl7M6PcOxJ7/9/5UWV/+Dqqslz7GIpQoBcL3jQ1x+833B84tc3wMA+McLAB3dM1NKKdYZXgGIhsc1AuiA++ijj0186lP/6d8/+eQTiUgkFvL7eV2K76CTUPZ9j64b5PN57rzzDv9LX/rSMSGEIaUc9X1fyM6+/Yerht/X/9LGB5e+kBAoqiYVhVa9Wom+9sxXIlbtRSZ2T7C40uah+5/ntZdOMDnscMcNMbaMGkgfrNAj8OWaJ+C5XkjppaLqcfR4Gj0WR9EiCKFgt5pUFhdplEu4ro9Q1O5ywnWDklehqHge65YZgSfgWKGsl1CCwiAPHDsQ3EznUuQmRommEmiKgt22sJpN6uUazWojYBSSPpquoSqh8Yezv25ouK5kZqHC6dkyihHhxtsOcOs7ryWqSV599sWjr7949N9F4pkHDDOgVLv8lp/jytt+DlU3MSLxS17TAwD44QOAHhqLLoQoAHFgWEqZAHJAEsgCsfA+DmTD9w0DqpQMg9RlaHjhdxlIegxUhjLhGuVymY9+9GN8/ev3E4vFQrVfF9t2u2pF6936j3zkI/zn//yfSafTQUDqEkYtN9j4Om/lO/h7qKqGUHTmT73IzLGvksk1yY6P8vrrCzz2zReYOXuOHWMe118ZZWos0DqwbInn9S4HQoP1JJ7r44ZBtcBrEEihIlFACnxJn7F7Ht0aiE1BwFv7Dl/SLRv2/UAC3LFdPC+gCO9ULaphyW5nbS/UYNZXFQVNU3A8n7mVOtMLNTxF56rr9nLbO6+lkDNZPHNh6ezLZz/daji/f/nNP93cuudGRrbtB6G8KaMfAMD3FwCElDICmEKIYWBUSjkazspDQD68z0kphySkkBRA6lJKTYLRMVrZY1GdCzAQvwyT8mt3ofGKbsVeR0Clu00EAbNINEKxWORjH/0oDzzwAJFINKQV9zY1/vt+9mf5/Oc+j6pp3VqDjRO/7Pzb7Gx2DqfveN/SRRX+p+kRHMti5tgj1BaeZGSLSTSb59Xjszz1yBFmzk4zkXe5eq/Jzi0mEVPBcQKVXr/HcGVorJ4MDFZ6a8YbeA9sMPbvFAQu2jvQWeuHtOMdIGjZLgvFJnOrDVQzyr4Du7j5jgOMj6WoLK7SbmVnRne845OjO676pqKothFNuID3Vox+AADfPQAYQFIIMQlslVJuB8bD2xYp5Xg4k5tAREop1vr4ewy6Y+g9r3Vr7+kRD123T3ffHsNbO+89xhhWz60ZXvC+aDTKhQsX+PCHfpHDhw9v4CjsGP/73/9+Pv3Hf4yu6X0SYxv+xlJuNP4e7+B7eUUIRUHVIjSrq8wdfwi/8TKjU0nMdJbTry/w3FPHOH3iHFG1ye4plb3bTApZA00VYVXhWnnxDxMEVFUEQT9F4EmoNGyWym3KLZdUNsP+q3dx3c37GBmKU10u0awlGN/zXrbsvbGtKliB2Dgl3/cvCCHmBcxImAdmhBDngLNSyooQojUAgO8cAISUMi2E2COlvEII9kjJZUi5Q8J2ICZ9qcueWblrjJ3HoTFtNPKOofZv6zfsja/1fUfPto7N9TbvXOo+Golw7vx5PvpP/ynHj7/S9Wg6r7/zrrv4sz/7bKgbYPXaete65bolgJSbO/ny4ujwZuf/dfGQQGJMUQ2a5SWWTz0J7dcY2RIjns+xuFjj8PMneeXoSWqlVQpJj51bdbaNG2SSGrqm4HkBGHgeuJ0lgv/9BYGO9+GFAcSG5VFq2lSaLsIwmZgc48B1e9h31TYSMYXKYpl2K0t+8lYmdt+EHo0EjMY9Qd+OjqFAhBWbAiHwhBBNYFEIcVoIXpOSs0KIV4BXpJTLQgh3AACbjxHgJuAmKeUNUsr9QEZKqW00zs5jf037DcCXXePtdd+775Wyb4bvlQrvrbfvfR6+ce1z1nkMva9t+t51zyWSWDTO2bNn+N9/8zc5HGoHALzrXXfzO7/7e2QymQ0twZt5Af3eybo1gmCT7SHJiNgIEkKswwjRDwTrJy4ljA+0akUqM4fx6sfJ5T1yYzlsX+Ps6QVePnyas6dmaFbKpGMu48Ma4wWdkbxORFfQVAUfcL2AhNT3whnbX0svvhkQ6GYeOg1MBDEIx/VxXEmj7VGuO1RaLk1bopomQ6MFdl8+xd6rdjA+nkZabcpLTTzGyW29meFtV6GbJp5rI6QMxTqC86Eo/TwVQvSIeXSAoQccwvfVhBAnhBDPAy8CjwGnBgAA75dS/iJwF5AL2FbB78y0fTN772wcKMtK1jfL9M7Kfr9RQsip3lHk7dm/6zGs219ubtj9HsLFZ/1eYdC1OAEYhkG1VuPJxx9neXmZqW3buOmmm1FVFcuywm5ANnggvQbfu/xYeyg3zPXfzbWxgYylBw0Cj0BDUQ2cdoPa4uvYpVeJ6isURqPEsinaNkyfX+bkq7OcOz1LeaWE57RIRHzyKYVsSiWb0kjGVEw9kPkKfjwhnVdg7F7PDI8MWpZdNyA+8TxwPR/HkTTbPrWmS73lUWt5NB2JVDTiyQQj4wW279rCzl0TjIxlUHybeqlBo2ZgpC4nP3k96aHJkHPBhr4ZPzTydTUXa0y9EoQSnBPWAAGC39EhMRVKl9m3BTwthPgq8BdA9ccNAIaAz0kpf7pXFbe7tkb2GRDr1HM7m31f9qztQwIN2eMZbLbf+s9ks9lfbvAK1gcFfV+ua9tl3fFt9EZ6wSOgAzMQihIIZrbbIcXXRjAJLvr+VGHfPh3A6gtkrgOki68N+v38dU5Ad4ZbBwr9hqCgagZSQru2QnP5dWT7PDGjTHY4TiwVRaoG5VKLudlVZqeXmJtZplQsY7WCPnxNuER0MHWBrkHEUAL5b0Wgq2Ersw8tK/j72q7EciS2I7E9iespCE3DjJjEE3EKIznGtw4xvmWI0fEc8aiGdC2aFYt6TUUxJkgMXUF2fA9mPBXqI3bYmMRakxOgKL0uf3gfipvSMewOKHabuURnA0r3/K15BYHwrkAIcRz4CHD4xwkA3iGlfKQ3+NVnsD3uPT3MOP3uescQ/Q0BPL8nBiA3eT89ywi/6+nLTfddO7V+n6dxse3B+7t+yIZta57A2jq1u72z7yagt5nx+77fZ9ibeR3hN3W1uDd6EJ1Zq0O/BdAzm/VdtD1gsMmMqCiBSKei6vieh9Mo0yqdw29fQJMrJGIOyVwCPaqhaAa2I6nV2pSKNSqlOuVSnVq1SaPWpNm2A2pyz+0DLRGmVs2ITjQWIZGMkUrHSGcSZLIJ0tkkibiOKoJOPqvl0qjYeGTRoluI53eRyE8SSaQQSKTXU4ehKOt+k6ATq12vk6mghOdM9AMF6/Q0ez+zZ0nVCQKH+34K+Dc/TgBgAv9RSvnrUsrERT2AtXD7BmPoN8b+KP56I5WbGdM6D2D9fnSXEeuMtLtdbPAq+oKKF10yvJlt62b2SywvNngEl/ACLhUOFJdYAmy471nrrvcG1p4rYZeghkTgWg3cZol29QLSWkWRZTS1jaHbRBMmRkQn4P0QgUZgRw6848EJCDztwIwUEbZIhxoCnutjtxyslo/nR/GVNIqWJZqeJJ7fihFNYURiSOkF6Qfp98zKG0U5134Ha+DWZ8j98QARchQK5WIeQM/Sae17fCHE14HfBM792MUApJS7hBAflFK+V0p5NRDrc7/Xuc/rZznfD/u217vhfcHC/nX/xvV/B2hkXxpvoysu+2b2tcfrjX3zmEBnGbLRs9gIVr0R/g3LATafxXs/uxPwkxdJDUghNokCSoTcJOK3FgrseglSXgwUwkCZEGtlCGLNwBRFRSgaoOA7bTzXwrWbOM0iXruM7zYQ0gK/haqBZ9fDphy1G9n0PBehGAhhIoWOUCMINYZmpjDiBSKJHJoeRY/E0HQD6YcGH/6Nu8e7zlCVtWj+RuPuBYnOur77W/t/Y18cIDxnSv+ywgbOCCG+Bfy1EOLJH9sgYE/qTwW2SSlvAQ6AvEFK9kopM0i6Kb/ujNkx5vWz5fo19ybBPH9D6u8iM29PAdCGQCT9lF392zbWDmy8X1s6XDy6vx5I1mxzLf7ABlAQPYbf+563eomsGXq/R7C2TazbT2zAjjWvoC9d1o0bBEYTeApCdPQbBZ7rBufHc5DdQFoP2CgqqqqhagERaRj7D5c6Mjwy2ffdG2b17hJG6TmmtfV94OH3vrfnNyjrl0E9y4H+3+oLIRrACSHES2EG4GngNSFE+3tVG/C/AgCsNwRFCJECLpNS7hGCXVKyU0q5E9gupUxKSQyCYp/eGXzNgHtm0T7vYc1ofLmWIuxfQvCGNQBy3TH3Awg9xUAbA3qy+9v7DbPXsDuVhGwCDpvVA/S9Li6d9r/Y5XLJa1EEqcTN9uk19P7P6Xd7ZVge3QtO/WAi1i0vRJf+e80IO9Yq+114+j9LWfvwdW49m87M9AXq+gFrQ+xjYxzEAupCiGkBZyWcEkJMAy8DJ4EVwFl/zgYAcHEAuFg5pQrEhBD5sBJwi5RyixBiSEo5AYxLyZhEFkTQsGNIKZXuCqLX1Q89iQ3rd9hYKbhW5reucnBjGq63ZqHX2DYARU8db+/jvlRebxkyG2f7Xu//DWv/ZN8nXMTGxaWDApukAy+WNuxbNmyWZXgDDYh+o95kFu4xQPrcbvpAB/oj+D0zTNeN7/VeNskCWAhhC6gIIS4ASwKxIJFzwKwQYgaYlVJeCGd7582mWAcA8NYBoO/kdbb1KPkonZ4ARVFGpJR5YIygmWcYIXIieJyXUuaADJCTEA9LiBWkNNb3A2w+0683SLmxR6CnOGd90K7PEOXFavzluh4AySZ3m3gBb3jmL2XabyZL2Ldvv4HLNSAR62BFiG6lklj/utgYZOx996UAo2u8PWjTn77sApGLEK4QwhHgAosgKkJQBIpCiKKUsgisCiGWw/sFKeWilLIphGgTvO8Nm9N+kAAwkAbr8aKBZngrbXZ595xwhU5XIOiKqo6E4DEkpYwTdAYawIiUUlEUZTT0QPLhfvEQPCQwhJSR0KxMGaQIgmyH3FiyK7mEa99no+u9gU3M/iJdgN9VJfA6LBCXeGHT18Qm8LB+XyH69lkfa+h54gB+uM0OH0tgUSBcoA6yFG6bF0LI0IibBLN2FaiGNfoVIURNSrkqpWwDVlie666fWP4xjQEAfOdgYYU3NgOMi7m3vQASbjUkKAIUVVVHQ09EAUYBVUrph7wBSdlxD4LyZ707kyhKBkj29Af7QEZCFom3zri77+9fM1x08S+BKEEB1hv5BhWgIi7lDoi+syIlLIrAOPumfiGwgaW1gxICgS19f2ndeV0GWuE5dYKZGRm+tgK0RGDZK77vtztgIITwCcr83fVege/737fmmx+18b9EM9BgDMZgfGdDGZyCwRiMAQAMxmAMxgAABmMwBmMAAIMxGIMxAIDBGIzBGADAYAzGYAwAYDAGYzAGADAYgzEYAwAYjMEYjAEADMZgDMYAAAZjMAZjAACDMRiDMQCAwRiMwRgAwGAMxmAMAGAwBmMwBgAwGIMxGAMAGIzBGIwBAAzGYAzGAAAGYzAGYwAAgzEYg/FDGf//AJU1LI61xvh/AAAAAElFTkSuQmCC"/>
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
				    				<img src="data:image/png;base64,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"/>
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
					    				<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAAJvSURBVDjLpZPrS5NhGIf9W7YvBYOkhlkoqCklWChv2WyKik7blnNris72bi6dus0DLZ0TDxW1odtopDs4D8MDZuLU0kXq61CijSIIasOvv94VTUfLiB74fXngup7nvrnvJABJ/5PfLnTTdcwOj4RsdYmo5glBWP6iOtzwvIKSWstI0Wgx80SBblpKtE9KQs/We7EaWoT/8wbWP61gMmCH0lMDvokT4j25TiQU/ITFkek9Ow6+7WH2gwsmahCPdwyw75uw9HEO2gUZSkfyI9zBPCJOoJ2SMmg46N61YO/rNoa39Xi41oFuXysMfh36/Fp0b7bAfWAH6RGi0HglWNCbzYgJaFjRv6zGuy+b9It96N3SQvNKiV9HvSaDfFEIxXItnPs23BzJQd6DDEVM0OKsoVwBG/1VMzpXVWhbkUM2K4oJBDYuGmbKIJ0qxsAbHfRLzbjcnUbFBIpx/qH3vQv9b3U03IQ/HfFkERTzfFj8w8jSpR7GBE123uFEYAzaDRIqX/2JAtJbDat/COkd7CNBva2cMvq0MGxp0PRSCPF8BXjWG3FgNHc9XPT71Ojy3sMFdfJRCeKxEsVtKwFHwALZfCUk3tIfNR8XiJwc1LmL4dg141JPKtj3WUdNFJqLGFVPC4OkR4BxajTWsChY64wmCnMxsWPCHcutKBxMVp5mxA1S+aMComToaqTRUQknLTH62kHOVEE+VQnjahscNCy0cMBWsSI0TCQcZc5ALkEYckL5A5noWSBhfm2AecMAjbcRWV0pUTh0HE64TNf0mczcnnQyu/MilaFJCae1nw2fbz1DnVOxyGTlKeZft/Ff8x1BRssfACjTwQAAAABJRU5ErkJggg=="/>
					    			</div>
					    			<div class="wpfc-exclude-rule-line-delete">
					    				<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABGdBTUEAAK/INwWK6QAAABl0RVh0U29mdHdhcmUAQWRvYmUgSW1hZ2VSZWFkeXHJZTwAAAJdSURBVDjLpZP7S1NhGMf9W7YfogSJboSEUVCY8zJ31trcps6zTI9bLGJpjp1hmkGNxVz4Q6ildtXKXzJNbJRaRmrXoeWx8tJOTWptnrNryre5YCYuI3rh+8vL+/m8PA/PkwIg5X+y5mJWrxfOUBXm91QZM6UluUmthntHqplxUml2lciF6wrmdHriI0Wx3xw2hAediLwZRWRkCPzdDswaSvGqkGCfq8VEUsEyPF1O8Qu3O7A09RbRvjuIttsRbT6HHzebsDjcB4/JgFFlNv9MnkmsEszodIIY7Oaut2OJcSF68Qx8dgv8tmqEL1gQaaARtp5A+N4NzB0lMXxon/uxbI8gIYjB9HytGYuusfiPIQcN71kjgnW6VeFOkgh3XcHLvAwMSDPohOADdYQJdF1FtLMZPmslvhZJk2ahkgRvq4HHUoWHRDqTEDDl2mDkfheiDgt8pw340/EocuClCuFvboQzb0cwIZgki4KhzlaE6w0InipbVzBfqoK/qRH94i0rgokSFeO11iBkp8EdV8cfJo0yD75aE2ZNRvSJ0lZKcBXLaUYmQrCzDT6tDN5SyRqYlWeDLZAg0H4JQ+Jt6M3atNLE10VSwQsN4Z6r0CBwqzXesHmV+BeoyAUri8EyMfi2FowXS5dhd7doo2DVII0V5BAjigP89GEVAtda8b2ehodU4rNaAW+dGfzlFkyo89GTlcrHYCLpKD+V7yeeHNzLjkp24Uu1Ed6G8/F8qjqGRzlbl2H2dzjpMg1KdwsHxOlmJ7GTeZC/nesXbeZ6c9OYnuxUc3fmBuFft/Ff8xMd0s65SXIb/gAAAABJRU5ErkJggg=="/>
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

				    			<div wpfc-cdn-name="cdn77" class="int-item">
				    				<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAN8AAAA+CAYAAACr4c4LAAAABGdBTUEAALGPC/xhBQAAACBjSFJNAAB6JgAAgIQAAPoAAACA6AAAdTAAAOpgAAA6mAAAF3CculE8AAAACXBIWXMAAAsTAAALEwEAmpwYAAAUSUlEQVR4Ae1dfZAVxRGffe8khjIgRg8RTciRiGJA4kcQQ+4SRas0mjJRsZRKTKzy4I+UGlOFmKqcB1aiUJUP/CMVTsuQGEiCGC2ljCWkIhcU8CvEj/MjAaOi4PmNlkG89zb9m53ZN7tvdmd2376745ypere7Mz3dPb3T0z09M3uMueQk4CQwJBLwmkn1M5csGrfr8a1t1VK1jXlsnKTlVb0dnu+9/dGzf39M5rmrk8DHTQLFKt+Rsz5ZHjPqHMb8ixjzvkHCPNBCoP2ex1Z6ldIap4wW0nIgI0YChShfeWrHhSSRH9CvvUHJ7KX6vy9VSz1OERuUpKs+7CXQkPKVj+voZD67nlrZ2oSW9pISXu2UsAmSdSiHhQRyKd8Bx3z9RL9Uvc1n7NhBaEXPxBmzFr20+sa3B4GWI+EkMGgSyKx8wtqtGDQOA0L9ZAXPdlZwkKXuyDVVAvbKx4MpB9xGgZTzm8pRGnKPza88vbEnDcSVOQnsLxIoWzEaRDHvI8U7ywq+eUDneq2f3eu//uKDzSPhMDsJDI4EzMoXKl7DkcxCWuQxb45TwEJE6ZAMsQSMbmd5avvaRlzNGW3vsxM+/x4bd9AAm3LkB2zLs2N4kx//z6fYth0HNdL8uZW+jbc3gsDVdRIYSgmkKl/puPZraCfKjVkZvOzMXeybp7zJ2r/4DhszupJY/eU3PsHWPz6O/XrdxFyKWClXJrMnN+1IJOAKnASGsQQSlQ/LCbQt7NEsvJ/z5TdZ97z/shmT389SjcOue/jTrPsPkzIpITH/zMCefSeynZv/l5mgrsK02W0UVb2QBpyzqfgY+qnrl720E2cLq3rrB555YIOuOvKyDlhog8/8Pt9jj5C8bzcNJrShgVZ46hOXRd/GqfUlQU5SPd/zF1Wf7l2ale8kOuSNeEXiSqIzEvJLSY2gjvCLpLJ4/mFjP2J3dT3Ff3kUD/iguI/e9Bj70bdfjqNPfMY6Y3nsqO8kAlgWYKChzrmxXClvF5YeO3VUxQOmdt9nC6mzrgcs6liiTwUL1kq980EX9An3CkaDQGolTSHwtBzXsVRT5LKGqQS0ytdy7NfmEL9WW8WgeH9d8gRXniLauPSyHazniufsUflsOaOgkH2FKCRGaWHhrdorarejDl/zjKIr4qlTKCG27GVKGBzEu8tUzwEPjQS0ysc8/yYbdqTi5bV2STQuO3M3+9n3rKdyB7aMHdWdhCstH1Ymz5w2xOmzFU1SQJBYkwc3LDNOk4Q8upthK4E65aMOeWHgCpl5vvnK53LN78yYGVt4wcsMgRubhBE/a4eDxSPcnTb4U2FIAfO4iak4ZSHhzuPevrJt810ShbsOXwm0xFmjiftiUj5jgmJgntbM1HPF8+yerYey1989wEhm578egiLZzXloTuVVrKK4vYJwPPgS4YfcxGsopjs/kql/kPjU0lR3V8y9O9QKFvft3J2mQIoFLAeh9VPsndXxF0eRJgu++6hIXHHiI+k5qnzUKf2KebM03M2fXPKitRz2fFBmO3bVpmVZ3NSfXrqDdd40xUir5HuXVi2VD8qShlBGAFWYlD2t/XRQ2OpQMEUC65QIFpssFZZzkqxwO6xf1n2tcKep3gbbemLbnnHrHoJNxGs8GMVFhaNgWFgqEhdHPEL/RNxOhNlt2vnDb73Mjjr0QyMolK7zpqPZIXNns5OuPDH8TZh3Klu29iiGclPC/A8L9abEXWX7KGFSR6fprncGQu9xeuhQJJ+TlHwo3XxSqPGN7DfFaQ3CMZ+WMZYpuCO3tJ6JAFjmRFbz3qzueCqRQL5aS42lDltF5zSKxJXK9PAtjCgfdTysbxnTxV/rN8Jg3W7K5TPZrfdPqIOFG/njlW3stEUzrBRwbruZHoiUq2VjJzVEA3vS1vDQuWAViRR21zSkdHGhHHH8LFg/bbJ9L5rKrcKqaoqyZ6V5DLRO+assGIvElYXucIKNKB8xph3VVIZhhUxWb9v2g9jly6cY52rYXgYFNCUbZec4fGZce/NLflsSPbhNSWUyH1aRFK/wbW3BeUX/DkkndjW+lxi8+tiJIJqakedeWNBEj2Hi8bOsZVIkrjxtGS51anM+uAHJO8FCfk+fYT7T2r1qklHxJEIo4K33H06Rzd0yq+4KZcc80xR4Idfnq3WVYxm0m2QcBQRiucFjJrdJi6GxTOxy8XzWjCNbaxrjjLFX/rU5TYF7shx2LhIX2gVlRsBNeAh8oIIbTK/5noFSZUV815AOntD0kldz75HHnxppCzwlLN+o8qPBV+7igacIenDN7goPfZMuCct+HuW3gpeq5/8uPp0JLV/LQEuiRSAEYcLm6LSE/ZpwObMk7O00pZlT9phA6IsW5mCREcl+CiDc4eZxH3wuRIuf5qR1c2QtoMwsEBeCUeRav0WKB7c99BDQF7AERUqwXV0vhTLp4FEXOFBmmJqwlqkdfTF6CEB1Ut27QAs08Uw/HpgCL4AXy1uUHaRQ+ejlWS3Mth78kayrvWKjdNYE62cKvrQevM8OrWG3iwiDa3HlWVPTIsqZSVbv5JxVGbnMcj6aF0ViPdEZtRFOqtQbtyyJiKigSFx4X2J3UhpJ+pheMB0BfNyK6SoCJq0vpAzy7UQr8SsPpIBXq/RC5SMTbaU1R346Pcr5xh7zmpzKgLx/94OaByzz8lw/095xYFq9UqWUuCxALzJxTiNxYvRSR1KZ3/CVR/8SvxLQa4Mfbg13t2yAM8BQZ0x2OT22KgMqViSuaqlyrYY2XEBVXj2IJgNOBw956WSmg43RUmnEirgbqiuPDGCleC3T8843P5EKcuiYdMuYVHns6IGkokz5Lz25KXUQMczrOtNcDoyG3N3AtrKpHa8VqYTkqvw2qaHUYe9NKovnD5Qr58TzGnk2BUeyLLMUj6tusIKijadfBz9uFiwFccULaEfhsbwzQCdB8Ktf6vHOF/zWiQ9LTiGNWCm9q0WSB9zHiiOPmZWv/510y3ZCjuNEiKCmnfsDx/3vjIownvjwdmVXYlmtIDGqSQJbH/fNUQ2KFnNxWuFiQAl18DVShjtMzoOF63C+Eq9Bipl4hCkOy11A6nR1+TkzxM4hbe36DqsFCzOLxPXqP7fURbYR8AiJ0TlPdWDQwQ88vTHcbKHeSxy6OigLB3DNWVK4/7K+ei/z1Gvo6+ET7tTx1DLt/XM7R2vzZSZ2r0CZspxSt1nH20mBHKtkcbYPAQLq0IkuJqwbKQQm8NJ1OIYULeIyKLy0Enyb8px4K5QsLCd35zCLHUW94csOa6bfoNPRFwjObOQLBJJCfJ4i83HlkUQ1w3DfCC54HVnlYGBnyItDyzfw3ocP2nDzt22pXh1HcQttuLZNUFRsok5LWDe0VGapLGnoGKyDySUQCGCN8EtSPA4WGXFFxYSLxMevKRP3sDoNFN8PHzLcTJxx6uUEjvlP7kSDBeZ6SW3PFGjJi4tHD+kAMbwOwvGaDIQc8aVTQgsjG0jRRgyYPAFO9Uh08Or5R/Ve4tDVkWVFXEPlE6fBjZ0XSoDlhLQE64fDtVibS0tQvDsJzpTu/6dZ4TmODJN/seaS6H6aeFLK52ZZ41LqmW/hPmpcG3NFxsATWZp5NrDJMPifGwkpg6wDDNlx8TlXNHrYSkrID3knbErAhoLX6LcRykrthwfDo486eH7+kZYNsHSA+2hL/Tua9l4FoZryUQZZg3ujDOif/vhA0mBYg8eJh+du3qo9FgSlxHk9nFw37ZYBxjW9ZnqAq5Qq9nMjwFMUzNICAn19Cib01js76hGk5sxV5yypkAmF2CqXu33p0df+TLzlxJUw54LXwFOpWr5B3itXdJYQhu6hkFwBdfDwPnQeiA5WoVHIbUT5sLpvg/WXd9ptikYQBceCBtZtZI8uf4xbwxdWbmG7Vj1kdDUlH1iwt3Y5c1gJWEBEr4ie0epLngDLI17N+YBvDyJ1NDAUotR5lx9aquXEoA0ptPUnRiCzvLj0+2xrW/AwBxTvTnk1mltx6gTwZA3P0EBEsgAzGPPLiPIJM2tUQGzz+umfPhth2PQAVxTW0MbSqbjwUSWbRAK73gZOBwNBy9CxsBRQxPh8CR9QWoYXA9iiXk6wxuTfAbpC6XK7mrq2IS/r8gPcvXo3rIadXDrrgaFRXEK55LvoFXPZkBm8B5pzH6K8N14GueJ9cZkqgyQUWgdPlbC9bBHK9EofkizshniMJYS+g+0xsYL6R1izLGfz6jGk51xzaxv7+V+OSgeiUggaazVGQAfgJDCMJBCxfJyvwHUzWj/AntU13Rh8ydtWbLa2UTyO3/euyEvH1XMSGCoJ1CsfcWIbOof7+a0lXyxcATHPszm9LoTWO1huwlC9JEd3ZEpAe5T83Sc37cX/Q6BNyHNMzd799ii2+u/j2Rw6anT4IftM4MZyuJpX/uYLRjgJQD79aaz/JfM5J1nBXZ0EhokEtMoH3vCfgEqHTTqNbo2RlQ8+LLOe+45g2IWCOeDYlE/EJ7UbC+nndE9jdz50WBJIXT4myP5T/1hXV+AynAT2AwnUB1wUphGpol0Dr1JW6kkBpQpfWP/u6bvZxR39xmAMjhH1PnUwu+W+CZnPALogiyp1d78/SiBV+dAg7PInC7M+T+OwmH7uzDfC/1AkceA/FWGjdNZDt7I+rggh5939oeJp1v0p3avm+L63nPAHUViPra8yf8Ej3fN25KU587rVi3f9+4lf5d15gcF0whemX7V18SXXxXkg3LTWHEl9nudfuaV7nnHjwsndq9ro63HbCa8HPLRM8b2Hl1zyuwi2Jj0EfHs9WxdfHFmXRD7xf0Ya/2nyaJRdVSZJuLQBFxUYwYysu9dlfQRk8AElRC0RQJE/5DWieIR/7nBWvC93rb6UFA8D1lpW9U9CJ4BMqINuxrWB1DVh8rS2vPVF3a6U+ksUfteiDWhLCjwvKlVqZ0GhePSdnH+Y6hRb7nfq+KxWvYlpdCzkkVY9tUyVSRJgeKohCQD5A+/u624ZM+pcGhqPTYMbjDIMBHT8w3qRdzB4itMgHldS3pKYhdmAkVbCzrzuj7TlyRcnK2ojNx/JyUrSKQqusBJPMMLTU8l7lKwqH9EpbzHlCGVKx4FviND+Qf5fp4CLnifHrTApzo6Hr58nNyxvIDgm2sKtmBjN7yGaU2lxdf2u55+4KG6FAU/9ZDJoqBYQyoEyWMckPNJaoM21dtXkqOKg8loiXsiFW0lyeUVn6XT0xh89bZwqD7SHnl+ABRUW8S1pOVW6HBfzflN7P4Hc63inQbfGIGPifZ9H7R8v842WjwPSMR2xS2KvrDg0V/8O3bmroeFFTxXuJkrgHsYhZEcVL2I2FAA/UsLZQZ6o4bPNVP8QYTG78GLRaXkpvVR0MKF4C0Icnv85kRcgieGQdXEFrrjiBZWif2UbZJvIcpPieZvAGzrqhKOn/zlaI3gSo/4SUrZlslzcQ6ngAWjxqNYikAtXwgU6HDIPV7+K0/ReDyw1ZKWW4V5Hj7dfKAjkEeAIBsPDPz/9m6jnM28hrl6J0QZ1j699S+8FMlDfnYZ3VOVJvm+qc4zMw9VO+QBJi++01Wc2bociUc97prLno+8MBe3iafqdZBWWoQPgh/uaFSRqVf9uKKocxdUXq/DSRfUWShwe89HRay6lHQ4FXfqt6NRTq151KXjDFaO/as1VDEJxW2f+ZNWJ+FFZK/Js8JAyXId2SRxQfjEAcBwqHXnP53xkvaAcKk829IBj93+euBtX8BooGym+9D7o6nnV20MehMXXvTvJO3AhSYtHbZktB9+gJIvyUQ3so7PZmCqRF3jtP2LGrK8U9k8wC2QsjkoqDAIb8TK1U8TL8jzDjYN7F7h4fI6ZB01iHdkGtInWpLhFoc69HfRwRUXuvmkw8I4GV67s3cB/dI+8LHiCzkoWjSxQYIW8yGf94mThBiNPtci29EJ+S6VOKJ1Q/D7pkcj3GqdpfvZhsFqlNVXh7S2fqMV3kxT4mQKVmYT7vRTZnBW8iASIYZaNuQ6x1AU3ECMpRsyZ3avvp878bMCq10OKsxCjMn64l26NqSlemY3jSoyOTT+4PnCbuIsam2ck4QJN3UBAfLSF/Ir5pGgLe/X5J+VcEHNZTwZmMPon0WEV/1puPWBBcE8pKx5pYYGH3ycSC84wEl9nhxbLkp6UR+h6ioGCqq/lHglkTUkoYD+UG/KzeXckq+MgQz5QBh4AUPGUWflQC2e5CFnozweomvOXu7o5jgo1hxs7rAizi/naBQiQiMgn/UdpfxYwBGFxbxOsR2BBvE1qqLxaZpEdO+EzdQLggkWSo7y0RJSPZY0whXVEDp63BsGUPtTRjcQE2qXwewE6jVwy4INfoNwLYPkAJ6OJOlogK+nRbZ+454d8obiUZ8QDHEK5+zDQJCl6qeS/Algk0JEDBvLT+Jb8SXlI1zNQQvL+EaSiJFx63IbvkN7BW+q7S5IB6ggZLoHM1EEvmMQDIkcShxQTv4WSA2WkClxct28zIpKP1QM6KtxadHJ1IBgpQshl+WTjcRKc7q1OQMg6tleneLaSGrlwsM7CuvRICzySWtuQ5ZOCKNoCOsWTknXXkSwBCgY1nmgT9rpy66TRhOkrjWJziteoBF39/UUChSgfGlt9/cUNjSqgU7z9pds4PouQQGHKB2aggLbnAOPM41sdA88+8GA83z07CYxUCRSqfBASPwfYOmkX3Z5rKbR+WsebXu3r7bOEd2BOAiNCAoUrH6RCCvgYHcSFMiX/dxsq5FvGypXTh/MJBbTHJSeBZkigoaWGNIbw3Unx2bekzdi9fMvYfraAntZmV+YkkEUChSw1pBKkTxG2VMrrYseReip79l21P+zVTG2bK3QSaEACzVc+MEf/LbY8ZtR9dNdOvub8TJ8ab6BxrqqTgJOAkID8DzNOIE4CTgJOAk4CTgJOAk4CTgIfNwn8HycIcRv0VYfwAAAAAElFTkSuQmCC"/>
				    				<div class="app">
				    					<div style="font-weight:bold;font-size:14px;">CDN by CDN77</div>
				    					<p>Website speed acceleration with CDN77. 28+ PoPs, Pay-as-you-go prices, no commitments.</p>
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