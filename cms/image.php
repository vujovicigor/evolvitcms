<?php
include("engine.php");
print_image(isset($_GET['id']) ? (string)$_GET['id'] : '');
