var Wpfc_Dialog = {
	id : "",
	buttons: [],
	dialog: function(id, buttons){
		var self = this;
		self.id = id;
		self.buttons = buttons;

		jQuery("#" + id).draggable();
		jQuery("#" + id).position({my: "center", at: "center", of: window});

		jQuery(".close-wiz").click(function(){
			self.remove();
		});

		self.show_buttons();
	},
	remove: function(){
		var self = this;
		jQuery("#" + self.id).remove();
	},
	show_buttons: function(){
		var self = this;
		if(typeof self.buttons != "undefined"){
			jQuery.each(self.buttons, function( index, value ) {
				jQuery("button[action='" + index + "']").show();
				jQuery("button[action='" + index + "']").click(function(){
					value();
				});
			});
		}
	}
};