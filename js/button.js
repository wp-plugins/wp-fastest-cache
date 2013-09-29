(function() {
    tinymce.create('tinymce.plugins.Wpfc', {
        init : function(ed, url) {
            url = url.replace("../js","../images");
            ed.addButton('wpfc', {
                title : 'Block caching for this page',
                cmd : 'wpfc',
                image : url + "/icon.png"
            });

            ed.addCommand('wpfc', function() {
                ed.execCommand('mceInsertContent', 0, "[wpfcNOT]");
            });
        }
    });
    tinymce.PluginManager.add( 'wpfc', tinymce.plugins.Wpfc );
})();