jQuery(document).ready(function() {

    /* Counter */

    jQuery('[data-wpucacheblockscounter]').each(function(i, el) {
        var $this = jQuery(el),
            i = parseInt($this.attr('data-wpucacheblockscounter'), 10);

        if (i < 0) {
            return;
        }

        var itv = setInterval(function() {
            i--;
            if (i == 0) {
                clearInterval(itv);
                $this.parent().text($this.parent().attr('data-wpucacheblockscounterempty'));
                return;
            }
            $this.text(i);
        }, 1000);

    });

});
