<?php
require_once "Urlparser.php";

$url = "http://simonenko.su";
//$url = "https://wiki.pwodev.com";

$parser = new Urlparser();
$parser->parse($url, true, EXTERNAL, 3, true);

echo '<br>Done!<br>';
echo 'Всего: ' . count($parser->links) . '<br>';
echo 'Уникальных: ' . $parser->getCountUnique() . '<br>';
echo 'Всего внутренних: ' . $parser->internal_counter . '<br>';
echo 'Всего внешних: ' . $parser->external_counter . '<br>';
flush();
die;