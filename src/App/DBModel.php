<?php

namespace Fend\App;

use Fend\Read;
use Fend\Write;

/**
 * SQL Model继承类
 * 默认提供常见增删改查函数
 * 有特殊需要可继承封装
 * 自带查询及单条数据cache
 * 注意：如果使用非本model内函数操作数据会导致Cache自动更新失效
 * 注意：非本Model内函数操作，更新数据后，Cache会在指定时间内失效后更新
 * 注意：本cache适合写少读多场景，条件查询cache命中率较低，但可以缓解很多请求
 * 注意：如果不计划使用Cache功能，建议继承DBNCModel更高效
 *
 * Class DBModel
 * @package Fend\App
 * @property \Fend\Read $readModel
 * @property \Fend\Write $writeModel
 */
class DBModel extends \Fend\Fend
{

    //string 数据库表名, 继承根据需要覆盖
    protected $_table = 'user';

    // string 数据库配置名称, 继承根据需要覆盖
    protected $_db = 'default';

    // 数据库驱动，可选Mysql和MysqlPDO
    protected $_driver = 'Mysql';

    //cache key 前缀, 继承根据需要覆盖
    protected $_cachePrefix = "tc";

    //数据库表的字段列表，用于筛选插入及更新数据, 继承根据需要覆盖
    protected $fieldList = [];

    //bool $_preapare 是否开启prepare查询
    protected $_prepare = true;

    //根据事务自动切换读写
    protected $_autoRWSwitch = true;

    /////////////////数据cache

    //是否开启单条数据cache 继承根据需要覆盖
    protected $_cacheSingleData = false;

    //是否开启where数据Cache 继承根据需要覆盖
    protected $_cacheSearchData = false;

    //默认cache时间, 继承根据需要覆盖
    protected $_cacheExpire = 60;

    //强制使用写model操作所有动作
    protected $_forceWrite = false;

    /**
     * 用于缓存数据的cache驱动
     * @var \Redis|\Memcached
     */
    protected $_cache = null;

    /**
     * 用于查询的链接
     * @var \Fend\Read
     */
    protected $_readModel = null;

    /**
     * 用于数据更改的链接
     * @var \Fend\Write
     */
    protected $_writeModel = null;

    /**
     * 单例工厂模式
     * @return static
     * @throws \Exception
     */
    public static function factory()
    {
        static $obj = null;

        return $obj = $obj ?? new static;
    }

    /**
     * DBModel constructor.
     */
    public function __construct()
    {
    }

    ////////////
    /// Cache
    ////////////
    /**
     * get cache key prefix for this model
     * @return string
     */
    public function getCacheKeyPrefix()
    {
        return $this->_cachePrefix . "_" . $this->_db . "_" . $this->_table;
    }

    /**
     * version incr
     * @return int
     */
    protected function incrVersion()
    {
        $ret = $this->_cache->incr($this->getCacheKeyPrefix() . "_" . "ver");
        return $ret;
    }

    /**
     * get version
     * @return bool|int|mixed|string
     */
    private function getVersion()
    {
        $ver = $this->_cache->get($this->getCacheKeyPrefix() . "_" . "ver");
        return $ver > 0 ? $ver : 0;
    }

    /**
     * cache with version
     * @param string $key cache key
     * @param string|array $data
     * @param int $expire
     * @return bool
     */
    protected function setVersionCache($key, $data, $expire = 0)
    {
        if ($expire == 0) {
            $expire = $this->_cacheExpire;
        }

        $data = [
            "ver" => $this->getVersion(),
            "data" => $data
        ];

        return $this->_cache->set($this->getCacheKeyPrefix() . "_" . $key, json_encode($data), $expire);
    }

    /**
     * get version cache
     * @param string $key
     * @return bool|array|string
     */
    protected function getVersionCache($key)
    {

        $ver = $this->getVersion();
        $data = json_decode($this->_cache->get($this->getCacheKeyPrefix() . "_" . $key), true);

        if (isset($data["ver"]) && $data["ver"] == $ver) {
            return $data["data"];
        }
        return false;

    }

