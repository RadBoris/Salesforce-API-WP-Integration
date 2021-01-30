<?php

require_once 'sf-config.php';
require_once 'local-sf-config.php';
require_once 'sf-cron-check-membership.php';
require_once 'sf-custom-hooks.php';
require_once 'sf-create-config-file.php';

class ApiData
{
    const RD_UID = 'RD_UID';
    const RD_EMAIL = 'RD_EMAIL';
    const EMAIL_FIELD = 'RD_Email__c';
    const EMAIL_DATE_CHANGED = 'RD_Email_Changed_Date__c';
    const EMAIL_SOURCE = 'RD_Email_Source__c';


    private $accessToken;
    private $sfConfig;
    private $localSfConfig;
    private $tokenStore;
    private $token;
    private $url;
    private $clientId;
    private $clientSecret;
    private $tokenGenerator;

    public function __construct()
    {
        $this->sfConfig = new SalesforceConfig();
        CreateConfigFile::getInstance();
        $this->localSfConfig = new SFLocalFileStoreConfig();
        $this->tokenStore = new \Crunch\Salesforce\TokenStore\LocalFile(new \Crunch\Salesforce\AccessTokenGenerator(), $this->localSfConfig);

        $this->tokenGenerator = new \Crunch\Salesforce\AccessTokenGenerator();

        $this->accessToken = $this->tokenStore->fetchAccessToken();

        if (is_null($this->accessToken) || $this->accessToken->needsRefresh()) {
            $this->accessToken = $this->getAccessToken();

            $accessToken = $this->tokenGenerator->createFromSalesforceResponse($this->accessToken);
            $this->tokenStore->saveAccessToken($accessToken);
        }
    }

    public function getConfig()
    {
        $path = $this->localSfConfig->getFilePath();
        $this->url = $this->sfConfig->getLoginUrl();

        $this->clientId = $this->sfConfig->getClientId();
        $this->clientSecret = $this->sfConfig->getClientSecret();

        $creds = new \stdClass();
        $creds->url = $this->url;
        $creds->clientId = $this->clientId;
        $creds->clientSecret = $this->clientSecret;

        return $creds;
    }

    public function getAccessToken()
    {
        $creds = $this->getConfig();

        if (!$creds->clientId || !$creds->clientSecret) {
            error_log('API credentials are missing');

            return;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $creds->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // check if WP Engine production environment is running
        if (function_exists('is_wpe') && is_wpe() == 1) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=password&client_id={$creds->clientId}&client_secret={$creds->clientSecret}&username=dev@email.com&password=$pwd");
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=password&client_id={$creds->clientId}&client_secret={$creds->clientSecret}&username=prd@email.com.directory&password=$pwd");
        }
        curl_setopt($ch, CURLOPT_POST, 1);

        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            echo 'Error:'.curl_error($ch);
        }
        curl_close($ch);

        $result = json_decode($result);
        $converted = get_object_vars($result);

