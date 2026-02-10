<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    return;
}

$repoDir = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($repoDir): void {
    $prefix = 'Ksfraser\\Database\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $repoDir . '/src/Ksfraser/Database/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
