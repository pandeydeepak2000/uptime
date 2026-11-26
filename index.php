<?php
if (!is_file(__DIR__ . '/app/config.php')) {
    header('Location: install.php');
    exit;
}
require __DIR__ . '/app/bootstrap.php';
redirect(auth_user() ? 'dashboard.php' : 'login.php');
