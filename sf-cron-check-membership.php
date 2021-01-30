<?php

class CronActiveMember
{
    const RD_UID = 'RD_UID';

    public static function getSFMembers()
    {
        global $wpdb;
        $pref = $wpdb->prefix;

        $ids = $rd_uids = [];
        $args = array('meta_key' => self::RD_UID, 'fields' => 'ids');

        foreach ($ids as $id) {
            $udata = get_user_meta($id, self::RD_UID, true);
            array_push($rd_uids, $udata);
        }

        foreach ($rd_uids as $rd_uid) {
            CronActiveMember::checkMemberActive($rd_uid, $pref);
        }
    }

    public static function checkMemberActive($rd_uid, $pref)
    {
        $date_now = date('Y-m-d');
        $user_profile = ApiData::runQuery($rd_uid);

        if ($user_profile[0]['HQ_Expiration_Date__c'] < $date_now || empty($user_profile[0]['HQ_Expiration_Date__c'])) {
            // get WP user email because email may have been updatedin SF
            $wp_user = CronActiveMember::getWPuser($user_profile[0]['RD_UID__c']);

            if (isset($user_profile[0]['RD_EMAIL__c'])) {
                try {
                    if (CronActiveMember::suspendListing($wp_user->data->user_email, $pref) === false) {
                        throw new Exception('Error Processing Post Status', 1);
                    }
                } catch (Exception $e) {
                    error_log($e->getMessage());
                }
            }
        }
    }

    public static function suspendListing($wp_email, $pref)
    {
        // suspend listing from directory
        global $wpdb;

        $query = "SELECT * FROM {$pref}posts WHERE post_author = {$wp_email}";

        wp_mail($wp_email, 'Please update your AMA membership', 'Your membership has expired, please renew your AMA membership at ama.org');

        if (isset($query)) {
            $result = $wpdb->get_row($query);

            $removed = wp_update_post(array(
                'ID' => $result->ID,
                'post_status' => 'draft',
            ));

            if ($removed != $result->ID) {
                return false;
            }
        }

        return true;
    }

    public static function getWPuser($sf_RF_UID)
    {
        $args = array(
            'meta_key' => 'RD_UID',
            'meta_value' => $sf_RF_UID,
        );

        $wp_user = get_users($args);

        return $wp_user;
    }

    public static function deleteMemberProfile()
    {
        // TODO: if expired for longer than 365 days delete profile
    }
}
