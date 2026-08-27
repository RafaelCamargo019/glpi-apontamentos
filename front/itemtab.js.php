<?php

include '../../../inc/includes.php';

Session::checkLoginUser();

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile(__DIR__ . '/../js/itemtab.js');
