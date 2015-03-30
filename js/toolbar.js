jQuery( document ).ready(function() {
	jQuery("body").append('<div id="revert-loader-toolbar"></div>');

	jQuery("#wp-admin-bar-wpfc-toolbar-parent-default li").click(function(e){
		var id = (typeof e.target.id != "undefined" && e.target.id) ? e.target.id : jQuery(e.target).parent("li").attr("id");
		var action = "";
		
		if(id == "wp-admin-bar-wpfc-toolbar-parent-delete-cache"){
			action = "wpfc_delete_cache";
		}else if(id == "wp-admin-bar-wpfc-toolbar-parent-delete-cache-and-minified"){
			action = "wpfc_delete_cache_and_minified";
		}
		jQuery("#revert-loader-toolbar").show();
		jQuery.ajax({
			type: 'GET',
			url: ajaxurl,
			data : {"action": action},
			dataType : "json",
			cache: false, 
			success: function(data){
				if(typeof WpFcCacheStatics != "undefined"){
					WpFcCacheStatics.update();
				}else{
					jQuery("#revert-loader-toolbar").hide();
				}
			}
		});
	});
});