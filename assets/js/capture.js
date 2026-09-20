(function($){
    'use strict';
    if(typeof GLAC==='undefined') return;
    var timer;
    function serialized(){
        var $f=$('form.checkout');
        if(!$f.length) $f=$('form.woocommerce-checkout');
        return $f.length ? $f.serialize() : '';
    }
    function capture(){
        $.post(GLAC.ajax,{action:'gl_ac_capture',nonce:GLAC.nonce,checkout_data:serialized()});
    }
    function schedule(){ clearTimeout(timer); timer=setTimeout(capture,parseInt(GLAC.delay,10)||800); }
    $(document.body).on('input change blur','form.checkout input, form.checkout select, form.checkout textarea, form.woocommerce-checkout input, form.woocommerce-checkout select, form.woocommerce-checkout textarea',schedule);
    $(document.body).on('updated_checkout updated_cart_totals added_to_cart removed_from_cart wc_fragments_refreshed',schedule);
    $(function(){ setTimeout(capture,1000); });
})(jQuery);
