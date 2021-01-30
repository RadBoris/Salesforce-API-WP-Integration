<?php

class SFLocalFileStoreConfig extends \Exception implements \Crunch\Salesforce\TokenStore\LocalFileConfigInterface
{
    /**
     * The path where the file will be stored, no trailing slash, must be writable.
     *
     * @return string
     */
    public function getFilePath()
    {
        return wp_get_upload_dir()['path'];
    }
}
