<?php

declare(strict_types=1);

use PHPUnit\Metadata\Api\Dependencies;

require 'vendor/autoload.php';

// Use PHPUnit's interpretation of inherited and cross-class dependency attributes.
$listing = simplexml_load_file('/tmp/v2-list.xml');
$edges = [];
foreach ($listing->tests->testClass as $class) {
    $name = (string) $class['name'];
    require_once (string) $class['file'];
    $methods = [];
    foreach ($class->testMethod as $method) {
        $methods[(string) $method['name']] = true;
    }
    foreach (array_keys($methods) as $method) {
        foreach (Dependencies::dependencies($name, $method) as $dependency) {
            $target = $dependency->getTargetClassName();
            if ($target !== $name) {
                $edges[$name][$target] = true;
            }
        }
    }
}
echo json_encode(array_map('array_keys', $edges), JSON_THROW_ON_ERROR);
