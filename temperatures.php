<?php

/*****
  Intent: run on cron every few minutes to log current state from all home thermostats and temperature sensors
*****/

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/vendor/autoload.php';

use \SyrotaAutomation\Gearman;

$bridge = new Homebridge();
$gm = new Gearman('rs485');

$sensorsList = [
  'df73fc3d023cabe944fed8a9cca976a8954e802f4b53d37e3de2377fe8f9bc7a' => [ // Master bedroom thermostat
    'fanId' => '18c339c9f54bf17ed622a88e0f300750e21864adc53dc45e484e0475f54a48de'
  ],
  'dec7abaff62f2c0fd36d74293d9c27238fc5027095797fddec3ab18e6d8648a2' => [ // Sam/Office thermostat
    'fanId' => '2ae4cf0b077df8fc4ff4f95e4e1dc704094898d3df850464c70be6de11163539'
  ],
  '9298413701c106099aac4eca4b832104f2a3d0d08de970a18e89c034fe1ddea8' => [ // Leah/Kids thermostat
    'fanId' => '57b720dd90f89d2ce869c04241a1c69acd6ee6a69c28a7601f597bb2b165f30a'
  ],
  '89cf8d0c778533abe54027d734ecdc80f2317c92fceb0412379c05bfaf6e1d3f' => [ // Dining room thermostat (shows currently selected temp sensor)
    'fanId' => 'a86ffc334512b803abd5496f632ccc8c0ee154191068abc55c84e7d4d526db48',
    'equipmentMonitorDevice' => 'BrAcSens'
  ],
  '6fbcfec62287945c4ef32c8d07d226cac7cee46e98d30978ee279a12c46e7ebf' => [ // Dining room temperature (separate from the thermostat)
  ],
  'bd45c4694aac388d787af2d7dc2398ada5316aff8e12e8b92b412d119b01fb62' => [ // Family room temperature
  ],
  '38d4e8f0e50391159207133cd4fa865d671e8b179f5595f480e65281d1b8dd19' => [ // Hapsfield Living room
    'fanId' => 'e3422762e350a9d040aae005895c8a9aacd5418adb65882a626bc5cb14c3a4ad'
  ]
  //'', //
];

foreach ($sensorsList as $id => $param) {
  $data = $bridge->getAccessory($id);
  $log = [
    '@timestamp' => date('c'),
    'dataSource' => 'Homebridge',
    'message' => '(see sensorData.*)',
    'sensorData' => array_merge(
      ['name' => $data['accessoryInformation']['Name'] . ' (' . $data['humanType'] . ')'],
      $data['values']
    )
  ];
  if (!empty($param['fanId'])) {
    $fanData = $bridge->getAccessory($param['fanId']);
    $log['sensorData']['fan'] = $fanData['values'];
  }
  if (!empty($param['equipmentMonitorDevice'])) {
    try {
        foreach(['Intake', 'Supply'] as $location) {
            $temp = $gm->command($param['equipmentMonitorDevice'], 'getTemp' . $location);
            if (!preg_match('%^[\d\.]{3,}$%', $temp)) {
                continue;
            }
            $log['sensorData']['equipment'][$location.'Temp'] = $temp;
        }
    } catch (Exception $e) {
        // empty on purpose
    }
  }
  file_put_contents(getRequiredEnv('OBSERVER_LOG_FILE'), json_encode($log) . "\n", FILE_APPEND);
}
