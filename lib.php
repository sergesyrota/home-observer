<?php

require_once __DIR__ . '/lib/Homebridge.php';

function getRequiredEnv($var)
{
  $data = getenv($var);
  if (empty($data)) {
    throw new Exception("Please specify $var as environment variable to run.");
  }
  return $data;
}