    /**
     * set cache
     * @param string $key
     * @param string|array $data
     * @param int $expire second
     * @return bool
     */
    protected function setSingleCache($key, $data, $expire = 0)
    {
        if ($expire == 0) {
            $expire = $this->_cacheExpire;
        }
        if (!empty($data)) {
            return $this->_cache->set($this->getCacheKeyPrefix() . "_" . $key, json_encode($data), $expire);
        } else {
            return $this->_cache->set($this->getCacheKeyPrefix() . "_" . $key, "", $expire);
        }
    }

    /**
     * get cache
     * @param string $key
     * @return bool|mixed|string
     */
    protected function getSingleCache($key)
    {
        return $this->_cache->get($this->getCacheKeyPrefix() . "_" . $key);
    }


    /**
     * 设置cache时间
     * 如果是临时更改记得改回
     * @param $time
     */
    public function setCacheTime($time)
    {
        $this->_cacheExpire = $time;
    }

    ////////////////////
    /// filed white list
    ///////////////////
    /**
     * 设置字段列表
     * 用于过滤update及insert数据
     * @param array $fieldArray
     */
    public function setFieldList($fieldArray)
    {
        $this->fieldList = $fieldArray;
    }

    /**
     * 根据字段列表过滤Insert或Update输入数据
     * 字段白名单内不存在的字段会被过滤掉
     * 如果字段列表没有设置，那么不做任何加工
     * @param $data
     * @return array
     * @throws \Exception 参数类型错误
     */
    public function filterFieldData($data)
    {
        $result = [];

        //如果没有设置字段列表，那么不做任何过滤
        if (empty($this->fieldList)) {
            return $data;
        }

        foreach ($this->fieldList as $key => $val) {
            //intval()|floatval()|doubleval() wrong on type array|object
            if (is_array($val) || is_object($val)) {
                throw new \Exception("录入参数不支持Array类型", -9853);
            }

            //修正NULL过滤
            if(isset($data[$key]) && $data[$key] === NULL ) {
                $result[$key] = NULL;
                continue;
            }

            //过滤参数及转换
            if ($val == "int" && isset($data[$key])) {
                $result[$key] = intval($data[$key]);
            } elseif ($val == "float" && isset($data[$key])) {
                $result[$key] = floatval($data[$key]);
            } elseif ($val == "double" && isset($data[$key])) {
                $result[$key] = doubleval($data[$key]);
            } elseif ($val == "string" && isset($data[$key])) {
                $result[$key] = $data[$key];
            }
        }

        return $result;
    }

    /**
     * 强迫所有操作动作都在写库进行, 用后记得关闭
     * @param bool $enable
     */
    public function forceWrite($enable = true)
    {
        $this->_forceWrite = $enable;
    }

    /**
     * 获取write、read 懒加载
     * @param string $k
     * @return mixed
     */
    public function __get($k)
    {
        if ($k === "writeModel") {
            if ($this->_writeModel === null) {
                $this->_writeModel = Write::Factory($this->_table, $this->_db, $this->_driver);
            }
            return $this->_writeModel;
        }

        if ($k === "readModel") {
            if ($this->_readModel === null) {
                $this->_readModel = Read::Factory($this->_table, $this->_db, $this->_driver);
            }
            return $this->_readModel;
        }

        return parent::__get($k);
    }

    /**
     * 根据操作类型，获取操作model，如果开启forceWrite那么持续返回写model，如在事务中自动返回写model
     * @param bool $isWrite
     * @return Read|Write
     */
    public function getModel($isWrite = false)
    {
        if($isWrite || $this->_forceWrite || $this->_autoRWSwitch && $this->writeModel->getTransactionStatus()) {
            return $this->writeModel;
        } else {
            return $this->readModel;
        }
    }

