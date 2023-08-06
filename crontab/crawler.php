<?php

// Stop crawler on cli running
$semaphore = sem_get(crc32('yggstate.cli.yggstate'), 1);

if (false === sem_acquire($semaphore, true)) {

  exit (PHP_EOL . 'yggstate.cli.yggstate process running in another thread.' . PHP_EOL);
}

// Lock multi-thread execution
$semaphore = sem_get(crc32('yggstate.crontab.crawler'), 1);

if (false === sem_acquire($semaphore, true)) {

  exit (PHP_EOL . 'yggstate.crontab.crawler process locked by another thread.' . PHP_EOL);
}

// Load system dependencies
require_once(__DIR__ . '/../config/app.php');
require_once(__DIR__ . '/../library/yggdrasil.php');
require_once(__DIR__ . '/../library/mysql.php');
require_once(__DIR__ . '/../library/url.php');

// Check disk quota
if (CRAWL_STOP_DISK_QUOTA_MB_LEFT > disk_free_space('/') / 1000000) {

  exit (PHP_EOL . 'Disk quota reached.' . PHP_EOL);
}

// Init Debug
$debug = [
  'time' => [
    'ISO8601' => date('c'),
    'total'   => microtime(true),
  ],
  'yggdrasil' => [
    'peer' => [
      'total' => [
        'online' => 0,
        'insert' => 0,
      ],
      'remote' => [
        'total' => [
          'insert' => 0,
          'update' => 0,
        ]
      ]
    ]
  ]
];

// Connect database
try {

  $db = new MySQL(DB_HOST, DB_PORT, DB_NAME, DB_USERNAME, DB_PASSWORD);

} catch(Exception $e) {

  var_dump($e);

  exit;
}

// Collect connected peers
if ($connectedPeers = Yggdrasil::getPeers()) {

  foreach ($connectedPeers as $connectedPeerAddress => $connectedPeerInfo) {

    try {

      $db->beginTransaction();

      if ($dbPeer = $db->findPeer($connectedPeerAddress)) {

        $dbPeerId = $dbPeer->peerId;

      } else {

        $dbPeerId = $db->addPeer($connectedPeerAddress, $connectedPeerInfo->key, time());

        if ($connectedPeerRemoteUrl = URL::parse($connectedPeerInfo->remote)) {

          if ($dbPeerRemote = $db->findPeerRemote($dbPeerId,
                                                  $connectedPeerRemoteUrl->host->scheme,
                                                  $connectedPeerRemoteUrl->host->name,
                                                  $connectedPeerRemoteUrl->host->port)) {

            // Update connection stats
            if ($dbPeerRemote->received < $connectedPeerInfo->bytes_recvd) {

              $debug['yggdrasil']['peer']['remote']['total']['update'] +=
              $db->updatePeerRemoteReceived($dbPeerRemote->dbPeerRemoteId, $connectedPeerInfo->bytes_recvd, time());

            }

            if ($dbPeerRemote->sent < $connectedPeerInfo->bytes_sent) {

              $debug['yggdrasil']['peer']['remote']['total']['update'] +=
              $db->updatePeerRemoteSent($dbPeerRemote->dbPeerRemoteId, $connectedPeerInfo->bytes_sent, time());
            }

            if ($dbPeerRemote->uptime < $connectedPeerInfo->uptime) {

              $debug['yggdrasil']['peer']['remote']['total']['update'] +=
              $db->updatePeerRemoteUptime($dbPeerRemote->dbPeerRemoteId, $connectedPeerInfo->uptime, time());
            }

          } else {

            $peerRemoteId = $db->addPeerRemote($dbPeerId,
                                               $connectedPeerRemoteUrl->host->scheme,
                                               $connectedPeerRemoteUrl->host->name,
                                               $connectedPeerRemoteUrl->host->port,
                                               $connectedPeerInfo->bytes_recvd,
                                               $connectedPeerInfo->bytes_sent,
                                               $connectedPeerInfo->uptime,
                                               time());

            $debug['yggdrasil']['peer']['remote']['total']['insert']++;
          }
        }

        $debug['yggdrasil']['peer']['total']['insert']++;
      }

      $debug['yggdrasil']['peer']['total']['online']++;

      $db->commit();

    } catch(Exception $e) {

      $db->rollBack();

      var_dump($e);

      break;
    }
  }
}

// Debug output
$debug['time']['total'] = microtime(true) - $debug['time']['total'];

print_r(
  array_merge($debug, [
    'db' => [
      'total' => [
        'select' => $db->getDebug()->query->select->total,
        'insert' => $db->getDebug()->query->insert->total,
        'update' => $db->getDebug()->query->update->total,
        'delete' => $db->getDebug()->query->delete->total,
      ]
    ]
  ])
);