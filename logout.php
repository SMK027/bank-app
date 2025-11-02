<?php
require_once 'config/config.php';

logoutUser();
redirect(BASE_URL . '/login.php');
