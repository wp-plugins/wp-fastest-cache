<?php
/*
Plugin Name: WP Fastest Cache
Plugin URI: http://wordpress.org/plugins/wp-fastest-cache/
Description: The simplest and fastest WP Cache system
Version: 0.8.3.1
Author: Emre Vona
Author URI: http://tr.linkedin.com/in/emrevona

Copyright (C)2013 Emre Vona

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
*/ 

	class WpFastestCache{
		private $systemMessage = "";
		private $options = array();

		public function __construct(){
			$optimize_image_ajax_requests = array("wpfc_revert_image_ajax_request", 
												  "wpfc_statics_ajax_request",
												  "wpfc_optimize_image_ajax_request",
												  "wpfc_update_image_list_ajax_request"
												  );

			if(isset($_GET) && isset($_GET["action"]) && in_array($_GET["action"], $optimize_image_ajax_requests)){
				if(file_exists($this->getProLibraryPath("image.php"))){
					include_once $this->getProLibraryPath("image.php");
					$img = new WpFastestCacheImageOptimisation();
					$img->hook();
				}
			}else{
				$this->setCustomInterval();

				$this->options = $this->getOptions();

				add_action('transition_post_status',  array($this, 'on_all_status_transitions'), 10, 3 );

				$this->commentHooks();

				$this->checkCronTime();

				register_deactivation_hook( __FILE__, array('WpFastestCache', 'deactivate') );

				if(file_exists($this->getProLibraryPath("mobile-cache.php"))){
					include_once $this->getProLibraryPath("mobile-cache.php");
				}

				if(file_exists($this->getProLibraryPath("powerful-html.php"))){
					include_once $this->getProLibraryPath("powerful-html.php");
				}

				if(is_admin()){
					//for wp-panel
					$this->setRegularCron();
					
					if(file_exists($this->getProLibraryPath("image.php"))){
						include_once $this->getProLibraryPath("image.php");
						//$this->setImageOptimisationCron();
					}

					if(file_exists($this->getProLibraryPath("logs.php"))){
						include_once $this->getProLibraryPath("logs.php");
					}

					$this->admin();
				}else{
					//for cache
					$this->cache();
				}
			}
		}

		private function admin(){
			include_once('inc/admin.php');
			$wpfc = new WpFastestCacheAdmin();
			$wpfc->addMenuPage();
		}
		private function cache(){
			include_once('inc/cache.php');
			$wpfc = new WpFastestCacheCreateCache();
			$wpfc->createCache();
		}

		public function deactivate(){
			$wpfc = new WpFastestCache();
			$path = ABSPATH;
			
			if($wpfc->is_subdirectory_install()){
				$path = $wpfc->getABSPATH();
			}

			if(is_file($path.".htaccess") && is_writable($path.".htaccess")){
				$htaccess = file_get_contents($path.".htaccess");
				$htaccess = preg_replace("/#\s?BEGIN\s?WpFastestCache.*?#\s?END\s?WpFastestCache/s", "", $htaccess);
				$htaccess = preg_replace("/#\s?BEGIN\s?GzipWpFastestCache.*?#\s?END\s?GzipWpFastestCache/s", "", $htaccess);
				$htaccess = preg_replace("/#\s?BEGIN\s?LBCWpFastestCache.*?#\s?END\s?LBCWpFastestCache/s", "", $htaccess);
				file_put_contents($path.".htaccess", $htaccess);
			}

			wp_clear_scheduled_hook("wp_fastest_cache");
			wp_clear_scheduled_hook($wpfc->slug()."_regular");
			wp_clear_scheduled_hook($wpfc->slug()."_imageOptimisation");

			delete_option("WpFastestCache");
			delete_option("WpFcDeleteCacheLogs");
			$wpfc->deleteCache();
		}

		protected function slug(){
			return "wp_fastest_cache";
		}

		protected function getWpContentDir(){
			return ABSPATH."wp-content";
		}

		protected function getOptions(){
			if($data = get_option("WpFastestCache")){
				return json_decode($data);
			}
		}

		protected function getSystemMessage(){
			return $this->systemMessage;
		}

		// protected function detectNewPost(){
		// 	if(isset($this->options->wpFastestCacheNewPost) && isset($this->options->wpFastestCacheStatus)){
		// 		add_filter ('save_post', array($this, 'deleteCache'));
		// 	}
		// }

		public function on_all_status_transitions($new_status, $old_status, $post) {
			if(isset($this->options->wpFastestCacheNewPost) && isset($this->options->wpFastestCacheStatus)){
				if ( ! wp_is_post_revision($post->ID) ){
					if($new_status == "publish" && $old_status != "publish"){
						$this->deleteCache();
					}else if($new_status == "publish" && $old_status == "publish"){
						$this->singleDeleteCache(false, $post->ID);
					}else if($new_status == "trash" && $old_status == "publish"){
						$this->deleteCache();
					}else if(($new_status == "draft" || $new_status == "pending") && $old_status == "publish"){
						$this->deleteCache();
					}

				}
			}
		}

		protected function commentHooks(){
			//it works when the status of a comment changes
			add_filter ('wp_set_comment_status', array($this, 'singleDeleteCache'));

			//it works when a comment is saved in the database
			add_filter ('comment_post', array($this, 'detectNewComment'));
		}

		public function detectNewComment($comment_id){
			if(current_user_can( 'manage_options') || !get_option('comment_moderation')){
				$this->singleDeleteCache($comment_id);
			}
		}

		public function singleDeleteCache($comment_id = false, $post_id = false){
			if($comment_id){
				$comment = get_comment($comment_id);
			}

			$post_id = $post_id ? $post_id : $comment->comment_post_ID;

			$permalink = get_permalink($post_id);

			if(preg_match("/http:\/\/[^\/]+\/(.+)/", $permalink, $out)){
				$path = $this->getWpContentDir()."/cache/all/".$out[1];
				if(is_dir($path)){
					
					if(file_exists($this->getProLibraryPath("logs.php"))){
						include_once $this->getProLibraryPath("logs.php");
						$log = new WpFastestCacheLogs("delete");
						$log->action();
					}

					$this->rm_folder_recursively($path);
				}
			}
		}

		public function deleteCache($minified = false){
			if(class_exists("WpFcMobileCache")){
				$wpfc_mobile = new WpFcMobileCache();
				$wpfc_mobile->delete_cache($this->getWpContentDir());

				wp_schedule_single_event(time() + 60, $this->slug()."_TmpDelete_".time());
			}

			$cache_path = $this->getWpContentDir()."/cache/all";

			if(is_dir($cache_path)){
				if(is_dir($this->getWpContentDir()."/cache/tmpWpfc")){
					rename($cache_path, $this->getWpContentDir()."/cache/tmpWpfc/".time());
					wp_schedule_single_event(time() + 60, $this->slug()."_TmpDelete_".time());
					$this->systemMessage = array("All cache files have been deleted","success");
					
					if(file_exists($this->getProLibraryPath("logs.php"))){
						include_once $this->getProLibraryPath("logs.php");
						$log = new WpFastestCacheLogs("delete");
						$log->action();
					}

				}else if(@mkdir($this->getWpContentDir()."/cache/tmpWpfc", 0755, true)){
					rename($cache_path, $this->getWpContentDir()."/cache/tmpWpfc/".time());
					wp_schedule_single_event(time() + 60, $this->slug()."_TmpDelete_".time());
					$this->systemMessage = array("All cache files have been deleted","success");
					
					if(file_exists($this->getProLibraryPath("logs.php"))){
						include_once $this->getProLibraryPath("logs.php");
						$log = new WpFastestCacheLogs("delete");
						$log->action();
					}

				}else{
					$this->systemMessage = array("Permission of <strong>/wp-content/cache</strong> must be <strong>755</strong>", "error");
				}
			}else{
				if($minified){
					$this->systemMessage = array("Minified CSS and JS files have been deleted","success");
					
					if(file_exists($this->getProLibraryPath("logs.php"))){
						include_once $this->getProLibraryPath("logs.php");
						$log = new WpFastestCacheLogs("delete");
						$log->action();
					}
					
				}else{
					$this->systemMessage = array("Already deleted","success");
				}
			}
		}

		public function checkCronTime(){
			add_action($this->slug(),  array($this, 'setSchedule'));
			add_action($this->slug()."_TmpDelete",  array($this, 'actionDelete'));
			add_action($this->slug()."_regular",  array($this, 'regularCrons'));

			add_action($this->slug()."_imageOptimisation", array($this, 'imageOptimize'));
		}

		public function actionDelete(){
			if(is_dir($this->getWpContentDir()."/cache/tmpWpfc")){
				$this->rm_folder_recursively($this->getWpContentDir()."/cache/tmpWpfc");
				if(is_dir($this->getWpContentDir()."/cache/tmpWpfc")){
					wp_schedule_single_event(time() + 60, $this->slug()."_TmpDelete");
				}
			}
		}

		public function imageOptimize(){
			if(class_exists("WpFastestCacheImageOptimisation")){
				echo "rrr";
				$image = new WpFastestCacheImageOptimisation();
				$image->optimizeLastImage();
			}
		}

		public function regularCrons(){
			$this->actionDelete();
		}

		public function setSchedule(){
			$this->deleteCache();
		}

		public function setRegularCron(){
			if(!wp_next_scheduled($this->slug()."_regular")){
				wp_schedule_event( time() + 360, 'everyfifteenminute', $this->slug()."_regular");
			}
		}

		public function setImageOptimisationCron(){
			if(!wp_next_scheduled($this->slug()."_imageOptimisation")){
				wp_schedule_event( time() + 60, 'everyfifteenminute', $this->slug()."_imageOptimisation");
			}
		}

		public function getABSPATH(){
			$path = ABSPATH;
			$siteUrl = site_url();
			$homeUrl = home_url();
			$diff = str_replace($homeUrl, "", $siteUrl);
			$diff = trim($diff,"/");

		    $pos = strrpos($path, $diff);

		    if($pos !== false){
		    	$path = substr_replace($path, "", $pos, strlen($diff));
		    	$path = trim($path,"/");
		    	$path = "/".$path."/";
		    }
		    return $path;
		}

		protected function rm_folder_recursively($dir, $i = 1) {
			$files = @scandir($dir);
		    foreach((array)$files as $file) {
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

		protected function is_subdirectory_install(){
			if(strlen(site_url()) > strlen(home_url())){
				return true;
			}
			return false;
		}

		protected function getMobileUserAgents(){
			return "iphone|sony|symbos|nokia|samsung|mobile|epoc|ericsson|panasonic|philips|sanyo|sharp|sie-|portalmmm|blazer|avantgo|danger|palm|series60|palmsource|pocketpc|android|blackberry|playbook|ipad|ipod|iemobile|palmos|webos|googlebot-mobile|bb10|xoom|p160u|nexus";
		}

		public function getProLibraryPath($file){
			$currentPath = plugin_dir_path( __FILE__ );
			$pluginMainPath = str_replace("inc/", "", $currentPath);

			return $pluginMainPath."pro/".$file;
		}

		public function cron_add_minute( $schedules ) {
		   	$schedules['everyfifteenminute'] = array(
			    'interval' => 60*15,
			    'display' => __( 'Once Every 15 Minutes' )
		    );

		    $schedules['twiceanhour'] = array(
			    'interval' => 60*30,
			    'display' => __( 'Twice an Hour' )
		    );

		    $schedules['everysixhours'] = array(
			    'interval' => 60*60*6,
			    'display' => __( 'Once Every 6 Hours' )
		    );

		    $schedules['weekly'] = array(
			    'interval' => 60*60*24*7,
			    'display' => __( 'Once a Week' )
		    );

		    $schedules['montly'] = array(
			    'interval' => 60*60*24*30,
			    'display' => __( 'Once a Month' )
		    );

		    return $schedules;
		}

		public function setCustomInterval(){
			add_filter( 'cron_schedules', array($this, 'cron_add_minute'));
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

	$wpfc = new WpFastestCache();



?>