    ////////////////
    /// ADUQ
    ///////////////
    /**
     * 添加一条数据
     * @param array $data 传入要添加数据的kv
     * @return bool|int
     * @throws \Exception SQL错误，链接失败
     */
    public function add($data)
    {
        //filter data by field list
        $data = $this->filterFieldData($data);

        if (empty($data)) {
            return false;
        }

        $ret = $this->getModel(true)->add($data, $this->_prepare);

        if ($ret) {
            $this->_cacheSearchData && $this->incrVersion();
            $this->_cacheSingleData && $this->setSingleCache("info_" . $ret,
                $this->getModel(true)->getById($ret, [], $this->_prepare));
        }
        return $ret;
    }

    /**
     * 多条数据一次插入，没有做单条数据cache
     * @param array(array()) $data 二维数组
     * @return false|int
     * @throws \Exception
     */
    public function addMulti($data)
    {
        foreach ($data as $k => $item) {
            //filter data by field list
            $data[$k] = $this->filterFieldData($item);
        }

        $ret = $this->getModel(true)->addMulti($data, $this->_prepare);

        if ($ret) {
            $this->_cacheSearchData && $this->incrVersion();
        }
        return $ret;
    }

    /**
     * 更新符合条件数据
     * @param array|string $condition
     * @param array $data 更新的数据
     * @return bool|int
     * @throws \Exception SQL错误，链接失败
     */
    public function updateByCondition($condition, $data)
    {
        //filter data by field list
        $data = $this->filterFieldData($data);

        //拒绝扫全库或无更新项
        if (empty($condition) || empty($data)) {
            return false;
        }

        //update the cache
        if ($this->_cacheSearchData || $this->_cacheSingleData) {

            //fetch list of id
            $idList = $this->getModel(true)->getListByCondition($condition, "id", 0, 0, "", $this->_prepare);
            $idArr = [];
            foreach ($idList as $item) {
                $idArr[] = $item["id"];
            }

            //update by condition
            $ret = $this->getModel(true)->edit($condition, $data, $this->_prepare);

            if ($ret) {
                //refresh cache
                $list = $this->getModel(true)->getListByWhere([["id", "in", $idArr]], "", 0, 0, "", $this->_prepare);
                foreach ($list as $item) {
                    $this->setSingleCache("info_" . $item["id"], $item);
                }

                $this->_cacheSearchData && $this->incrVersion();
            }

            return $ret;

        } else {
            //just do it
            return $this->getModel(true)->edit($condition, $data, $this->_prepare);
        }

    }

    /**
     * 更新符合条件数据
     * 注意：where结尾的函数使用的是新where规则
     * @param array|string $where
     * @param array $data 更新的数据
     * @return bool|int
     * @throws \Exception SQL错误，链接失败
     */
    public function updateByWhere($where, $data)
    {
        //filter data by field list
        $data = $this->filterFieldData($data);

        if (empty($where) || empty($data)) {
            return false;
        }

        //update the cache
        if ($this->_cacheSearchData || $this->_cacheSingleData) {

            //fetch list of id
            $idList = $this->getModel(true)->getListByWhere($where, "id", 0, 0, "", $this->_prepare);
            $idArr = [];
            foreach ($idList as $item) {
                $idArr[] = $item["id"];
            }

            //update by condition
            $ret = $this->getModel(true)->editByWhere($where, $data, $this->_prepare);

            if ($ret) {
                //refresh cache
                $list = $this->getModel(true)->getListByWhere([["id", "in", $idArr]], "", 0, 0, "", $this->_prepare);
                foreach ($list as $item) {
                    $this->setSingleCache("info_" . $item["id"], $item);
                }

                $this->_cacheSearchData && $this->incrVersion();
            }

            return $ret;

        } else {
            //just do it
            return $this->getModel(true)->editByWhere($where, $data, $this->_prepare);
        }

    }

