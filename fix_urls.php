<?php
$mysqli = new mysqli('127.0.0.1', 'u255640043_Dbmvicgold', '!QaZ@W8SX#2EDC%RDZ', 'u255640043_merryDBvic0');
if ($mysqli->connect_error) die('Error: ' . $mysqli->connect_error);
$mysqli->query("UPDATE mv_options SET option_value='https://merryvic.com' WHERE option_name IN ('siteurl','home')");
echo "Rows updated: " . $mysqli->affected_rows . "\n";
$res = $mysqli->query("SELECT option_name, option_value FROM mv_options WHERE option_name IN ('siteurl','home')");
while ($row = $res->fetch_assoc()) echo $row['option_name'] . ': ' . $row['option_value'] . "\n";