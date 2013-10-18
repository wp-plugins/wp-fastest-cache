jQuery.fn.extend({
    wpfclang: function(language){
    	var dictionary = {
    		"All cached files are deleted at the determinated time." : {"tr" : "Belirlenen zamanda önbellekteki tüm dosyalar silinecektir.",
    																										 "ru" : "",
    																										 "ukr": "",
    																										 "es" : "Todos los archivos en el cache serán eliminados a la hora y frecuencia seleccionadas:"},
    		"Choose One" : {"tr" : "Seçin",
    						"ru" : "",
    						"ukr": "",
    						"es" : "Seleccione"},
    		"Once an hour": {"tr" : "Saatte bir kez",
    						 "ru" : "",
    						 "ukr": "",
    						 "es" : "Cada hora"},
    		"Once a day": {"tr" : "Günde bir kez",
    						 "ru" : "",
    						 "ukr": "",
    						 "es" : "Una vez al día"},
    		"Twice a day": {"tr" : "Günde iki kez",
    						 "ru" : "",
    						 "ukr": "",
    						 "es" : "Dos veces al día"},
    		"Next due": {"tr" : "Sonraki Görev",
						 "ru" : "",
						 "ukr": "",
						 "es" : "Próximo"},
    		"Schedule": {"tr" : "Vardiya",
						 "ru" : "",
						 "ukr": "",
						 "es" : "Frecuencia"},
			"Server time": {"tr" : "Server tarihi",
						 "ru" : "",
						 "ukr": "",
						 "es" : "Hora del servidor"},
    		"You can decrease the size of page" : {"tr" : "Sayfanın boyutunu küçültebilirsiniz",
    											   "ru" : "Вы можете уменьшить размер страницы",
    											   "ukr": "Ви не можете зменшити розмір сторінки",
    											   "es" : "Puede disminuir el tamaño de la página"},
    		"WP Fastest Cache Options": {"tr" : "WP Fastest Cache Seçenekleri",
    									 "ru" : "WP Fastest Cache Опции",
    									 "ukr": "WP Fastest Cache Опції",
    									 "es" : "Opciones de WP Fastest Cache"},									 
    		"Options have been saved" : {"tr" : "Tercihler kaydedildi",
    									 "ru" : "Опции были сохранены",
    									 "ukr": "Опції були збережені",
    									 "es" : "Configuración guardada"},										 
    		".htacces is not writable": {"tr" : ".htacces dosyasının yazma izni yok",
    									 "ru" : ".htacces не может быть записан",
    									 "ukr": ".htacces не може бути записаним",
    									 "es" : "No se puede modificar .htacces"},								 
    		"All cache files have been deleted" : {"tr" : "Önbellekteki tüm dosyalar silindi",
    											   "ru" : "Все файлы кеша были удалены",
    											   "ukr": "Всі файли кеша були видалені",
    											   "es" : "Eliminados todos los archivos en el cache"},						   
			"Language" 				  : {"tr" : "Dil",
										 "ru" : "Язык",
										 "ukr": "Мова",
										 "es" : "Idioma"},
			"Settings"				  : {"tr" : "Ayarlar", 
										 "ru" : "Настройки",
										 "ukr": "Налаштування",
										 "es" : "Configuración"},
			"Delete Cache"            : {"tr" : "Önbellek Temizle", 
										 "ru" : "Удалить Кэш",
										 "ukr": "Видалити Кеш",
										 "es" : "Limpiar cache"}, 
			"Cache Timeout"      	  : {"tr" : "Zaman Aşımı", 
										 "ru" : "Кэш Тайм-аута",
										 "ukr": "Кеш Тайм-ауту", 
										 "es" : "Tiempo de espera del cache"}, 
			"Cache System"            : {"tr" : "Cache Sistemi",
										 "ru" : "Кэш Системы",
										 "ukr": "Кеш Системи",
										 "es" : "Sistema de cache"}, 
			"Enable"                  : {"tr" : "Açık",
										 "ru" : "Включить",
										 "ukr": "Включити",
										 "es" : "Activar"}, 
			"New Post"                : {"tr" : "Yeni Yazı",
										 "ru" : "Новый Пост",
										 "ukr": "Новий Пост",
										 "es" : "Nueva entrada"}, 
			"Clear all cache files when a post or page is published" : {"tr" : "Yeni bir yazı veya sayfa eklenirse tüm önbelleği temizle",
																		"ru" : "Удалить все файлы кэша, когда пост или страница опубликованы",
																		"ukr": "Видалити всі файли кешу, коли пост або сторінка опубліковани",
																		"es" : "Limpiar todo el cache cuando se publiquen una entrada o página nuevas"},
			"Submit"  				  : {"tr" : "Gönder",
										 "ru" : "Отправить",
										 "ukr": "Відправити",
										 "es" : "Enviar"},
			"Delete Now" 			  : {"tr" : "Temizle Şimdi",
										 "ru" : "Удалить Сейчас",
										 "ukr": "Видалити Зараз",
										 "es" : "Eliminar ahora"},
			"You can delete all cache files" : {"tr" : "Tüm önbelleği temizleyebilirsiniz",
												 "ru" : "Вы можете удалить все кэшированные файлы",
												 "ukr": "ви можете видалили всі кешированні файли",
												 "es" : "Puede eliminar todos los archivos en el cache"},
			"Target folder"           : {"tr" : "Hedef klasör",
										 "ru" : "Целевая папка",
										 "ukr": "Цільова папка",
										 "es" : "Carpeta de destino"},
			"It will active in the next version" : {"tr" : "Bir sonraki versiyonda aktif olacaktır",
													"ru" : "Эта функция будет активной в следующей версии",
													"ukr":"Ця функція стане активною в наступній версії",
													"es" :"Activo en la siguiente versión"}
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
		jQuery('#wpbody-content label, div.question, .questionCon input[type="submit"], #message p, .wrap h2, #nextVerAct, select option, th').each(function(){
			jQuery(this).wpfclang(self.language);
		});
	}
}