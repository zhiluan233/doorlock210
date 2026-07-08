<?php
namespace anim210System;

require_once __DIR__ . '/../vendor/autoload.php'; // 确保引入 phpMQTT

use Bluerhinos\phpMQTT;

class MqttClient {
  private $mqtt;
  private $connected = false;

  public function __construct($host, $port, $clientId, $username = '', $password = '', $keepalive = 60) {
    $this->mqtt = new phpMQTT($host, $port, $clientId);
    $this->connected = $this->mqtt->connect(true, NULL, $username, $password, $keepalive);
    if (!$this->connected) throw new \Exception("MQTT connect failed");
  }
  public function publish($topic, $payload, $qos = 1, $retain = 0) {
    return $this->mqtt->publish($topic, $payload, $qos, $retain);
  }
  public function subscribe($topics) {
    $this->mqtt->subscribe($topics, 0);
  }
  public function loop() {
    return $this->mqtt->proc();
  }
  public function close() {
    if ($this->connected) $this->mqtt->close();
  }
}
