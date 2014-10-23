var WpFcStatics = {
	url: "",
	init: function(url){
		this.url = url;
		this.set_click_event_show_hide_button();

		self = this;
		jQuery("#wpfc-optimized-images").click(function(){
			jQuery("[id^='wpfc-optimized-statics-']").addClass("wpfc-loading-statics");
			jQuery("[id^='wpfc-optimized-statics-']").html("");
			self.optimize_image(self);
		})
	},
	update_image_list: function(){
		jQuery.ajax({
			type: 'POST', 
			url: self.url,
			data : {"action": "wpfc_update_image_list_ajax_request"},
			cache: false, 
			success: function(data){
				jQuery("#the-list").fadeToggle("500", function(){
					jQuery(this).html(data);
					jQuery(this).fadeToggle("500");
				});
			}
		});
	},
	set_click_event_show_hide_button: function(){
		jQuery("#show-image-list, #hide-image-list").click(function(e){
			if(e.target.id == "show-image-list"){
				jQuery(e.target).hide();
				jQuery("#hide-image-list").show();
				jQuery("#wpfc-image-list").show();
				jQuery("#wpfc-image-static-panel").hide();
			}else if(e.target.id == "hide-image-list"){
				jQuery(e.target).hide();
				jQuery("#show-image-list").show();
				jQuery("#wpfc-image-list").hide();
				jQuery("#wpfc-image-static-panel").show();
			}
		});
	},
	optimize_image: function(self){
		jQuery.ajax({
			type: 'POST', 
			url: self.url,
			dataType : "json",
			data : {"action": "wpfc_optimize_image_ajax_request"},
			cache: false, 
			success: function(data){
				if(data.success == "success"){
					if(data.message != "finish"){
						self.update_statics(function(){
							setTimeout(function(){
								self.optimize_image(self);
							}, 1500);
						});
					}else{
						self.update_statics();
					}
				}else{
					self.update_statics();
					if(typeof data.message != "undefined" && data.message){
						alert(data.message);
					}else{
						alert("Unknown Error: 1");
					}
				}
			}
		});
	},
	update_statics: function(callback){
		var self = this;
		
		if(callback){ callback(); }

		jQuery("[id^='wpfc-optimized-statics-']").addClass("wpfc-loading-statics");
		jQuery("[id^='wpfc-optimized-statics-']").html("");

		jQuery.ajax({
			type: 'POST', 
			url: self.url,
			dataType : "json",
			data : {"action": "wpfc_statics_ajax_request"},
			cache: false, 
			success: function(data){
				jQuery.each(data, function(e, i){
					var el = jQuery("#wpfc-optimized-statics-" + e);
					if(el.length === 1){
						if(e == "percent"){
							var percent = i*3.6;

							if(percent > 180){
								jQuery("#wpfc-pie-chart-big-container-first").show();
								jQuery("#wpfc-pie-chart-big-container-second-right").show();
								jQuery('#wpfc-pie-chart-big-container-second-left').animate({  borderSpacing: (percent - 180) }, {
								    step: function(now,fx) {
								      jQuery(this).css('-webkit-transform','rotate('+now+'deg)'); 
								      jQuery(this).css('-moz-transform','rotate('+now+'deg)');
								      jQuery(this).css('transform','rotate('+now+'deg)');
								    },
								    duration:'slow'
								},'linear');

							}else{
								jQuery("#wpfc-pie-chart-big-container-first").hide();
								jQuery("#wpfc-pie-chart-big-container-second-right").hide();

								jQuery('#wpfc-pie-chart-little').animate({  borderSpacing: percent }, {
								    step: function(now,fx) {
								      jQuery(this).css('-webkit-transform','rotate('+now+'deg)'); 
								      jQuery(this).css('-moz-transform','rotate('+now+'deg)');
								      jQuery(this).css('transform','rotate('+now+'deg)');
								    },
								    duration:'slow'
								},'linear');


							}
						}

						el.removeClass("wpfc-loading-statics");
						el.html(i);
					}
				});
			}
		});
	},
	revert_image: function(){
		var self = this;
		jQuery("div.revert").click(function(e){
			jQuery("#revert-loader").show();

			var id = jQuery(e.target).find("input")[0].value;

			jQuery.ajax({
				type: 'POST', 
				url: self.url,
				dataType : "json",
				data : {"action": "wpfc_revert_image_ajax_request", "id" : id},
				cache: false, 
				success: function(data){
					try{
						if(data.success == "true"){
							self.update_statics(function(){
								jQuery("#revert-loader").hide();
								jQuery("tr[post-id='" + id + "']").fadeOut();
							});
						}else if(data.success == "false"){
							jQuery("#revert-loader").hide();
							if(typeof data.message != "undefined" && data.message){
								alert(data.message);
							}else{
								alert("Revert Image: " + 'data.success == "false"');
							}
						}

					}catch(err){
						alert("Revert Image: " + err.message);
					}
				}
			});

		});
	}
};