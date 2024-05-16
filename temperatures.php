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
  '7fef05bd8517dfda9de22ab331fa6a5a6551b6a023d8f336857ad85ad5f9b094' => [ // Master bedroom thermostat
    'fanId' => 'ffeea03e649e62f35664e2bc85cf6bcfd70773c4d8fc507a8ed4e5484d75367f'
  ],
  'bc665a815cbb2861581a6a1df5b68379d3b144b2f6ddd97689b34084eaeaf549' => [ // Sam/Office thermostat
    'fanId' => 'b3154dc5ad8ab74b250c107370ab20943f6d35e6cc68c7f030f45fbe01783f29'
  ],
  'f34c9dec25b46770dbb6a4a1fb421dcf5a3ed99240e1bb018ab55364cde1efc2' => [ // Leah/Kids thermostat
    'fanId' => '48fab439c600c8ecb7f8ca20c50b2140a0c7ef20e3aedb75102ef5ec93c7a8f5'
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

// Todo, change how this is done
//$homebridgeAcessories = $bridge->getAccessories();

foreach ($sensorsList as $id => $param) {
  $data = $bridge->getAccessory($id);
  $log = [
    '@timestamp' => date('c'),
    'dataSource' => 'Homebridge',
    'message' => '(see sensorData.*)',
    'sensorData' => array_merge(
      [
        'name' => $data['accessoryInformation']['Name'] . ' (' . $data['humanType'] . ')',
        'serialNumber' => $data['accessoryInformation']['Serial Number'],
      ],
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
            $log['sensorData']['equipment'][$location.'Temperature'] = floatval($temp);
        }
    } catch (Exception $e) {
        // empty on purpose
    }
  }
  file_put_contents(getRequiredEnv('OBSERVER_LOG_FILE'), json_encode($log) . "\n", FILE_APPEND);
}

function getDevicesBySerial($serialNumber, $payload) {
    $deviceList = [];
    foreach ($payload as $device) {
        if ($device['accessoryInformation']['Serial Number'] == $serialNumber) {
            $deviceList[$device['serviceName']] = $device['uniqueId'];
        }
    }
    return $deviceList;
}
