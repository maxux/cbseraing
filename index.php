<?php
include('includes/cbseraing.class.php');
include('includes/layout.class.php');

$layout = new LightLayout();
$cbs = new CBSeraing\cbseraing($layout);

$cbs->stage1();

echo $layout->render();
?>
