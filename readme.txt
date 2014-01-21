=== WP Fastest Cache ===
Contributors: emrevona
Donate link: http://profiles.wordpress.org/emrevona/
Tags: fastest wp cache, cache, caching, performance, wp-cache, plugin, page, optimize, fast, w3 total cache, apache, wp fast cache, google, mod_rewrite, accelerator, google rank, htaccess, quick cache, super cache, w3 total cache, minify, speed, performance, page cache, optimizer, wp fastest cache, facebook, shortcode, gallery, widget, apache, apc, availability, AWS, batcache, buddypress, bwp-minify, cache, caching, cascading style sheet, CDN, Cloud Files, cloudflare, cloudfront, twitter, compress, content delivery network, CSS, css cache, database cache, db-cache, deflate, disk cache, disk caching, eacclerator, elasticache, flash media server, google, google page speed, google rank, gzip, http compression, iis, javascript, Amazon S3, js cache, limelight, litespeed, max cdn, media library, merge, microsoft, minify css, compressor css, mod_cloudflare, image, links, mod_pagespeed, multiple hosts, mysql, posts, plugin, Post, Autoptimize, optimize, optimizer, page cache, performance, plugin, quick cache, images 
Requires at least: 3.3
Tested up to: 3.8
Stable tag: 4.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


== Description ==

This plugin creates static html files from your dynamic WordPress blog.
When a page is rendered, php and mysql are used. Therefor, system needs RAM and CPU. 
If many visitors come to a site, system uses lots of RAM and CPU so page is rendered so slowly. 
In this case, you need a cache system not to render page again and again.
Cache system generates a static html file and saves. Other users reach to static html page.
<br><br>
Setup of this plugin is so easy. You don't need to modify .htacces file. It will modified automatically.

http://www.youtube.com/watch?v=5XzkiLr1FYE

<h4>Features</h4>

1. Mod_Rewrite which is the fastest method is used in this plugin
2. All cache files are deleted when a post or page is published
3. Admin can delete all cached files from the options page
4. Admin can delete minified css and js files from the options page
5. Block cache for specific page or post with Short Code
6. Cache Timeout - All cached files are deleted at the determinated time
7. Enable/Disable cache option for mobile devices

<h4>Performance Optimization</h4>

1. Generating static html files from your dynamic WordPress blog
2. Minify Html - You can decrease the size of page
3. Minify Css - You can decrease the size of css files
4. Enable Gzip Compression - Reduce the size of files sent from your server to increase the speed to which they are transferred to the browser.

<h4>Supported languages: </h4>

* English
* Español (by Diplo)
* Русский
* Türkçe
* Українська

== Installation ==

1. Upload `wp-fastest-cache` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Permission of .htacces must 644
4. Enable this plugin on option page

== Frequently asked questions ==

== Screenshots ==

1. Main Page
2. Delete All File Page
3. Delete Minified Css and Js Files
4. All cached files are deleted at the determinated time
5. Block caching for post and pages (TinyMCE)
6. Block caching for post and pages (Quicktags)
7. You can minify your html
8. You can minify the css files

== Changelog ==

= 0.7.8.5 =
* <strong>[FEATURE]</strong> Enable/Disable cache option for mobile devices has been added
* <strong>[FEATURE]</strong> "[wpfcNOT]" shortcode has been converted to the image
* Optimisation of CSS minify
* r10.net support forum url has been added
* Some style changes
* to correct misspelling
* Icon has been changed
* <strong>[FEATURE]</strong> Portuguese language has been added

= 0.7.8 =
* <strong>[FEATURE]</strong> Delete Minified Css & Js feature has been added
* Update of Spanish translation
* Update of Turkish translation
* Update of Russian translation
* Update of Ukrainian translation

= 0.7.7 =
* Optimisation of CSS minify
* rmdir, mkdir and rename error_log problem
* modify .htaccess problem
* Update of Spanish translation
* Update of Turkish translation
* Update of Russian translation
* Update of Ukrainian translation

= 0.7.6 =
* <strong>[FEATURE]</strong> Gzip Compression

= 0.7.5 =
* Performance of delete all files is improved
* Rewrite rules of WPFC is removed from .htaccess when wpfc is deactivated
* CSS of Warnings has been changed

= 0.7.4 =
* Minify Css problem has been solved
* Info panel has been added
* Update of Spanish translation
* Update of Turkish translation
* Update of Russian translation
* Update of Ukrainian translation

= 0.7.3 =
* Info Tip has been added

= 0.7.2 =
* <strong>[FEATURE]</strong> Minify CSS files

= 0.7.1 =
* Delete Cron Job when the plugin is deactivated
* Delete from DB when the plugin is deactivated

= 0.7 =
* <strong>[FEATURE]</strong> works with Wordfence properly

= 0.6.9 =
* <strong>[FEATURE]</strong> 404 pages are not cached

= 0.6.8 =
* urls which includes words that wp-content, wp-admin, wp-includes are not cached
* The issue about cache timeout has been solved

= 0.6.7 =
* <strong>[FEATURE]</strong> Cache Timeout has been added

= 0.6.6 =
* <strong>[FEATURE]</strong> Spanish language has been added

= 0.6.5 =
* <strong>[FEATURE]</strong> Minify html

= 0.6.4 =
* <strong>[FEATURE]</strong> Supported languages: Russian, Ukrainian and Turkish

= 0.6.3 =
* <strong>[FEATURE]</strong> "Block Cache For Posts and Pages" has been added as a icon for TinyMCE and  Quicktags editor

= 0.6.2 =
* Cache file is not created if the file is exist

= 0.6.1 =
* Cached files are deleted after deactivation of the plugin

= 0.6 =
* Cached file is not updated after comment because of security reasons

= 0.5.9 =
* Checking corruption of html

= 0.5.8 =
* Creation time of file has been added

= 0.5.7 =
* "Not cached version" text has been removed

= 0.5.6 =
* Some style changes

= 0.5.5 =
* System works under sub wp sites

= 0.5.4 =
* Plugin URI has been added

= 0.5.3 =
* Dir path has been removed from not cached version 

= 0.5.2 =
* Some styles changes

= 0.5.1 =
* Some styles changes

= 0.5 =
* <strong>[FEATURE]</strong> Admin can delete all cached files from the options page
* <strong>[FEATURE]</strong> All cache files are deleted when a post or page is published
* <strong>[FEATURE]</strong> Blocking cache with Shortcode

== Frequently Asked Questions ==

= How do I know my blog is being cached? =
If a page is cached, at the bottom of the page there is a text like "&lt;!-- WP Fastest Cache file was created in 0.330816984177 seconds, on 08-01-14 9:01:35 --&gt;".

= What does ".htaccess not found" warning mean? =
Wpfc does not create .htaccess automatically so you need to create empty one.

= Does Wpfc work with WPMU (Wordpress Multisite) properly? =
No. Wpfc does not support Wordpress Multisite yet.

= Is this plugin compatible with Adsense? =
Yes, it is compatible with Adsense %100.

== Upgrade notice ==