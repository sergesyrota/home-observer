<?php

/*****
  Intent: run on cron every few minutes to log current state from all home thermostats and temperature sensors

  Devices are identified by (Serial Number, humanType) instead of Homebridge's uniqueId, because uniqueId
  is derived from Homebridge's internal cached state and changes whenever the Homebridge host restarts /
  re-pairs accessories. Serial Number is a physical property of the device and never changes; humanType
  (e.g. "Thermostat", "Fan", "Switch", "Temperature Sensor") is derived from the HAP service UUID rather
  than a user-editable label, and disambiguates the multiple accessories a single physical device (like a
  Nest thermostat) exposes under that same serial number.

  Run `php temperatures.php --discover` to print every currently-known (Serial Number, humanType, Name)
  combination from Homebridge, to help populate/update $sensorsList below after re-pairing hardware.
*****/

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/vendor/autoload.php';

use \SyrotaAutomation\Gearman;

$bridge = new Homebridge();

if (in_array('--discover', $argv)) {
  foreach ($bridge->getAccessories() as $accessory) {
    printf(
      "%-20s | %-20s | %-30s | %s\n",
      $accessory['accessoryInformation']['Serial Number'],
      $accessory['humanType'],
      $accessory['serviceName'],
      $accessory['accessoryInformation']['Name']
    );
  }
  exit;
}

$gm = new Gearman('rs485');

$sensorsList = [
  [ // Master bedroom thermostat
    'serial' => '15AA01AC31170BZ1',
    'type' => 'Thermostat',
    'fanType' => 'Fan',
  ],
  [ // Sam/Office thermostat
    'serial' => '15AA01AC47170LNG',
    'type' => 'Thermostat',
    'fanType' => 'Fan',
  ],
  [ // Leah/Kids thermostat
    'serial' => '15AA01AC391708P6',
    'type' => 'Thermostat',
    'fanType' => 'Fan',
    'equipmentMonitorVacuumDevice' => 'BrAcSens', // Temporary hack for bedrooms
  ],
  [ // Dining room thermostat (shows currently selected temp sensor)
    'serial' => '09AF01AF16210CA8',
    'type' => 'Thermostat',
    'fanType' => 'Fan',
    'equipmentMonitorDevice' => 'BrAcSens',
  ],
  [ // Dining room temperature (separate from the thermostat)
    'serial' => '09AF01AF16210CA8', // same physical unit/serial as the dining room thermostat above
    'type' => 'Temperature Sensor',
  ],
  [ // Family room temperature
    'serial' => '22AA01AC042106QX',
    'type' => 'Temperature Sensor',
  ],
  [ // Hapsfield Living room
    'serial' => '09AF01AF192108YJ',
    'type' => 'Thermostat',
    'fanType' => 'Fan',
  ],
];

$accessoryIndex = [];
foreach ($bridge->getAccessories() as $accessory) {
  $serial = $accessory['accessoryInformation']['Serial Number'];
  $accessoryIndex[$serial][$accessory['humanType']] = $accessory;
}

function findAccessory($accessoryIndex, $serial, $type) {
  if (empty($accessoryIndex[$serial][$type])) {
    throw new Exception("No Homebridge accessory found for serial '$serial' / type '$type'");
  }
  return $accessoryIndex[$serial][$type];
}

foreach ($sensorsList as $sensor) {
  $data = findAccessory($accessoryIndex, $sensor['serial'], $sensor['type']);
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
  if (!empty($sensor['fanType'])) {
    $fanData = findAccessory($accessoryIndex, $sensor['serial'], $sensor['fanType']);
    $log['sensorData']['fan'] = $fanData['values'];
  }
  if (!empty($sensor['equipmentMonitorDevice'])) {
    try {
        foreach(['Intake', 'Supply'] as $location) {
            $temp = $gm->command($sensor['equipmentMonitorDevice'], 'getTemp' . $location);
            if (!preg_match('%^[\d\.]{3,}$%', $temp)) {
                continue;
            }
            $log['sensorData']['equipment'][$location.'Temperature'] = floatval($temp);
        }
    } catch (Exception $e) {
        // empty on purpose
    }
  }
  if (!empty($sensor['equipmentMonitorVacuumDevice'])) {
    try {
        $temp = $gm->command($sensor['equipmentMonitorVacuumDevice'], 'getTempVacuum');
        if (preg_match('%^[\d\.]{3,}$%', $temp)) {
            $log['sensorData']['equipment']['SupplyTemperature'] = floatval($temp);
        }
    } catch (Exception $e) {
        // empty on purpose
    }
  }
  file_put_contents(getRequiredEnv('OBSERVER_LOG_FILE'), json_encode($log) . "\n", FILE_APPEND);
}
