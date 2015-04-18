var WpfcMaxCDN = jQuery.extend(WpfcCDN, {
	//no need to change init()
	init: function(obj){
		this.conditions = this.set_conditions;
		this.set_params(obj);
		this.open_wizard();
	},
	//called by cdn.js set_buttons_action()
	set_conditions: function(action, current_page_number){
		var self = this;

		if(action == "next"){
			if(current_page_number == 2){
				self.check_url_exist();
			}else{
				return true;
			}
		}
	}
});