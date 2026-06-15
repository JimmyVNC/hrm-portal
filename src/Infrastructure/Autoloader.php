<?php

/**
 * PSR-4 Autoloader for HRM Portal
 * Maps namespaces to the src directory efficiently.
 */
spl_autoload_register(function ($class) {
    $mappings = [
        'App\\' => __DIR__ . '/../', // src/
        'Shuchkin\\'   => __DIR__ . '/../', // src/ (for SimpleXLSX)
    ];

    foreach ($mappings as $prefix => $baseDir) {
        // Does the class use the namespace prefix?
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        // Get the relative class name
        $relativeClass = substr($class, $len);

        // Replace the namespace prefix with the base directory, 
        // replace namespace separators with directory separators 
        // in the relative class name, append with .php
        $file = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        // If the file exists, require it
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    
    return false;
});
