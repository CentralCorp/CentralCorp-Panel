<?php
session_start();
$configFilePath = 'config.php';
if (!file_exists($configFilePath)) {
    header('Location: setdb');
    exit();
}
require_once 'connexion_bdd.php';
require('auth.php');
header('Location: account/connexion.php');
exit();
?>