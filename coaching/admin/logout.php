<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
$_SESSION = [];
session_destroy();
header('Location: ' . url(''));
