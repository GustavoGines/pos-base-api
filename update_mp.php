<?php
$file = 'tests/Feature/MercadoPagoFeatureTest.php';
$content = file_get_contents($file);
$content = str_replace("'pos_id' => 'caja-principal',", "'pos_id' => 'CAJAPRINCIPAL',", $content);
file_put_contents($file, $content);
