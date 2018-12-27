<?php

class Loader {

    public static $rootDir;

    public static function autoload($className){
        if (strpos($className, '\\') !== false && strpos($className, 'pfilsx\db_broker') !== false) {
            $classFile = implode(DIRECTORY_SEPARATOR,[
                static::$rootDir,
                ltrim(str_replace(['pfilsx\\db_broker','\\', '/'], DIRECTORY_SEPARATOR, $className).'.php', DIRECTORY_SEPARATOR)
            ]);
            if ($classFile === false || !is_file($classFile)) {
                return;
            }
        } else {
            return;
        }
        require_once $classFile;
        if (!class_exists($className, false) && !interface_exists($className, false) && !trait_exists($className, false)) {
            throw new Exception("Unable to find '$className' in file: $classFile. Namespace missing?");
        }
    }
}
Loader::$rootDir = __DIR__;
spl_autoload_register(['Loader', 'autoload'], true ,true);
