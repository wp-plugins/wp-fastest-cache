var WpfcMaxCDN = jQuery.extend(WpfcCDN, {
	init: function(obj){
		this.conditions = this.set_conditions;
		this.set_id(obj);
		this.set_template_url(obj);
		this.open_wizard();
	},
	set_conditions: function(action, current_page_number){
		console.log(action, current_page_number);
		if(action == "next" && current_page_number == 1){
			var api_key = jQuery("#wpfc-apikey-maxcdn").val();
			if(api_key){
				jQuery.ajax({
					type: 'GET',
					url: ajaxurl,
					data : {"action": "wpfc_maxcdn_validate"},
					dataType : "json",
					cache: false, 
					success: function(data){
						console.log(data);
					}
				});
			}else{
				alert("empty api key");
				return false;
			}

		}
	}
});