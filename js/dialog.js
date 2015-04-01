var Wpfc_Dialog = {
	dialog: function(id){
		jQuery("#" + id).draggable();
		jQuery("#" + id).position({my: "center", at: "center", of: window});

		jQuery(".close-wiz").click(function(){
			jQuery("#" + id).remove();
		});
	}
};