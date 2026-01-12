<?php

namespace Flm;


use CachedEcho;
use FileUtil;
use ReflectionMethod;
use RuntimeException;
use Throwable;

abstract class BaseController
{
    /**
     * @var FileManager
     */
    protected $flm;

    protected $config;

    protected $currentDirectory;

    public function __construct($config)
    {
        global $topDirectory;

        $this->config = $config;
        
        // Use config root if set, else topDirectory, else /tmp
        $rootDir = $this->config['root'] ?? $topDirectory ?? '/tmp';
        error_log("filemanager BaseController: using rootDir = $rootDir");
        
        $this->flm = new FileManager(
            new Filesystem($rootDir),
            $this->config,
            null
        );
        // Set initial workdir to rootDir
        $this->flm->workDir($rootDir);
    }

    public function handleRequest()
    {

        error_log("filemanager debug: _POST: " . json_encode($_POST));
        error_log("filemanager debug: _GET: " . json_encode($_GET));
        error_log("filemanager debug: _SERVER: " . json_encode([
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '',
            'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? '',
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? ''
        ]));
        global $topDirectory;
        error_log("filemanager debug: topDirectory: " . (isset($topDirectory) ? $topDirectory : 'not set'));
        error_log("filemanager debug: config: " . json_encode($this->config));

        // Special-case: allow GET only for a strict allowlist of binary responses
        // (needed for <img src="..."> which cannot POST)
        if (!isset($_POST['action']) && !isset($_POST['cmd']) && isset($_GET['method'])) {
            $allowedGetMethods = ['viewImage'];
            $method = $_GET['method'];
            if (in_array($method, $allowedGetMethods, true)) {
                $call = ['method' => $method];
                if (isset($_GET['target'])) {
                    $call['target'] = $_GET['target'];
                }
            } else {
                self::jsonError('Invalid action');
            }
        } elseif (isset($_POST['action'])) {
            $action = $_POST['action'];

            $call = json_decode($action, true);

            $call = $call ? $call : ['method' => $action];
        } elseif (isset($_POST['cmd'])) {
            $call = $_POST;
        } else {
            self::jsonError('Invalid action');
        }

        try {
            //isset($call['workdir']) && $this->flm->workDir($call['workdir']);
            $out = $this->_processCall((object)$call);

            self::jsonOut($out);

        } catch (Throwable $err) {
            Helper::getConfig("debug") && FileUtil::toLog(__METHOD__ . ' DEBUG Exception ' . var_export([$err->getMessage(), $err->getTraceAsString()], true));
            self::jsonError($err->getCode(), $err->getMessage());
        }

    }

    public static function jsonError($errcode, $msg = 'Internal error')
    {
        self::jsonOut(['errcode' => $errcode, 'msg' => $msg, 'status' => 'error']);
        die();
    }

    public static function jsonOut($data)
    {
        // Avoid CachedEcho which conflicts with output buffering
        // Clear any existing output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo json_encode($data);
        exit;
    }

    /**
     * @param $call
     * @return mixed|null
     * @throws Throwable
     */
    protected function _processCall($call)
    {

        $method = $call->method;

        if ((substr($method, 0, 1) == '_')) {
            throw new RuntimeException("Invalid method");
        }

        unset($call->method);

        $out = null;
        if (method_exists($this, $method)) {
            $reflectionMethod = new ReflectionMethod($this, $method);
            if (!$reflectionMethod->isPublic()) {

                throw new RuntimeException("Invalid method");
            }

            $out = call_user_func_array([$this, $method], [$call]);
        } else {
            throw new RuntimeException("Invalid method");
        }

        return $out;
    }

    public function _getPostData($post_keys, $json = true)
    {
        $ret = [];
        foreach ($post_keys as $key => $err_code) {

            if (!isset($_POST[$key]) || ($json && !($files = json_decode($_POST[$key], true)))) {

                self::jsonError($err_code);
                return false;

            }

            $ret[$key] = $_POST[$key];
        }

        return $ret;

    }

    public function flm(): FileManager
    {
        return $this->flm;
    }
}