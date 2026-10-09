<?php
$mysqli = new mysqli('127.0.0.1', 'u255640043_Dbmvicgold', '!QaZ@W8SX#2EDC%RDZ', 'u255640043_merryDBvic0');
if ($mysqli->connect_error) { die('Connect Error: ' . $mysqli->connect_error); }
echo "Connected OK\n";

// Check if multisite
$res = $mysqli->query("SHOW TABLES LIKE 'mv_blogs'");
if ($res->num_rows > 0) { echo "Multisite: YES\n"; } else { echo "Multisite: NO\n"; }

// Check sitemeta for site_admins
$res = $mysqli->query("SELECT meta_key, meta_value FROM mv_sitemeta WHERE meta_key='site_admins'");
while ($row = $res->fetch_assoc()) { echo "site_admins: " . $row['meta_value'] . "\n"; }

// Update siteurl and home to production
$mysqli->query("UPDATE mv_options SET option_value='https://merryvic.com' WHERE option_name IN ('siteurl','home')");
echo "Updated siteurl/home to https://merryvic.com\n";

// Verify
$res = $mysqli->query("SELECT option_name, option_value FROM mv_options WHERE option_name IN ('siteurl','home')");
while ($row = $res->fetch_assoc()) { echo $row['option_name'] . ': ' . $row['option_value'] . "\n"; }

// Also check if the users are in site_admins for multisite
$res = $mysqli->query("SELECT user_id, meta_key, meta_value FROM mv_usermeta WHERE user_id IN (2,51) AND meta_key LIKE '%super%admin%'");
while ($row = $res->fetch_assoc()) { echo "Super admin check: " . json_encode($row) . "\n"; }