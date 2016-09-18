<?php
namespace Isutar\Web;

use Slim\Http\Request;
use Slim\Http\Response;
use PDO;
use PDOWrapper;

$container = new class extends \Slim\Container {
    public $dbh;
    public function __construct() {
        parent::__construct();

        $this->dbh = new PDOWrapper(new PDO(
            $_ENV['ISUTAR_DSN'],
            $_ENV['ISUTAR_DB_USER'] ?? 'isucon',
            $_ENV['ISUTAR_DB_PASSWORD'] ?? 'isucon',
            [ PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]
        ));
    }
};
$app = new \Slim\App($container);

$app->run();
