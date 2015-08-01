<?php
	class WpFastestCacheAdminToolbar{
		public function __construct(){}

		public function add(){
			add_action( 'wp_before_admin_bar_render', array($this, "wpfc_tweaked_admin_bar") );
		}

		public function wpfc_tweaked_admin_bar() {
			global $wp_admin_bar;

			$wp_admin_bar->add_node(array(
				'id'    => 'wpfc-toolbar-parent',
				'title' => 'WPFC'
			));

			$wp_admin_bar->add_menu( array(
				'id'    => 'wpfc-toolbar-parent-delete-cache',
				'title' => 'Delete Cache',
				'parent'=> 'wpfc-toolbar-parent',
				'meta' => array("class" => "wpfc-toolbar-child")
			));

			$wp_admin_bar->add_menu( array(
				'id'    => 'wpfc-toolbar-parent-delete-cache-and-minified',
				'title' => 'Delete Cache and Minified CSS/JS',
				'parent'=> 'wpfc-toolbar-parent',
				'meta' => array("class" => "wpfc-toolbar-child")
			));

			?>
			<script type="text/javascript">

			</script>
			<?php

		}
	}
?>