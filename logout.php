<?php
require __DIR__ . '/app/bootstrap.php';
session_boot();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid()) {
    if (!empty($_SESSION['uid'])) {
        audit('logout', 'Signed out');
    }
    logout_user();
    flash('success', 'You have been signed out securely.');
}
redirect('login.php');
