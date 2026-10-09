<?php
$mysqli = new mysqli('127.0.0.1', 'u255640043_Dbmvicgold', '!QaZ@W8SX#2EDC%RDZ', 'u255640043_merryDBvic0');
if ($mysqli->connect_error) { die('Connect Error: ' . $mysqli->connect_error); }
echo "Connected OK\n";
$res = $mysqli->query("SELECT option_name, option_value FROM mv_options WHERE option_name IN ('siteurl','home')");
while ($row = $res->fetch_assoc()) { echo $row['option_name'] . ': ' . $row['option_value'] . "\n"; }
$res = $mysqli->query("SELECT ID, user_email, user_login FROM mv_users WHERE user_email IN ('support@merryvic','support@cromstelit')");
while ($row = $res->fetch_assoc()) { echo "User: ID={$row['ID']} email={$row['user_email']} login={$row['user_login']}\n"; }
$res = $mysqli->query("SELECT user_id, meta_key, meta_value FROM mv_usermeta WHERE user_id IN (2,51) AND meta_key LIKE '%capabilities%'");
while ($row = $res->fetch_assoc()) { echo "Caps: user_id={$row['user_id']} key={$row['meta_key']} val={$row['meta_value']}\n"; }