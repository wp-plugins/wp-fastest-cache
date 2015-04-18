var Wpfc_Dialog = {
	id : "",
	dialog: function(id){
		var self = this;
		self.id = id;

		jQuery("#" + id).draggable();
		jQuery("#" + id).position({my: "center", at: "center", of: window});

		jQuery(".close-wiz").click(function(){
			self.remove();
		});
	},
	remove: function(){
		var self = this;
		jQuery("#" + self.id).remove();
	}
};