jQuery(document).ready(function() {
 
    jQuery('#yawpp_upload_audio').click(function() {
        // attach our upload function
        if(typeof window.wp_send_to_editor != 'function') {
			window.wp_send_to_editor = window.send_to_editor;
			window.send_to_editor = window.yawpp_send_to_editor;
			formfield = jQuery('#yawpp_file').attr('name');
		}
    });
    jQuery('#yawpp_upload_video').click(function() {
        // attach our upload function
        if(typeof window.wp_send_to_editor != 'function') {
			window.wp_send_to_editor = window.send_to_editor;
			window.send_to_editor = window.yawpp_send_to_editor;
			formfield = jQuery('#yawpp_file').attr('name');
		}
    });
    // restore wordpress default send_to_editor
    jQuery('#media-buttons a.thickbox').bind('click', function() {
        // yawpp upload function is active
        if(typeof window.wp_send_to_editor == 'function') {
            window.send_to_editor = window.wp_send_to_editor;
            window.wp_send_to_editor = null;
        }
    });

    window.yawpp_send_to_editor = function(h) {
        h = jQuery(h).attr('href');
        jQuery('#yawpp_file').val(h);
        tb_remove();
    }

});
