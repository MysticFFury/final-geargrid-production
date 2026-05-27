<?php
header('Content-Type: text/plain');
echo "DATABASE_URL via getenv: " . getenv('DATABASE_URL') . "\n";
echo "DATABASE_URL via _ENV: " . ($_ENV['DATABASE_URL'] ?? 'NOT SET') . "\n";
echo "DATABASE_URL via _SERVER: " . ($_SERVER['DATABASE_URL'] ?? 'NOT SET') . "\n";
echo "MYSQL_URL via getenv: " . getenv('MYSQL_URL') . "\n";
