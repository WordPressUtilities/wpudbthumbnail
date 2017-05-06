jQuery(document).ready(function() {
    var wrapp = jQuery('#set-post-thumbnail'),
        img_val;
    /* No thumbnail by default */
    if (!wrapp || wrapp.find('img').length < 1) {
        return;
    }
    var itvwpudbthumbnail = setInterval(function() {
        var img = wrapp.find('img');
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
            clearInterval(itvwpudbthumbnail);
            jQuery('.wpudbthumbnail-wrapper').remove();
        }
    }, 500);
});
