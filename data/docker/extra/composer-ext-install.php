#!/usr/bin/env php
<?php

/*
 * version: 2020-02-24
 */

list($major,$minor,$patch) = explode('.',phpversion());
$command                   = <<<EOC
apk add php${major}-%1\$s || \
      apk add php${major}-pecl-%1\$s || \
      apt install -yqq php${major}.${minor}-%1\$s || \
      (docker-php-ext-install %1\$s && docker-php-ext-enable %1\$s) || \
      (yes '\n' | pecl install %1\$s && echo "extension=%1\$s.so" >> /etc/php7/conf.d/30_%1\$s.ini)
EOC;

$cwd         = getcwd();
$known       = [];
$descriptors = [
    ['pipe', 'r'],
    ['pipe', 'w'],
    ['file', '/tmp/error-output.txt', 'a'],
];

$app = json_decode(file_get_contents($argv[1]??(__DIR__.'/../composer.json')));
$dep = json_decode(file_get_contents($argv[2]??(__DIR__.'/../composer.lock')));
array_push($dep->packages, $app);

unset($_SERVER['argc']);
unset($_SERVER['argv']);

$packages = array_merge(
    $dep->packages,
    $dep->{'packages-dev'}
);

foreach($packages as $package) {
    $dependencies = array_merge(
        (array) ($package->require ?? []),
        (array) ($package->{'require-dev'} ?? [])
    );
    foreach($dependencies as $name => $version) {
        if (substr($name, 0, 4) !== 'ext-') continue;

        if (isset($known[$name])) {
            continue;
        } else {
            $known[$name] = true;
        }

        $pipes = [];
        $cmd   = sprintf($command,substr($name,4));
        echo ' ---> ', $cmd, PHP_EOL;
        $process = proc_open($cmd, $descriptors, $pipes, $cwd, $_SERVER);
        while(!feof($pipes[1])) {
            echo '      ', fgets($pipes[1]);
        }
        echo PHP_EOL;
        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_close($process);

    }
}