    /**
     * 更新指定id数据
     * @param int $id
     * @param array $data
     * @return bool|int
     * @throws \Exception SQL错误，链接失败
     */
    public function updateById($id, $data)
    {
        //filter data by field list
        $data = $this->filterFieldData($data);

        if (empty($id) || empty($data)) {
            return false;
        }

        //update for the cache
        if ($this->_cacheSearchData || $this->_cacheSingleData) {

            //update by condition
            $ret = $this->getModel(true)->editById($id, $data, $this->_prepare);

            if ($ret) {
                //refresh cache
                $this->setSingleCache("info_" . $id, $this->getModel(true)->getById($id, [], $this->_prepare));
                $this->_cacheSearchData && $this->incrVersion();
            }

            return $ret;

        } else {
            //just do it
            return $this->getModel(true)->editById($id, $data, $this->_prepare);
        }
    }

    /**
     * 删除符合条件的数据
     * @param array|string $condition
     * @return bool|int
     * @throws \Exception SQL错误，链接失败
     */
    public function delByCondition($condition)
    {
        if (empty($condition)) {
            return false;
        }

        //del for the cache
        if ($this->_cacheSearchData || $this->_cacheSingleData) {

            //fetch list of id
            $idList = $this->getModel(true)->getListByCondition($condition, "id", 0, 0, "", $this->_prepare);
            $idArr = [];
            foreach ($idList as $item) {
                $idArr[] = $item["id"];
            }

            //del by condition
            $ret = $this->getModel(true)->del($condition, $this->_prepare);

            if ($ret) {
                //refresh cache
                foreach ($idArr as $id) {
                    $this->setSingleCache("info_" . $id, null);
                }

                $this->_cacheSearchData && $this->incrVersion();
            }

            return $ret;

        } else {
            //just do it
            return $this->getModel(true)->del($condition, $this->_prepare);
        }

    }

    /**
     * 删除符合条件的数据
     * 注意：where结尾的函数使用的是新where规则
     * @param array|string $where
     * @return bool|int
     * @throws \Exception SQL错误，链接失败
     */
    public function delByWhere($where)
    {
        if (empty($where)) {
            return false;
        }

        //del the cache
        if ($this->_cacheSearchData || $this->_cacheSingleData) {

            //fetch list of id
            $idList = $this->getModel(true)->getListByWhere($where, "id", 0, 0, "", $this->_prepare);
            $idArr = [];
            foreach ($idList as $item) {
                $idArr[] = $item["id"];
            }

            //del by where
            $ret = $this->getModel(true)->delByWhere($where, $this->_prepare);

            if ($ret) {
                //refresh cache
                foreach ($idArr as $item) {
                    $this->setSingleCache("info_" . $item, null);
                }

                $this->_cacheSearchData && $this->incrVersion();
            }

            return $ret;

        } else {
            //just do it
            return $this->getModel(true)->delByWhere($where, $this->_prepare);
        }
    }

    /**
     * 删除指定id数据
     * @param string $id
     * @return bool|int
     * @throws \Exception
     */
    public function delById($id)
    {
        if (empty($id)) {
            return false;
        }

        //update for the cache
        if ($this->_cacheSearchData || $this->_cacheSingleData) {

            //update by condition
            $ret = $this->getModel(true)->delById($id, $this->_prepare);

            if ($ret) {
                //refresh cache
                $this->setSingleCache("info_" . $id, null);
                $this->_cacheSearchData && $this->incrVersion();
            }

            return $ret;

        } else {
            //just do it
            return $this->getModel(true)->delById($id, $this->_prepare);
        }
    }


    /**
     * 根据id获取一条数据
     * @param string $id 数据id
     * @param array|string $field 字段列表,cache模式下设置这个cache会失效
     * @param bool $cache 是否从cache读取，默认开启
     * @return array|bool 成功返回结果，失败返回false
     * @throws \Exception 链接异常,SQL异常
     */
    public function getInfoById($id, $field = array(), $cache = true)
    {
        if (empty($id)) {
            return false;
        }

        if ($cache && $this->_cacheSingleData && empty($field)) {
            $result = $this->getSingleCache("info_" . $id);
            if (!empty($result)) {
                return json_decode($result, true);
            }
        }

        $ret = $this->getModel()->getById($id, $field, $this->_prepare);
        if ($ret) {
            if ($cache && $this->_cacheSingleData && empty($field)) {
                $this->setSingleCache("info_" . $id, $ret);
            }
        }
        return $ret;
    }

