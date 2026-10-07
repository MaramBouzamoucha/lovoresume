<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';
// Vérifie et crée le répertoire logs si nécessaire
$logDir = dirname(__DIR__).'/var/log';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
