<?php

require_once 'sf-api-helper.php';

class SFCustomHooks
{
    public function __construct()
    {
        $this->registerCustomMessage();
        $this->registerLoginErrorMessageFilter();
        $this->registerRemoveAdminBar();
        $this->registerCloneRDRole();
        $this->registerRegistrationCallback();
        $this->registerLoginCallback();
        // $this->registerPreventLoginAdminSection();
        $this->limitPostsPerUser();
        $this->registerCronJob();
        $this->registerEmailUpdateCallback();
        $this->registerRedirectAfterPasswordUpdateCallback();
        $this->registerPendingToPublishCallback();
        $this->registerFilterRedirect();
        $this->registerFilterLoginRedirect();
        $this->registerChangeRegistrationEmail();
    }

    public function registerLoginCallback()
    {
        function getParams($email)
        {
            $email = sanitize_email($email);

            if (!email_exists($email)) {
                // no WP account
                ApiData::apiCheckCredentials($email);
            } else {
                // WP account
                $user = get_user_by('email', $email);

                ApiData::apiCheckCredentials($user);
            }
        }
        add_action('wp_authenticate', 'getParams', 30, 2);
    }

    public function registerPreventLoginAdminSection()
    {
        function _s_no_admin_privileges()
        {
            return new WP_Error('no_admin_privileges', '');
        }
        add_filter('authenticate', '_s_no_admin_privileges');
    }

    public function registerRegistrationCallback()
    {
        function get_registration_data($user_id)
        {
            if ($user_id) {
                $user = new WP_User($user_id);

                // check if user is in SalesForce
                if ($user->data->user_email && !$user->has_role('resource_directory')) {
                    $user_profile = ApiData::runQuery($user->data->user_email, 'RD_EMAIL');
                    if (isset($user_profile[0]['RD_UID__c'])) {
                        $user->add_role('resource_directory');
                        $user->remove_role('subscriber');
                        wp_new_user_notification($user_id);
                    } else {
                        // create user with specified roles
                        wp_new_user_notification($user_id);
                    }
                }
            }
        }
        add_action('user_register', 'get_registration_data', 10, 2);
    }

    public static function getRegistrationUrl()
    {
        $registration_url = trim(wpbdp_get_option('registration-url', ''));

        if (!$registration_url && get_option('users_can_register')) {
            if (function_exists('wp_registration_url')) {
                $registration_url = wp_registration_url();
            } else {
                $redirect_to = '?wpbdp_view=submit_listing';
                $registration_url = $registration_url ? add_query_arg(array('redirect_to' => urlencode($redirect_to)), $registration_url) : '';
            }
        }

        return $registration_url;
    }

    public function registerCloneRDRole()
    {
        function _s_clone_role()
        {
            global $wp_roles;
            $sub = new stdClass() ;

            if (!isset($wp_roles)) {
                $wp_roles = new WP_Roles();
            }

            $sub = get_role('resource_directory');

            // roles are sticky; clear out role if created with diff perms
            if ($sub && ($sub->capabilities['read'] && $sub->capabilities['level_0'])) {
                return;
            } elseif ($sub && (!$sub->capabilities['read'] && !$sub->capabilities['level_0'])) {
                remove_role('resource_directory');
            }

            $sub = $wp_roles->get_role('subscriber');
            $wp_roles->add_role('resource_directory', 'Resource Directory', $sub->capabilities);
        }
        add_action('init', '_s_clone_role');
    }
    // run RD cron job
    public function registerCronJob()
    {
        function _s_fire_up_cron()
        {
            (CronActiveMember::getSFMembers());
        }
        add_action('s_run_rd_cron', '_s_fire_up_cron');
    }

