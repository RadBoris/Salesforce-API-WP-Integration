<?php


class SalesforceConfig implements \Crunch\Salesforce\ClientConfigInterface
{
    /**
     * @return string
     */
    public function getLoginUrl()
    {
        // check if WP Engine production environment is running
        if (function_exists('is_wpe') && is_wpe() == 1) {
            return "https://login.salesforce.com/services/oauth2/token";
        } else {
            return "https://dev.salesforce.com/services/oauth2/token";
        }
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        return $clientid;
    }

    /**
     * @return string
     */
    public function getClientSecret()
    {
        return $clientsecret;
    }
}