    /**
     * 根据ID数组，获取一组数据
     * @param array $idArray
     * @param array|string $field
     * @param bool $cache 是否从cache读取，默认开启
     * @return array|bool
     * @throws \Exception 链接异常,SQL异常
     */
    public function getInfoByIdArray($idArray, $field = array(), $cache = true)
    {
        if (empty($idArray)) {
            return false;
        }
        //题外话：本来是想直接读取多条cache
        //但是发现一条条的时间消耗超过了一次查询，特别是id超过20个,改为mget会导致cache吞吐过大卡顿
        //如果用代码先从cache取，然后再从mysql获取剩下没拿到的，消耗特别差

        $key = "idarr_" . md5(implode(",", $idArray) . "|" . implode(",", $field));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }
        $result = $this->getModel()->getByIdArray($idArray, $field, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;
    }

    /**
     * 根据条件查询列表，不返回符合条件数据总数
     * @param array|string $conditions
     * @param array|string $fields
     * @param int $start
     * @param int $limit
     * @param string $order
     * @param bool $cache 是否从cache读取，默认开启
     * @return bool|array
     * @throws \Exception 链接异常,SQL异常
     */
    public function getListByCondition($conditions = array(), $fields = array(), $start = 0, $limit = 20, $order = "", $cache = true)
    {

        $key = "lbc_" . md5(json_encode(array_merge($conditions, [$fields], [$start, $limit])));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }
        $result = $this->getModel()->getListByCondition($conditions, $fields, $start, $limit, $order, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;
    }

    /**
     * 根据条件查询列表，不返回符合条件数据总数
     * 注意：where结尾的函数使用的是新where规则
     * @param array|string $where
     * @param array|string $fields
     * @param int $start
     * @param int $limit
     * @param string $order
     * @param bool $cache 是否从cache读取，默认开启
     * @return bool|array
     * @throws \Exception 链接异常,SQL异常
     */
    public function getListByWhere($where = array(), $fields = array(), $start = 0, $limit = 20, $order = "", $cache = true)
    {
        $key = "lbw_" . md5(json_encode(array_merge($where, [$fields], [$start, $limit])));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }
        $result = $this->getModel()->getListByWhere($where, $fields, $start, $limit, $order, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;
    }

    /**
     * 根据条件获取一条数据
     * @param array|string $condition 查询条件
     * @param array|string $field 列出字段
     * @param bool $cache 是否从cache读取，默认开启
     * @return array|string
     * @throws \Exception 链接异常,SQL异常
     */
    public function getInfoByCondition($condition = array(), $field = array(), $cache = true)
    {
        $key = "gic_" . md5(json_encode(array_merge($condition, [$field])));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }
        $result = $this->getModel()->getInfoByCondition($condition, $field, "", $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;
    }

    /**
     * 根据条件查询一条数据
     * 注意：where结尾的函数使用的是新where规则
     * @param array|string $where
     * @param array|string $field
     * @param string $order
     * @param bool $cache 是否从cache读取，默认开启
     * @return array|string
     * @throws \Exception 链接异常,SQL异常
     */
    public function getInfoByWhere($where = array(), $field = array(), $order = "", $cache = true)
    {
        $key = "giw_" . md5(json_encode(array_merge($where, [$field, $order])));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }
        $result = $this->getModel()->getInfoByWhere($where, $field, $order, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;
    }

    /**
     * 根据条件获取数据列表，并返回符合条件数据个数
     * @param array|string $condition
     * @param int $start
     * @param int $limit
     * @param array|string $field
     * @param string $order
     * @param bool $cache 是否从cache读取，默认开启
     * @return array
     * @throws \Exception 链接异常,SQL异常
     */
    public function getListPageByCondition($condition, $start = 0, $limit = 20, $field = array(), $order = "", $cache = true)
    {
        $key = "glpc_" . md5(json_encode(array_merge($condition, [$start, $limit,$field,$order])));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }
        $result = $this->getModel()->getDataList($condition, $start, $limit, $field, $order, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;
    }

