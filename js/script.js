jQuery(document).ready(function() {
    var wrapp = jQuery('#postimagediv'),
        img_val;
    if (!wrapp) {
        return;
    }
    var itv = setInterval(function() {
        var img = wrapp.find('#set-post-thumbnail img');
        if (!img) {
            return;
        }
        var img_src = img.attr('src');
        if (!img_val) {
            img_val = img_src;
            return;
        }
        if (img_val != img_src) {
            img_val = img_src;
            console.log('aaa');
            clearInterval(itv);
            jQuery('.wpudbthumbnail-wrapper').remove();
        }
    }, 1000);
});
