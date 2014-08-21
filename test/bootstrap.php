<?php

if (is_file('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
    require_once 'test/Mocks.php';
} elseif (is_file('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
    require_once '../test/Mocks.php';
}