    /**
     * 根据条件获取数据列表，并返回符合条件数据个数
     * 注意：where结尾的函数使用的是新where规则
     * @param array|string $where
     * @param int $start
     * @param int $limit
     * @param array|string $field
     * @param string $order
     * @param bool $cache 是否从cache读取，默认开启
     * @return array
     * @throws \Exception 链接异常,SQL异常
     */
    public function getListPageByWhere($where, $start = 0, $limit = 20, $field = array(), $order = "", $cache = true)
    {
        $key = "glpw_" . md5(json_encode(array_merge($where, [$start, $limit, $field, $order])));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }
        $result = $this->getModel()->getDataListByWhere($where, $start, $limit, $field, $order, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;
    }

    /**
     * 统计符合条件的数据个数
     * @param array|string $condition
     * @param bool $cache 是否从cache读取，默认开启
     * @return string
     * @throws \Exception 链接异常,SQL异常
     */
    public function getCount($condition, $cache = true)
    {
        $key = "gcc_" . md5(json_encode(array_merge($condition)));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }

        $result = $this->getModel()->getCount($condition, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;

    }

    /**
     * 统计符合条件的数据个数
     * 注意：where结尾的函数使用的是新where规则
     * @param $where
     * @param bool $cache 是否从cache读取，默认开启
     * @return string
     * @throws \Exception 链接异常,SQL异常
     */
    public function getCountByWhere($where, $cache = true)
    {
        $key = "gcw_" . md5(json_encode(array_merge($where)));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }

        $result = $this->getModel()->getCountByWhere($where, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;

    }

    /**
     * 根据group分组count，并支持分页
     * @param string $group 分组字段，逗号分割
     * @param array $where where条件
     * @param int $offset 翻页offset
     * @param int $limit 一页数据个数
     * @param string $fields 统计字段，默认是count(*) as total
     * @param string $order 排序
     * @param bool $cache 是否开启cache
     * @return array
     * @throws \Exception
     */
    public function getSumByGroupList($group = "", $where = array(), $offset = 0, $limit = 20, $fields = "count(*) as total", $order = '', $cache = true)
    {
        $key = "gcbgl_" . md5(json_encode(array_merge($where, [$group, $offset, $limit, $fields, $order])));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }

        $result = $this->getModel()->getSumByGroupList($group, $where, $offset, $limit, $fields, $order,
            $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;

    }

    /**
     * 根据group分组count，并支持分页
     * @param string $group 分组字段，逗号分割
     * @param array $where where条件
     * @param int $offset 翻页offset
     * @param int $limit 一页数据个数
     * @param string $fields 统计字段，默认是count(*) as total
     * @param string $order 排序
     * @param bool $cache 是否开启cache
     * @return array
     * @throws \Exception
     */
    public function getSumByGroup($group = "", $where = array(), $offset = 0, $limit = 20, $fields = "count(*) as total", $order = '', $cache = true)
    {
        $key = "gcbg_" . md5(json_encode(array_merge($where, [$group, $offset, $limit, $fields, $order])));

        if ($cache && $this->_cacheSearchData) {
            $result = $this->getVersionCache($key);
            if ($result) {
                return $result;
            }
        }

        $result = $this->getModel()->getSumByGroup($group, $where, $offset, $limit, $fields, $order, $this->_prepare);

        if ($result && $cache && $this->_cacheSearchData) {
            $this->setVersionCache($key, $result);
        }
        return $result;

    }

    /**
     * left join 并返回列表
     * @param string $right_table 右表名称
     * @param array $on left join条件 如:["id" => "id"] key会增加左表前缀，val会加右表前缀
     * @param array $where where条件 [["user",6],["sex",">=",3]]
     * @param string|array $fields 返回结果字段列表 如 "DISTINCT xes_collect_user.content_id,xes_collect_user.id"
     * @param int $offset 开始返回数据offset
     * @param int $limit 最大返回数据条数
     * @param string $order 如 "id desc,user_id asc"
     * @return array
     * @throws \Exception
     */
    public function getLeftJoinListByWhere($right_table, $on, $where = array(), $fields = array(), $offset = 0, $limit = 20, $order = "")
    {
        return $this->getModel()->getLeftJoinListByWhere($right_table, $on, $where, $fields, $offset, $limit, $order, $this->_prepare);
    }

