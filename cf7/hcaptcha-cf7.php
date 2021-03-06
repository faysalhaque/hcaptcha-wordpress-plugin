<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) exit;

$hcaptcha_api_key = get_option('hcaptcha_api_key');
$hcaptcha_secret_key = get_option('hcaptcha_secret_key');
if (!empty($hcaptcha_api_key) && !empty($hcaptcha_secret_key) && !is_admin()) {
    function enqueue_hcap_cf7_script()
    {
        global $hcap_cf7;
        if (!$hcap_cf7) {
            return;
        }
        $hcaptcha_api_key = get_option('hcaptcha_api_key');

        $script = "var widgetIds = [];
        var hcap_cf7LoadCallback = function() {
        var hcap_cf7Widgets = document.querySelectorAll('.hcap_cf7-h-captcha');
        for (var i = 0; i < hcap_cf7Widgets.length; ++i) {
            var hcap_cf7Widget = hcap_cf7Widgets[i];
            var widgetId = hcaptcha.render(hcap_cf7Widget.id, {
                'sitekey' : '" . $hcaptcha_api_key . "'
                });
                widgetIds.push(widgetId);
            }
        };
        (function($) {
            $('.wpcf7').on('invalid.wpcf7 mailsent.wpcf7', function() {
                for (var i = 0; i < widgetIds.length; i++) {
                    hcaptcha.reset(widgetIds[i]);
                }
            });
        })(jQuery);";

        wp_add_inline_script('hcaptcha-script', $script);
    }
    add_action('wp_enqueue_scripts', 'enqueue_hcap_cf7_script');

    function hcap_cf7_wpcf7_form_elements($form)
    {
        /**
         * The quickest and easiest way to add the hcaptcha shortcode if it's not added in the CF7 form fields.
         */
        if (strpos($form, '[cf7-hcaptcha]') === false) {
            $form = str_replace('<input type="submit"', '[cf7-hcaptcha]<br /><input type="submit"', $form);
        }
        $form = do_shortcode($form);
        return $form;
    }
    add_filter('wpcf7_form_elements', 'hcap_cf7_wpcf7_form_elements');

    function hcap_cf7_shortcode($atts)
    {
        global $hcap_cf7;
        $hcap_cf7 = true;
        $hcaptcha_api_key = get_option('hcaptcha_api_key');
        $hcaptcha_theme     = get_option("hcaptcha_theme");
        $hcaptcha_size         = get_option("hcaptcha_size");

        return '<div class="h-captcha" id="hcap_cf7-' . uniqid() . '" class="h-captcha hcap_cf7-h-captcha" data-sitekey="' . $hcaptcha_api_key
            . '" data-theme="' . $hcaptcha_theme . '" data-size="' . $hcaptcha_size . '"></div><span class="wpcf7-form-control-wrap hcap_cf7-h-captcha-invalid"></span>' . wp_nonce_field('hcaptcha_contact_form7', 'hcaptcha_contact_form7', true, false);
    }
    add_shortcode('cf7-hcaptcha', 'hcap_cf7_shortcode');

    function hcap_cf7_verify_recaptcha($result)
    {
        // As of CF7 5.1.3, NONCE validation always fails. Returning to false value shows the error, found in issue #12
        // if (!isset($_POST['hcaptcha_contact_form7_nonce']) || (isset($_POST['hcaptcha_contact_form7_nonce']) && !wp_verify_nonce($_POST['hcaptcha_contact_form7'], 'hcaptcha_contact_form7'))) {
        //     return false;
        // }
        //
        // CF7 author's comments: "any good effect expected from a nonce is limited when it is used for a publicly-open contact form that anyone can submit,
        // and undesirable side effects have been seen in some cases.”
        //
        // Our comments: hCaptcha passcodes are one-time use, so effectively serve as a nonce anyway.

        $_wpcf7 = !empty($_POST['_wpcf7']) ? absint($_POST['_wpcf7']) : 0;
        if (empty($_wpcf7)) {
            return $result;
        }

        $submission = WPCF7_Submission::get_instance();
        $data = $submission->get_posted_data();
        if (empty($data['_wpcf7'])) {
            return $result;
        }

        $cf7_text = do_shortcode('[contact-form-7 id="' . $data['_wpcf7'] . '"]');
        $hcaptcha_api_key = get_option('hcaptcha_api_key');
        if (false === strpos($cf7_text, $hcaptcha_api_key)) {
            return $result;
        }

        $message = get_option('hcap_cf7_message');
        if (empty($message)) {
            $message = 'Invalid captcha';
        }

        if (empty($data['h-captcha-response'])) {
            $result->invalidate(array('type' => 'captcha', 'name' => 'hcap_cf7-h-captcha-invalid'), $message);
            return $result;
        }

        $hcaptcha_secret_key = get_option('hcaptcha_secret_key');
        $url = 'https://hcaptcha.com/siteverify?secret=' . $hcaptcha_secret_key . '&response=' . sanitize_text_field($data['h-captcha-response']);
        $request = wp_remote_get($url);
        $body = wp_remote_retrieve_body($request);
        $response = json_decode($body);
        if (!(isset($response->success) && 1 == $response->success)) {
            $result->invalidate(array('type' => 'captcha', 'name' => 'hcap_cf7-h-captcha-invalid'), $message);
        }

        return $result;
    }
    add_filter('wpcf7_validate', 'hcap_cf7_verify_recaptcha', 20, 2);
}
