<?php
/*
Plugin Name: WP Fastest Cache
Plugin URI: http://wordpress.org/plugins/wp-fastest-cache/
Description: The simplest and fastest WP Cache system
Version: 0.5.8
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

	include_once('inc/common.php');
	$wpfc = new WpFastestCache();
	if(is_admin()){
		//for wp-panel
		$wpfc->addMenuPage();
	}else{
		//for cache
		$wpfc->createCache();
	}

?>