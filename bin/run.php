#!/usr/bin/env php
<?php

if (!$loader = include __DIR__ . '/../vendor/autoload.php') {
    die('You must set up the project dependencies.');
}

$app = new \Cilex\Application('Kubernetes Garbage Collection');
$app->command(new \Cilex\Command\CollectGarbage());
$app->command(new \Cilex\Command\CollectGarbageCertificates());
$app->command(new \Cilex\Command\CheckEvictions());

$app->run();
