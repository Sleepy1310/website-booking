<?php
/**
 * INIT - Entry point cho mọi API endpoint
 * Dùng đường dẫn tuyệt đối để tránh lỗi trên Windows/Apache
 * Include: require_once $_SERVER['DOCUMENT_ROOT'] . '/WebsiteDatVe/api/config/init.php';
 */
$_API_ROOT = 'C:/xampp/htdocs/WebsiteDatVe/api/config';

require_once $_API_ROOT . '/database.php';
require_once $_API_ROOT . '/bootstrap.php';
