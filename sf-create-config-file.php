<?php

class CreateConfigFile
{
    private static $instance;

    private function __construct()
    {
        $this->checkIfExistsConfigFile();
    }

    public static function getInstance(): CreateConfigFile
    {
        if (null === static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    private function checkIfExistsConfigFile()
    {
        $file = DIRECTORY_SEPARATOR.'sf-key';
        $dir = wp_get_upload_dir()['path'];

        if (is_file($dir.$file)) {
            return true;
        } else {
            try {
                touch($dir.$file, 0744);
            } catch (\Throwable $th) {
                error_log(print_r($th->getMessage(), true));
            }
            return false;
        }
    }
}