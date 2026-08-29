<?php
declare(strict_types=1);
/* Universidade VERO — Logout do LMS. */
require_once __DIR__ . '/../includes/uni_auth.php';
uni_auth_boot();
uni_auth_logout();
header('Location: ' . (defined('BIOS_BASE') ? BIOS_BASE : '') . '/universidade/login.php');
exit;