    public function registerLoginErrorMessageFilter()
    {
        function handle_login_errors()
        {
            global $errors;
            $err_codes = $errors->get_error_codes();

            $contact = '<a href="/contact">support</a>';

            if (in_array('invalid_email', $err_codes)) {
                $error = '<strong>ERROR</strong>: Your email is incorrect.';
            } elseif (in_array('incorrect_password', $err_codes)) {
                $error = '<strong>ERROR</strong>: The password you entered is incorrect.';
            } elseif (in_array('disabled_account', $err_codes)) {
                $error = sprintf('<strong>ERROR</strong>: This is not an email address for an AMA Chicago member.
                Contact %s for assistance.', $contact);
            } elseif (in_array('no_admin_privileges', $err_codes) && ($GLOBALS['pagenow'] === 'wp-login.php')) {
                $error = '<strong>ERROR</strong>: You are not allowed to log in the admin section.';
            } elseif (in_array('username_exists', $err_codes)) {
                $error = '<strong>ERROR</strong>: You have an active account.';
            } else {
                $error = '<strong>ERROR</strong>: Please check your credentials and try again.';
            }

            return $error;
        }
        add_filter('login_errors', 'handle_login_errors');
    }

    public function registerRemoveAdminBar()
    {
        function _s_remove_admin_bar()
        {
            if (current_user_can('resource_directory')) {
                show_admin_bar(true);
            }
        }
        add_action('after_setup_theme', '_s_remove_admin_bar');
    }

    public function registerCustomMessage()
    {
        function register_message($message)
        {
            if (strpos($message, 'Register') !== false) {
                $new_message = 'Register for the Marketing Directory';

                return '<p class="message register">'.$new_message.'</>';
            }
        }
        add_action('login_message', 'register_message');
    }

    public function limitPostsPerUser()
    {
        function _s_post_published_limit($new_status, $old_status, $post)
        {
            $max_posts = 1;
            $author = $post->post_author;
            $user = new WP_User($author);
            $count = count_user_posts($author, 'wpbdp_listing');

            if (ApiData::checkPermissions($user) && $count > $max_posts && ($new_status == 'auto-draft' || $new_status == 'pending')) {
                wp_trash_post($post->ID);
                return;
            }
        }
        add_action('transition_post_status', '_s_post_published_limit', 10, 3);
    }

    public function registerEmailUpdateCallback()
    {
        function rd_profile_update($user_id, $old_user_data)
        {
            $profile = get_user_by('ID', $user_id);

            if ($profile->data->user_email != $old_user_data->data->user_email) {
                ApiData::updateQuery($profile->RD_UID, $profile->user_email);
            }
        }
        add_action('profile_update', 'rd_profile_update', 10, 2);
    }

    public function registerRedirectAfterPasswordUpdateCallback()
    {
        function rd_password_update($user)
        {
            wp_redirect(home_url('/marketing-directory/?wpbdp_view=login'));
        }
        add_action('after_password_reset', 'rd_password_update', 10, 1);
    }

    public function registerPendingToPublishCallback()
    {
        function on_publish_pending_post($post)
        {
            SFCustomHooks::updatePostMetaCoord($post);
        }
        add_action('pending_to_publish', 'on_publish_pending_post', 10, 1);
    }

    public function registerFilterRedirect()
    {
        function _s_redirect_confirmation($registration_redirect)
        {
            return home_url('/registration-complete');
        }
        add_filter('registration_redirect', '_s_redirect_confirmation');
    }

    public function registerFilterLoginRedirect()
    {
        function _s_login_redirect($redirect_to, $request, $user)
        {
            $current_user_posts = get_posts([
                'author'    =>  $user->ID,
                'post_type' => 'wpbdp_listing',
                'post_status' => ['pending', 'draft', 'publish']
            ]);

            if (ApiData::checkPermissions($user) && count($current_user_posts) >= 1) {
                $redirect_to = home_url('/marketing-directory');
            } elseif (ApiData::checkPermissions($user) && count($current_user_posts) == 0) {
                $redirect_to = home_url('/marketing-directory/?wpbdp_view=submit_listing');
            } elseif (isset($user->roles) && is_array($user->roles)) {
                if (in_array('administrator', $user->roles)) {
                    $redirect_to = admin_url();
                }
            }
            return $redirect_to;
        }
        add_filter('login_redirect', '_s_login_redirect', 10, 3);
    }

    public static function updatePostMetaCoord($post)
    {
        $result = new stdClass();

        // wpbdp form fields have only array notation for identification
        $address = get_post_meta($post->ID, '_wpbdp[fields][10]')[0];
        $zip = get_post_meta($post->ID, '_wpbdp[fields][11]')[0];

        if (isset($zip)) {
            $address = urlencode($address.' '. $zip);
        }

        if (isset($address)) {
            $result = file_get_contents("https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key=$key");
        }

        $resp = json_decode($result, true);

        $lat = isset($resp['results'][0]['geometry']['location']['lat']) ? $resp['results'][0]['geometry']['location']['lat'] : "";
        $lng = isset($resp['results'][0]['geometry']['location']['lng']) ? $resp['results'][0]['geometry']['location']['lng'] : "";

        if ($lat && $lng) {
            update_post_meta($post->ID, 'map', array($lat, $lng));
        }
    }

    public function registerChangeRegistrationEmail()
    {
        function edit_registration_email($message)
        {
            $revised = preg_split('/\s+/', $message['message']);

            unset($revised[11]);

            $message['message'] = implode($revised, ' ');
            $revised2 = explode(' ', $message['message'], 3);

            $message['message'] = $revised2[0] . ' ' . $revised2[1] . "\n" .  $revised2[2];

            return $message;
        }
        add_filter('wp_new_user_notification_email', 'edit_registration_email', 1);
    }
}

(new SFCustomHooks());
