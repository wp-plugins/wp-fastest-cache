var WpfcCDN = {
	values: {"name" : "", "cdnurl" : "", "originurl" : "", "file_types" : ""},
	id : "",
	template_url : "",
	content : "",
	init: function(obj){
		this.set_params(obj);
		this.open_wizard();
	},
	check_conditions: function(action, current_page_number){
		var self = this;

		if(action == "next"){
			if(current_page_number == 2){
				self.check_url_exist();
			}else{
				return true;
			}
		}
	},
	set_params: function(obj){
		this.id = obj.id;
		this.template_url = obj.template_main_url + "/" + this.id + ".php";
		if(obj.values){
			this.values = obj.values;
		}
	},
	open_wizard: function(){
		var self = this;
		if(jQuery("#wpfc-modal-" + self.id).length == 0){
			self.load_template(function(){
				self.fill_integration_fields();
				self.set_buttons_action();
			});
		}
	},
	fill_integration_fields: function(){
		var self = this;
		jQuery("#wpfc-wizard-" + self.values.id).find("input#cdn-url").val(self.values.cdnurl);
		jQuery("#wpfc-wizard-" + self.values.id).find("#origin-url").val(self.values.originurl);

		if(self.values.file_types){
			jQuery("#wpfc-wizard-" + self.values.id).find(".wpfc-checkbox-list input[type='checkbox']").attr("checked", false);
			jQuery.each(self.values.file_types.split(","), function( index, value ) {
				jQuery("#file-type-" + value).attr("checked", true);
			});
		}
	},
	set_buttons_action: function(){
		var self = this;
		var action = "";
		var current_page, next_page, current_page_number;

		self.buttons();

		jQuery("button.wpfc-dialog-buttons").click(function(e){
			action = jQuery(e.currentTarget).attr("action");
			current_page_number = jQuery(".wpfc-cdn-pages-container div.wiz-cont:visible").attr("wpfc-cdn-page");

			if(action == "next"){
				if(self.check_conditions("next", current_page_number)){
					self.show_page("next");
				}
			}else if(action == "back"){
				self.show_page("back");
			}else if(action == "finish"){
				self.save_integration();
			}else if(action == "close"){
				Wpfc_Dialog.remove();
			}else if(action == "remove"){
				self.remove_integration();
			}
		});
	},
	remove_integration: function(){
		var self = this;

		jQuery(".wpfc-dialog-buttons[action='remove']").attr("disabled", true);

		jQuery.ajax({
			type: 'POST',
			dataType: "json",
			url: self.ajax_url,
			data : {"action": "wpfc_remove_cdn_integration_ajax_request"},
		    success: function(res){
		    	self.values = {};
		    	jQuery(".wpfc-dialog-buttons[action='remove']").attr("disabled", false);
		    	jQuery("div[wpfc-cdn-name='" + self.id + "']").find(".connected").text("");
		    	Wpfc_Dialog.remove();
		    	console.log(res);
		    },
		    error: function(e) {
		    	jQuery(".wpfc-dialog-buttons[action='remove']").attr("disabled", false);
		    	alert("unknown error");
		    }
		  });
	},
	save_integration: function(){
		jQuery(".wpfc-dialog-buttons[action='finish']").attr("disabled", true);
		
		var self = this;
		self.buttons();
		self.values.id = self.id;
		self.values.cdnurl = jQuery("input#cdn-url").val();
		self.values.originurl = jQuery("input#origin-url").val();
		self.values.file_types = jQuery(".wpfc-checkbox-list input[type='checkbox']:checked").map(function(){return this.value;}).get().join(",");
		
		
		jQuery.ajax({
			type: 'POST',
			dataType: "json",
			url: self.ajax_url,
			data : {"action": "wpfc_save_cdn_integration_ajax_request", "values" : self.values, "file_types" : self.values.file_types},
		    success: function(res){
				jQuery("div[wpfc-cdn-name='" + self.id + "']").find(".connected").text("Connected");
				jQuery(".wpfc-dialog-buttons[action='finish']").attr("disabled", false);
				self.show_page("next");
		    	console.log(res);
		    },
		    error: function(e) {
		    	jQuery(".wpfc-dialog-buttons[action='finish']").attr("disabled", false);
		    	alert("unknown error");
		    }
		  });



	},
	check_url_exist: function(){
		var self = this;
		var cdn_url = jQuery("#cdn-url").val();
		jQuery("#cdn-url-loading").show();
		jQuery(".wpfc-cdn-pages-container div.wiz-cont:visible #cdn-url").nextAll("label").html("");
		jQuery.ajax({
			type: 'GET',
			dataType: "json",
			url: ajaxurl,
			data : {"action": "wpfc_check_url_ajax_request", "url" : cdn_url},
		    success: function(res){
		    	jQuery("#cdn-url-loading").hide();
		    	if(res.success){
		    		self.show_page("next");
		    	}else{
		    		jQuery(".wpfc-cdn-pages-container div.wiz-cont:visible #cdn-url").nextAll("label").html(res.error_message);
		    	}
		    },
		    error: function(e) {
		    	jQuery("#cdn-url-loading").hide();
		    	alert("unknown error");
		    }
		  });
	},
	show_page: function(type){
		var current_page = jQuery(".wpfc-cdn-pages-container div.wiz-cont:visible");
		current_page.hide();

		if(type == "next"){
			current_page.next().show();
		}else if(type == "back"){
			current_page.prev().show();
		}
		this.buttons();
	},
	buttons: function(){
		var self = this;
		var current_page, next_pages;

		current_page = jQuery(".wpfc-cdn-pages-container div.wiz-cont:visible");
		next_pages = current_page.nextAll(".wiz-cont");

		jQuery(".wpfc-dialog-buttons[action]").hide();

		if(self.values.id == self.id){
			self.show_button("remove");
		}

		if(next_pages.length){
			if(next_pages.length > 1){
				self.show_button("next");
			}else if(next_pages.length == 1){
				self.show_button("finish");
			}

			if(jQuery(".wpfc-cdn-pages-container div.wiz-cont:visible").attr("wpfc-cdn-page") > 1){
				self.show_button("back");
			}
		}else{
			self.show_button("close");
		}

	},
	show_button: function(type){
		jQuery(".wpfc-dialog-buttons[action='" + type + "']").show();
	},
	hide_button: function(type){
		jQuery(".wpfc-dialog-buttons[action='" + type + "']").hide();
	},
	load_template: function(callbak){
		var self = this;
		jQuery("#revert-loader-toolbar").show();
		jQuery.get(self.template_url, function( data ) {
			jQuery("#revert-loader-toolbar").hide();
			jQuery("body").append(data);
			Wpfc_Dialog.dialog("wpfc-modal-" + self.id);
			callbak();
		});
	}
};