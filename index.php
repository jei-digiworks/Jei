<?php
// Root index.php
// Redirects incoming web traffic to the Player Portal login screen

header("Location: auth/login.php");
exit;