        return $converted;
    }

    public static function runQuery($identifier, $identifierType = self::RD_UID)
    {
        $data = [];
        $localSfConfig = new SFLocalFileStoreConfig();
        $tokenStore = new \Crunch\Salesforce\TokenStore\LocalFile(new \Crunch\Salesforce\AccessTokenGenerator(), $localSfConfig);
        $tokenGenerator = new \Crunch\Salesforce\AccessTokenGenerator();

        $accessToken = $tokenStore->fetchAccessToken();

        $sfConfig = new SalesforceConfig();

        $sfClient = new \Crunch\Salesforce\Client($sfConfig, new \GuzzleHttp\Client());

        $sfClient->setAccessToken($accessToken);

        $identifier = trim($identifier);

        switch ($identifierType) {
            case self::RD_UID:
                $data = $sfClient->search("SELECT firstName, lastName, RD_UID__c, RD_Email_Changed_Date__c, HQ_Expiration_Date__c FROM Contact where  RD_UID__c='{$identifier}'");
                break;
            case self::RD_EMAIL:
                $data = $sfClient->search("SELECT firstName, lastName, RD_UID__c, RD_Email_Changed_Date__c, HQ_Expiration_Date__c FROM Contact where  RD_Email__c='{$identifier}'");
                break;
            default:
        }

        return $data;
    }

    public static function apiCheckCredentials($user)
    {
        $user_profile = [];
        $RD_UID = '';
        $date_now = date('Y-m-d');
        $rd_home = '/marketing-directory/';

        // if current WP user
        if (is_object($user)) {
            if (isset($user->RD_UID)) {
                $user_profile = ApiData::runQuery($user->RD_UID);

                if ($user_profile[0]['HQ_Expiration_Date__c'] > $date_now) {
                    if (ApiData::checkPermissions($user)) {
                        apply_filters('_s_login_redirect', '');
                    }
                } else {
                    apply_filters('membership_expired', '');
                    wp_redirect(wp_login_url());

                    exit;
                }
            } else {
                // first time login or non-RD user
                $user_profile = ApiData::runQuery($user->data->user_email, self::RD_EMAIL);

                if (!empty($user_profile) && ($user_profile[0]['HQ_Expiration_Date__c'] > $date_now)) {
                    $RD_UID = $user_profile[0]['RD_UID__c'];

                    add_user_meta($user->data->ID, 'RD_UID', $RD_UID);

                    if (ApiData::checkPermissions($user) && $user_profile[0]['RD_UID__c']) {
                        wp_redirect($rd_home);
                    }
                } else {
                    // non RD user
                    if ($GLOBALS['pagenow'] === 'wp-login.php') {
                        if ($user->has_cap('administrator')) {
                            wp_redirect(admin_url());
                        } else {
                            //apply_filters('no_admin_privileges', '');
                            wp_redirect(wp_login_url());
                            exit;
                        }
                    }
                }
            }
        } else {
            // no WP account but SF account active, or email changed in SF
            $user_profile = ApiData::runQuery($user, self::RD_EMAIL);

            if (!empty($user_profile)) {
                isset($user_profile[0]['RD_UID__c']) ? $sf_RF_UID = (string) $user_profile[0]['RD_UID__c'] : $sf_RF_UID = '';

                if (!empty($sf_RF_UID)) {
                    $args = array(
                        'meta_key' => 'RD_UID',
                        'meta_value' => $sf_RF_UID,
                    );

                    $wp_user = get_users($args);

                    if (isset($wp_user[0]->data->ID) && (isset($user_profile[0]['HQ_Expiration_Date__c']) && $user_profile[0]['HQ_Expiration_Date__c'] > $date_now)) {
                        if (ApiData::checkPermissions($wp_user[0]) && count_user_posts($user->data->ID, 'wpbdp_listing') == 1) {
                            wp_redirect($rd_home);
                        } elseif (ApiData::checkPermissions($wp_user[0]) && count_user_posts($user->data->ID, 'wpbdp_listing') == 0) {
                            apply_filters('_s_login_redirect', '');
                        }
                    } elseif (!isset($wp_user[0]->ID) && (isset($user_profile[0]['HQ_Expiration_Date__c']) && $user_profile[0]['HQ_Expiration_Date__c'] > $date_now)) {
                        $registration_url = SFCustomHooks::getRegistrationUrl();
                        wp_redirect($registration_url);
                        exit;
                    } else {
                        apply_filters('membership_expired', '');
                    }
                } else {
                    return;
                }
            } else {
                return;
            }
        }
    }

    public static function checkPermissions($user)
    {
        global $wpdb;

        $property = $wpdb->prefix.'capabilities';
        $caps = $user->$property;

        if (isset($caps) && is_array($caps)) {
            if (array_key_exists('resource_directory', $caps) && $caps['resource_directory'] == 1) {
                return true;
            }
        }

        return false;
    }

    public static function updateQuery($identifier, $new_email)
    {
        $identifier = trim($identifier);

        $localSfConfig = new SFLocalFileStoreConfig();
        $tokenStore = new \Crunch\Salesforce\TokenStore\LocalFile(new \Crunch\Salesforce\AccessTokenGenerator, $localSfConfig);
        $tokenGenerator = new \Crunch\Salesforce\AccessTokenGenerator();

        $accessToken = $tokenStore->fetchAccessToken();

        $sfConfig = new SalesforceConfig();

        $sfClient = new \Crunch\Salesforce\Client($sfConfig, new \GuzzleHttp\Client());

        $sfClient->setAccessToken($accessToken);

        $data = $sfClient->search("SELECT Id FROM Contact where  RD_UID__c='{$identifier}'");

        $sfClient->updateRecord('Contact', $data[0]['Id'], [ self::EMAIL_FIELD => $new_email, self::EMAIL_SOURCE => 'ProfileUse']);
    }
}

(new ApiData());
