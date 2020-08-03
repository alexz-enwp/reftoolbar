<?php
$value = "Third edition";
$result['edition'] = str_replace(' edition','',$value);
$result['edition'] = str_replace(' ed.','',$value);
$result['edition'] = str_replace(' ed','',$value);
echo $result['edition'];
