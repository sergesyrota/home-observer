<?php

/*****
  Intent: run on cron every few minutes to log current state from all home thermostats and temperature sensors
*****/

require_once __DIR__ . '/lib.php';

$bridge = new Homebridge();

$sensorsList = [
  'df73fc3d023cabe944fed8a9cca976a8954e802f4b53d37e3de2377fe8f9bc7a', // Master bedroom thermostat
  'dec7abaff62f2c0fd36d74293d9c27238fc5027095797fddec3ab18e6d8648a2', // Sam/Office thermostat
  '9298413701c106099aac4eca4b832104f2a3d0d08de970a18e89c034fe1ddea8', // Leah/Kids thermostat
  '89cf8d0c778533abe54027d734ecdc80f2317c92fceb0412379c05bfaf6e1d3f', // Dining room thermostat (shows currently selected temp sensor)
  '6fbcfec62287945c4ef32c8d07d226cac7cee46e98d30978ee279a12c46e7ebf', // Dining room temperature (separate from the thermostat)
  'bd45c4694aac388d787af2d7dc2398ada5316aff8e12e8b92b412d119b01fb62', // Family room temperature
  '38d4e8f0e50391159207133cd4fa865d671e8b179f5595f480e65281d1b8dd19', // Hapsfield Living room
  //'', //
];

foreach ($sensorsList as $id) {
  $data = $bridge->getAccessory($id);
  $log = [
    '@timestamp' => date('c'),
    'dataSource' => 'Homebridge',
    'sensorData' => array_merge(
      ['name' => $data['accessoryInformation']['Name'] . ' (' . $data['humanType'] . ')'],
      $data['values']
    )
  ];
  var_dump($log);
  file_put_contents(getRequiredEnv('OBSERVER_LOG_FILE'), json_encode($log) . "\n", FILE_APPEND);
}
