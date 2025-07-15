<?php

spl_autoload_register(function ($class) {
    // Convert namespace to file path
    $class = str_replace('Redis\\', '', $class);
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
