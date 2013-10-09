jQuery.fn.extend({
    wpfclang: function(language){
    	var dictionary = {
    		"You can decrease the size of page" : {"tr" : "Sayfanın boyutunu küçültebilirsiniz"},
    		"WP Fastest Cache Options": {"tr" : "WP Fastest Cache Seçenekleri",
    									 "ru" : "WP Fastest Cache Опции",
    									 "ukr": "WP Fastest Cache Опції"},
    		"Options have been saved" : {"tr" : "Tercihler kaydedildi",
    									 "ru" : "Опции были сохранены",
    									 "ukr": "Опції були збережені"},
    		".htacces is not writable": {"tr" : ".htacces dosyasının yazma izni yok",
    									 "ru" : ".htacces не может быть записан",
    									 "ukr": ".htacces не може бути записаним"},
    		"All cache files have been deleted" : {"tr" : "Önbellekteki tüm dosyalar silindi",
    											   "ru" : "Все файлы кеша были удалены",
    											   "ukr": "Всі файли кеша були видалені"},
			"Language" 				  : {"tr" : "Dil",
										 "ru" : "Язык",
										 "ukr" : "Мова"},
			"Settings"				  : {"tr" : "Ayarlar", 
										 "ru" : "Настройки",
										 "ukr": "Налаштування"},
			"Delete Cache"            : {"tr" : "Önbellek Temizle", 
										 "ru" : "Удалить Кэш",
										 "ukr": "Видалити Кеш"}, 
			"Cache Timeout"      	  : {"tr" : "Zaman Aşımı", 
										 "ru" : "Кэш Тайм-аута",
										 "ukr": "Кеш Тайм-ауту"}, 
			"Cache System"            : {"tr" : "Cache Sistemi",
										 "ru" : "Кэш Системы",
										 "ukr": "Кеш Системи"}, 
			"Enable"                  : {"tr" : "Açık",
										 "ru" : "Включить",
										 "ukr": "Включити"}, 
			"New Post"                : {"tr" : "Yeni Yazı",
										 "ru" : "Новый Пост",
										 "ukr": "Новий Пост"}, 
			"Clear all cache files when a post or page is published" : {"tr" : "Yeni bir yazı veya sayfa eklenirse tüm önbelleği temizle",
																		"ru" : "Удалить все файлы кэша, когда пост или страница опубликованы",
																		"ukr": "Видалити всі файли кешу, коли пост або сторінка опубліковани"},
			"Submit"  				  : {"tr" : "Gönder",
										 "ru" : "Отправить",
										 "ukr": "Відправити"},
			"Delete Now" 			  : {"tr" : "Temizle Şimdi",
										 "ru" : "Удалить Сейчас",
										 "ukr": "Видалити Зараз"},
			"You can delete all cache files" : {"tr" : "Tüm önbelleği temizleyebilirsiniz",
												 "ru" : "Вы можете удалить все кэшированные файлы",
												 "ukr": "ви можете видалили всі кешированні файли"},
			"Target folder"           : {"tr" : "Hedef klasör",
										 "ru" : "Целевая папка",
										 "ukr": "Цільова папка"},
			"It will active in the next version" : {"tr" : "Bir sonraki versiyonda aktif olacaktır",
													"ru" : "Эта функция будет активной в следующей версии",
													"ukr" :"Ця функція стане активною в наступній версії"}
		};
		var el = jQuery(this);
    	var text = el.attr("type") == "submit" ? el.val().trim() : el.text().trim();
    	var converted = typeof dictionary[text] == "undefined" ? dictionary[text] : dictionary[text][language];

    	if(typeof converted != "undefined"){
	    	if(el.attr("type") == "submit"){
	    		el.val(converted);
	    	}else{
	    		el.text(converted);
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
		var self = this;
		jQuery('#wpbody-content label, div.question, .questionCon input[type="submit"], #message p, .wrap h2, #nextVerAct').each(function(){
			jQuery(this).wpfclang(self.language);
		});
	}
}