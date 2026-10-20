<?php
session_start();
session_unset();
session_destroy();
header('Location: /WebsiteDatVe/index.php');
exit;
