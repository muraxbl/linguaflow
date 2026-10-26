<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Minimal PrestaShop boundary; SQL integration uses only an explicitly supplied disposable server.
error_reporting(E_ALL & ~E_DEPRECATED);
define('_PS_VERSION_', '8.2.1');
define('_DB_PREFIX_', 'test_');
define('_MYSQL_ENGINE_', 'InnoDB');
require dirname(__DIR__) . '/vendor/autoload.php';
if (getenv('LFAI_TEST_PURIFIER_AUTOLOAD')) { require getenv('LFAI_TEST_PURIFIER_AUTOLOAD'); }

class Employee
{
    public $id = 7;
    public $id_profile = 2;
    public $logged = true;
    public $shops = [1, 2];
    public function isLoggedBack() { return $this->logged; }
    public function hasAuthOnShop($id) { return in_array((int) $id, $this->shops, true); }
}
class Shop
{
    public const CONTEXT_SHOP = 1;
    public const CONTEXT_GROUP = 2;
    public const CONTEXT_ALL = 4;
    public static $scope = 1;
    public static $contextId = 1;
    public $id;
    public $active = true;
    public function __construct($id) { $this->id = $id; }
    public static function getContext() { return self::$scope; }
    public static function getContextShopID() { return self::$contextId; }
    public static function getContextShopGroupID() { return self::$scope === self::CONTEXT_GROUP ? 1 : null; }
    public static function setContext($scope, $id = null) { self::$scope = $scope; self::$contextId = $id; }
    public static function getShops($active = true, $group = null, $ids = false) { return $ids ? [1, 2] : [1 => ['id_shop' => 1, 'name' => 'One'], 2 => ['id_shop' => 2, 'name' => 'Two']]; }
}
class Context
{
    public static $instance;
    public $employee;
    public $shop;
    public $language;
    public $controller;
    public static function getContext() {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->employee = new Employee();
            self::$instance->shop = (object) ['id' => 1];
            self::$instance->language = (object) ['id' => 1];
        }
        return self::$instance;
    }
}
class Access
{
    public static $denied = [];
    public static function isGranted($roles, $profile) { return !array_intersect((array) $roles, self::$denied); }
}
class Configuration
{
    public static $values = ['LFAI_PROVIDER' => 'openai_compatible', 'LFAI_API_KEY' => 'audit-fixture-key', 'LFAI_BASE_URL' => 'https://saved.example/v1', 'LFAI_MODEL' => 'fixture', 'LFAI_SOURCE_LANG' => 1, 'LFAI_TARGET_LANGS' => '[2]', 'LFAI_SHOP_SCOPE' => 'context'];
    public static function getGlobalValue($key) { return self::$values[$key] ?? false; }
    public static function updateGlobalValue($key, $value, $html = false) { self::$values[$key] = $value; return true; }
    public static function get($key) { return 1; }
}
class Tools
{
    public static $input = [];
    public static function getValue($key, $default = false) { return self::$input[$key] ?? $default; }
    public static function isSubmit($key) { return isset(self::$input[$key]); }
    public static function toCamelCase($text, $upper = false) { return $text; }
    public static function str2url($value) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value)); }
}
class Module
{
    protected $context;
    public function __construct() { $this->context = Context::getContext(); }
    public function l($text, $specific = null) { return $text; }
}
class ModuleAdminController
{
    public $context;
    public $module;
    public $ajax = false;
    public $permissions = ['view' => true, 'add' => true, 'edit' => true, 'delete' => true];
    public $validToken = true;
    public $errors = [];
    public $confirmations = [];
    public function __construct() { $this->context = Context::getContext(); $this->module = new LinguaFlowAI(); }
    public function access($permission, $disable = false) { return $this->permissions[$permission] ?? false; }
    public function checkToken() { return $this->validToken; }
    public function postProcess() {
        if ($this->ajax) { return $this->{'ajaxProcess' . Tools::getValue('action')}(); }
    }
}
class Language
{
    public $iso_code;
    public $language_code;
    public $name;
    public function __construct($id) { $this->iso_code = $id === 1 ? 'es' : 'en'; $this->language_code = $this->iso_code; $this->name = $id === 1 ? 'Spanish' : 'English'; }
    public static function getLanguage($id) { return in_array($id, [1, 2], true); }
}
class Product
{
    public $id_category_default = 1;
    public $id_manufacturer = 0;
    public $id;
    public $name = 'Fixture product';
    public function __construct($id, $full = false, $lang = null, $shop = null) { $this->id = $id; }
    public static function resetStaticCache() {}
}
class Hook { public static function exec($name, $args = []) {} }
class Cache { public static function clean($key) {} }
class Search { public static $shops = []; public static function indexation($full, $id) { self::$shops[] = (int) Context::getContext()->shop->id; } }
class Validate { public static function isLoadedObject($obj) { return !empty($obj->id); } }
class PrestaShopLogger { public static function addLog(...$args) {} }

class Db
{
    public const INSERT_IGNORE = 2;
    public static $instance;
    public static $queries = 0;
    private $pdo;
    private $affected = 0;
    public function __construct() {
        $socket = getenv('LFAI_TEST_DB_SOCKET');
        if (!$socket) { throw new RuntimeException('Supply LFAI_TEST_DB_SOCKET for a disposable test server.'); }
        $this->pdo = new PDO('mysql:unix_socket=' . $socket . ';dbname=linguaflow_security_tests;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    public static function getInstance() { return self::$instance ?: (self::$instance = new self()); }
    public function escape($value, $html = false) { return substr($this->pdo->quote($html ? $value : strip_tags($value)), 1, -1); }
    public function execute($sql) { ++self::$queries; $this->affected = $this->pdo->exec($sql); return true; }
    public function executeS($sql, $array = true, $cache = true) { ++self::$queries; return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function getRow($sql, $cache = true) { $rows = $this->executeS($sql); return $rows ? $rows[0] : false; }
    public function getValue($sql, $cache = true) { $row = $this->getRow($sql); return $row ? reset($row) : false; }
    public function Affected_Rows() { return $this->affected; }
    public function Insert_ID() { return $this->pdo->lastInsertId(); }
    private function value($value) { return $value === null ? 'NULL' : "'" . $value . "'"; }
    public function insert($table, $data, $null = false, $cache = true, $type = 1) {
        return $this->execute('INSERT ' . ($type === self::INSERT_IGNORE ? 'IGNORE ' : '') . 'INTO `test_' . $table . '` (`' . implode('`,`', array_keys($data)) . '`) VALUES (' . implode(',', array_map([$this, 'value'], array_values($data))) . ')');
    }
    public function update($table, $data, $where, $limit = 0, $null = false) {
        $sets = [];
        foreach ($data as $key => $value) { $sets[] = '`' . $key . '`=' . $this->value($value); }
        return $this->execute('UPDATE `test_' . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . $where);
    }
    public function delete($table, $where) { return $this->execute('DELETE FROM `test_' . $table . '` WHERE ' . $where); }
}
function pSQL($value, $html = false) { return Db::getInstance()->escape((string) $value, $html); }
require dirname(__DIR__) . '/linguaflowai.php';
require dirname(__DIR__) . '/controllers/admin/AdminLinguaFlowAIController.php';
require dirname(__DIR__) . '/controllers/admin/AdminLinguaFlowAIGlossaryController.php';
