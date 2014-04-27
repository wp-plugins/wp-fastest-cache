window.wpfc = {};
jQuery.fn.extend({
    wpfclang: function(){
    	var dictionary = window.wpfc.dictionary || {};
		var el = jQuery(this);
    	var text = el.attr("type") == "submit" ? el.val().trim() : el.text().trim();
    	var converted = typeof dictionary[text] == "undefined" ? text : dictionary[text];

    	if(typeof converted != "undefined" && converted){
	    	if(el.attr("type") == "submit"){
	    		el.val(converted);
	    	}else{
	    		el.html(converted);
	    	}
    	}
    }
});
var Wpfclang = {
	language : "",
	init: function(language){
		this.language = language;
		this.translate();
		this.setLanguageInputField();
	},
	setLanguageInputField: function(){
		var self = this;
		jQuery("#wpFastestCacheLanguage").val(self.language);
	},
	translate: function(){
		if(typeof window.wpfc != "undefined" && typeof window.wpfc.dictionary != "undefined"){
			var self = this;
			jQuery('#wpbody-content label, div.question, .questionCon input[type="submit"], #message p, .wrap h2, #nextVerAct, select option, th, #rule-help-tip h4, #rule-help-tip label, .omni_admin_sidebar h3').each(function(){
				console.log(this);
				jQuery(this).wpfclang();
			});
		}
	}
};