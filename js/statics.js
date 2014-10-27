var WpFcStatics = {
	url: "",
	current_page: 0,
	total_page: 0,
	init: function(url){
		this.url = url;
		this.set_click_event_show_hide_button();
		this.set_click_event_optimize_image_button();
		this.set_click_event_search_button();
		this.set_click_event_paging();
		this.set_click_event_clear_search_text();
	},
	set_click_event_clear_search_text: function(){
		var self = this;

		jQuery("span.deleteicon span").click(function(e){
			jQuery("#wpfc-image-search-input").val("");
			jQuery(e.target).addClass("cleared");
			self.update_image_list(0);
		});

		jQuery("#wpfc-image-search-input").keyup(function(e){
			if(jQuery(e.target).val().length > 0){
				jQuery("span.deleteicon span").removeClass("cleared");
			}else{
				jQuery("span.deleteicon span").addClass("cleared");

				if(e.keyCode == 8){
					self.update_image_list(0);
				}
			}

			if(e.keyCode == 13){
				self.update_image_list(0);
			}
		});
	},
	set_click_event_paging: function(){
		var self = this;
		jQuery(".wpfc-image-list-next-page, .wpfc-image-list-prev-page, .wpfc-image-list-first-page, .wpfc-image-list-last-page").click(function(e){
			if(jQuery(e.target).hasClass("wpfc-image-list-next-page")){
				self.update_image_list(self.current_page + 1);
			}else if(jQuery(e.target).hasClass("wpfc-image-list-prev-page")){
				self.update_image_list(self.current_page - 1);
			}else if(jQuery(e.target).hasClass("wpfc-image-list-first-page")){
				self.update_image_list(0);
			}else if(jQuery(e.target).hasClass("wpfc-image-list-last-page")){
				self.update_image_list(self.total_page - 1);
			}
		});
	},
	set_click_event_search_button: function(){
		var self = this;
		jQuery("#wpfc-image-search-button").click(function(){
			self.update_image_list(0);
		});
	},
	set_click_event_optimize_image_button: function(){
		var self = this;
		jQuery("#wpfc-optimize-images-button").click(function(){
			jQuery("[id^='wpfc-optimized-statics-']").addClass("wpfc-loading-statics");
			jQuery("[id^='wpfc-optimized-statics-']").html("");
			self.optimize_image(self);
		});
	},
	update_image_list: function(page, search){
		var self = this;

		if(page > -1 && page < self.total_page){
		}
			jQuery("#revert-loader").show();

			var search = jQuery("#wpfc-image-search-input").val();

			jQuery.ajax({
				type: 'POST',
				url: self.url,
				data : {"action": "wpfc_update_image_list_ajax_request", "page": page, "search" : search},
				dataType : "json",
				cache: false, 
				success: function(data){
					if(typeof data != "undefined" && data){
						self.total_page = Math.ceil(data.optimized_exist/data.per_page);
						self.total_page = self.total_page > 0 ? self.total_page : 1;

						self.current_page = page;

						jQuery(".wpfc-current-page").text(self.current_page + 1);
						jQuery("#the-list").html(data.content);
						jQuery(".wpfc-total-pages").html(self.total_page);
						jQuery("#revert-loader").hide();

						jQuery(".wpfc-image-list-prev-page").removeClass("disabled");
						jQuery(".wpfc-image-list-next-page").removeClass("disabled");
						jQuery(".wpfc-image-list-first-page").removeClass("disabled");
						jQuery(".wpfc-image-list-last-page").removeClass("disabled");

						if((self.current_page + 1) == self.total_page){
							jQuery(".wpfc-image-list-next-page").addClass("disabled");
							jQuery(".wpfc-image-list-last-page").addClass("disabled");
						}

						if(self.current_page === 0){
							jQuery(".wpfc-image-list-prev-page").addClass("disabled");
							jQuery(".wpfc-image-list-first-page").addClass("disabled");
						}

						self.revert_image();

					}else{
						alert("Error: Image List Problem. Please refresh...");
					}
				}
			});

	},
	set_click_event_show_hide_button: function(){
		var self = this;
		jQuery("#show-image-list, #hide-image-list").click(function(e){
			if(e.target.id == "show-image-list"){
				jQuery(e.target).hide();
				jQuery("#hide-image-list").show();
				jQuery("#wpfc-image-list").show();
				jQuery("#wpfc-image-static-panel").hide();
				self.update_image_list(0);
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