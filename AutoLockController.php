<?php

/**
 * AutoLockController class file.
 *
 * @author XuanXin Zhen <xuanxin.zhen@gmail.com>
 * @link https://github.com/zhenxxin/yii2-auto-lock
 * @copyright 2017 by XuanXin Zhen <xuanxin.zhen@gmail.com>
 * @license see MIT license for detail.
 */

namespace zhenxxin\autolock;

use Yii;
use yii\base\Module;
use yii\console\Controller;
use yii\console\Exception;

/**
 * 一个可以自动锁定的命令行程序。
 * 使用方式：
 * 1. 继承本类；
 * 2. 按照 Yii 框架的方式书写你的 actions。
 *
 * @property integer $lockMode 锁定模式
 *
 * @author: XuanXin Zhen <xuanxin.zhen@gmail.com>
 * @since 1.0
 */
class AutoLockController extends Controller
{
    /**
     * 需要被排除的 action 列表
     * @var array
     */
    public $exclude = [];

    /**
     * 需要被包含的 action 列表
     * @var array
     */
    public $include = [];

    /**
     * 锁文件存放目录
     * 默认使用框架的 runtime 目录
     * @var string
     */
    private $runtime;

    /**
     * 锁文件名
     * @var string
     */
    private $lockFilename;

    /**
     * 锁文件
     * @var resource
     */
    private $lockFile;

    /**
     * 锁定模式
     * @var integer
     */
    private $lockMode;

    public function __construct($id, Module $module, array $config = [])
    {
        parent::__construct($id, $module, $config);

        $this->lockMode = LOCK_EX | LOCK_NB;
        $this->runtime = Yii::$app->runtimePath;
    }

    /**
     * 获取当前的锁定模式
     * @return integer
     */
    public function getLockMode()
    {
        return $this->lockMode;
    }

    /**
     * 设置锁定模式
     * @param $value
     * @throws Exception
     */
    public function setLockMode($value)
    {
        if (!is_numeric($value)) {
            $value = intval($value);
        }

        if (!is_integer($value)) {
            $value = intval($value);
        }

        // 检查模式的值范围（暂时没有别的办法检查了？）
        if ($value < (LOCK_NB & LOCK_UN & LOCK_EX & LOCK_SH) ||
            $value > (LOCK_NB | LOCK_UN | LOCK_EX | LOCK_SH)
        ) {
            throw new Exception('不支持的值，请确认。');
        }

        $this->lockMode = $value;
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {
            if (!$this->checkLockRequired($action)) {
                return true;
            }

            return $this->lock($action);
        }

        return parent::beforeAction($action);
    }

    /**
     * @inheritdoc
     */
    public function afterAction($action, $result)
    {
        if ($this->checkLockRequired($action)) {
            return $this->unlock($action);
        }

        return parent::afterAction($action, $result);
    }

    /**
     * 创建锁文件，并锁定
     * @param $action
     * @return bool
     */
    private function lock($action)
    {
        $dir = sprintf('%s/%s', $this->runtime, 'lock');
        if (!file_exists($dir)) {
            mkdir($dir);
        }

        $filename = sprintf('%s/%s-%s.lock', $dir, $this->name, $action);
        $file = fopen($filename, 'c'); // 'c' 相对于 'w' 方式更适合于需要 咨询锁（advisory lock） 的情况
        if (!flock($file, $this->lockMode)) {
            return false;
        }

        // 写入当前进程号，方便 kill
        if (fwrite($file, getmypid())) {
            $this->lockFile = $file;
            $this->lockFilename = $filename;

            return true;
        }

        return false;
    }

    /**
     * 解锁并删除锁文件
     * @param $action
     * @return bool
     */
    private function unlock($action)
    {
        $filename = $this->lockFilename;
        if (!file_exists($filename)) {
            return true;
        }

        return flock($this->lockFile, LOCK_UN) && fclose($this->lockFile) && unlink($filename);
    }

    /**
     * 检查是否需要锁定
     * 当 @property $include 为空时，表示全部需要锁定
     * @param string $action 被检查的 action 名
     * @return bool
     */
    private function checkLockRequired($action)
    {
        $compare = strtolower($action);

        $include = array_map(function ($item) {
            return strtolower($item);
        }, $this->include);

        $exclude = array_map(function ($item) {
            return strtolower($item);
        }, $this->exclude);

        if (empty($include)) {
            return true;
        }

        return !in_array($compare, $exclude) || in_array($compare, $include);
    }
}
