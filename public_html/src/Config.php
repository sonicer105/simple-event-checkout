<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public static function load(): array
    {
        if (!defined('ABS_PATH')) {
            http_response_code(403);
            exit('Direct access not allowed.');
        }

        $config = require ABS_PATH . '/public_html/config/config.php';
        $localConfigPath = ABS_PATH . '/public_html/config/local.php';

        if (is_file($localConfigPath)) {
            $localConfig = require $localConfigPath;
            if (is_array($localConfig)) {
                $config = array_replace_recursive($config, $localConfig);
            }
        }

        return $config;
    }
}