    /**
     * left join 并返回符合条件数据总个数
     * @param string $right_table 右表名称
     * @param array $on left join条件 如:["id" => "id"] key会增加左表前缀，val会加右表前缀
     * @param array $where where条件 [["user",6],["sex",">=",3]]
     * @return int
     * @throws \Exception
     */
    public function getLeftJoinCountByWhere($right_table, $on, $where = array())
    {
        return $this->getModel()->getLeftJoinCountByWhere($right_table, $on, $where, $this->_prepare);
    }

    /**
     * left join Group having 返回列表
     * @param string|array $fields 返回结果字段列表 如 "DISTINCT xes_collect_user.content_id,xes_collect_user.id"
     * @param string $right_table 右表名称
     * @param array $on left join条件 如:["id" => "id"] key会增加左表前缀，val会加右表前缀
     * @param array $where where条件 [["user",6],["sex",">=",3]]
     * @param string $group 分组字段,隔开
     * @param array $having group having where
     * @param int $offset 开始返回数据offset
     * @param int $limit 最大返回数据条数
     * @param string $order 如 "id desc,user_id asc"
     * @return array
     * @throws \Exception
     */
    public function getLeftJoinGroupHavingListByWhere($fields, $right_table, $on, $where = array(),
        $group = "", $having = [], $offset = 0, $limit = 20 , $order = "")
    {
        return $this->getModel()->getLeftJoinGroupHavingListByWhere($fields, $right_table, $on, $where,
            $group, $having, $offset, $limit, $order, $this->_prepare);
    }

    /**
     * 获取最后一次执行的SQL
     * @param bool $isWrite 由于读写分离，需要知道要获取读还是写的SQL
     * @return array
     */
    public function getLastSQL($isWrite = false)
    {
        if ($isWrite) {
            return $this->getModel(true)->getLastSQL();
        } else {
            return $this->getModel()->getLastSQL();
        }
    }

    /**
     * 获取最后一次insertId
     * @return int|bool
     */
    public function getLastInsertId()
    {
        return $this->getModel(true)->getLastId();
    }

    /**
     * 获取最后一次执行影响数据个数
     * @return int
     */
    public function getAffectRow()
    {
        return $this->getModel(true)->afrows();
    }

    /**
     * 开启事务
     * @return bool
     */
    public function transaction()
    {
        return $this->getModel(true)->trans_begin();
    }

    /**
     * 提交事务
     * @return bool
     */
    public function commit()
    {
        return $this->getModel(true)->trans_commit();
    }

    /**
     * 回滚事务
     * @return bool
     */
    public function rollBack()
    {
        return $this->getModel(true)->trans_rollback();
    }

    /**
     * 开启prepare开关，开启后所有SQL操作都会使用Prepare预处理
     * @param bool $open 传递false会关闭
     */
    public function openPrepare($open = true)
    {
        $this->_prepare = $open;
    }

    /**
     * 闭包执行事务
     * 闭包函数内抛出异常会自动回滚事务，否则自动提交事务并将闭包函数的返回值返回
     * ```
     * $result = $db->transaction(function($db) {
     *       // 业务逻辑处理
     *  return true;
     * });
     * var_dump($result); // true
     * ```
     * @param callable $callable
     * @return mixed
     * @throws \Throwable
     * @author xialeistudio
     * @date 2019-09-05
     */
    public function transactionCallback(callable $callable)
    {
        return $this->getModel(true)->getModule()->transaction($callable);
    }

    /**
     * 获取当前表名
     * @return string
     */
    public function getTableName()
    {
        return $this->_table;
    }
}
