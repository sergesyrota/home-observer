<?php

require_once __DIR__ . '/lib.php';

// CRON setup and re-run protection
$pidFile = getRequiredEnv('OBSERVER_PID_FILE');
if (!is_writeable(dirname($pidFile))) {
    throw new Exception("PID file should be in writeable folder");
}

// Exit if the process is running.
if (isProcessRunning($pidFile)) {
    exit(0);
}

file_put_contents($pidFile, posix_getpid());
function removePidFile() {
    unlink(getRequiredEnv('OBSERVER_PID_FILE'));
}
register_shutdown_function('removePidFile');
// END CRON setup

require_once '/var/www/home/dashboard/include/rs485.php';
$rs = new rs485;

$start = time();

$knownState = [
    'garage' => '',
];

while (true) {
    touch($pidFile);

    checkGarage($knownState['garage']);

    if ((time() - $start) > 3600*24*7) {
        // Reboot after a week, just in case
        exit();
    }

    sleep(1);
}

function checkGarage(&$oldState) {
    $newState = getGarageDoors();
    if ($newState != $oldState) {
        $newStateArray = json_decode($newState, true);
        $oldStateArray = json_decode($oldState, true);
        //echo "Garage door state change: $oldState -> $newState\n";
        $doors = [];
        if (is_array($newStateArray)) {
            foreach ($newStateArray as $door=>$null) {
                $doors[$door] = null;
            }
        }
        if (is_array($oldStateArray)) {
            foreach ($oldStateArray as $door=>$null) {
                $doors[$door] = null;
            }
        }
        foreach ($doors as $door=>$null) {
            $oldStateOfOneDoor = $newStateOfOneDoor = 'UNKNOWN';
            if (isset($oldStateArray[$door])) {
                $oldStateOfOneDoor = ($oldStateArray[$door] == 1 ? 'Open' : 'Closed');
            }
            if (isset($newStateArray[$door])) {
                $newStateOfOneDoor = ($newStateArray[$door] == 1 ? 'Open' : 'Closed');
            }
            if ($oldStateOfOneDoor != $newStateOfOneDoor) {
                $logMessage = json_encode([
                    '@timestamp' => date('c'),
                    'room' => 'garage',
                    'door' => $door,
                    'oldState' => $oldStateOfOneDoor,
                    'newState' => $newStateOfOneDoor,
                    'message' => "Garage ($door) door went from $oldStateOfOneDoor to $newStateOfOneDoor",
                ]) . "\n";
                file_put_contents(getRequiredEnv('OBSERVER_LOG_FILE'), $logMessage, FILE_APPEND);
            }
        }
        $oldState = $newState;
    }
}

function isProcessRunning($pidFile) {
    if (!file_exists($pidFile) || !is_file($pidFile)) return false;
    $pid = file_get_contents($pidFile);
    // Check if process is dead
    if (time() - filemtime($pidFile) > 300) {
        posix_kill($pid, SIGKILL);
        return false;
    }
    return posix_kill($pid, 0);
}


function getGarageDoors($retry=3) {
    $data = tryCmd('GarageSens', 'getDoors', $retry);
    return $data;
}

// Makes a few attempts to get results from RS485;
function tryCmd($device, $command, $attempts=3) {
    $rs = new rs485();
    $lastException = new Exception('Unknown error?');
    for ($i=0; $i<$attempts; $i++) {
        try {
            $out = $rs->command($device, $command);
            return $out;
        } catch(Exception $e) {
            $lastException = $e;
        }
    }
    throw $lastException;
}
