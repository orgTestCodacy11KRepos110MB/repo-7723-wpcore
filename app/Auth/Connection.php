<?php
namespace app\Auth;

use app\Auth\Clientstate;

// use app\ConfigHandler;
// use app\FileHandler;

class Connection
{
    private static $_connectorTable;
    private static $_checkTable;

    /**
     * 创建连接池缓存table
     */
    public function createConnectorTable()
    {
        //创建表格 行数参数大小得为2的指数
        self::$_connectorTable = new \swoole_table(1000);

        // 表字段
        self::$_connectorTable->column('state', \swoole_table::TYPE_INT, 2); // 1,2,4,8
        self::$_connectorTable->column('createTime', \swoole_table::TYPE_STRING, 20);
        self::$_connectorTable->column('username', \swoole_table::TYPE_STRING, 30);
        self::$_connectorTable->column('userinfo', \swoole_table::TYPE_STRING, 300);

        self::$_connectorTable->create();
    }

    /**
     * 创建待检池缓存table
     */
    public function createCheckTable()
    {
        //创建表格 行数参数大小得为2的指数
        self::$_checkTable = new \swoole_table(65536);

        // 表字段
        self::$_checkTable->column('createTime', \swoole_table::TYPE_STRING, 20);

        self::$_checkTable->create();
    }

    /**
     * 获取连接信息
     * @param int $fd
     * 连接id
     * @return array
     */
    public function getConnector($fd)
    {
        $result = self::$_connectorTable->get($fd);
        if (!$result) {
            $result = array();
        }
        return $result;
    }

    /**
     * [getCache 获取fd的缓存]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-07-16
     * ------------------------------------------------------------------------------
     * @param   [type]          $fd [description]
     * @return  [type]              [description]
     */
    public function getCache($fd, $key = null)
    {
        $connector = $this->getConnector($fd);
        if (!empty($connector)) {

            if ($key) {
                return $connector[$key];
            }

            return $connector;
        }

        return null;
    }

    /**
     * 保存当前连接到连接池
     * @param int $fd
     * @param int
     * @param int $userId
     */
    public function saveConnector($fd, $param=[])
    {
        $arr = $this->getConnector($fd);

        if (!array_key_exists("createTime", $arr)) {
            $arr["createTime"] = time();
        }

        if (!empty($param['state'])) {
            $arr['state'] = $param['state'];
        } else {
            if (empty($arr['state'])) {
                $arr['state'] = Clientstate::Init;
            }
        }

        if (!empty($param['username'])) {
            $arr['username'] = $param['username'];
        }

        if (!empty($param['userinfo'])) {
            $arr['userinfo'] = $param['userinfo'];
        }

        // 保存连接
        self::$_connectorTable->set($fd, $arr);
    }

    //当客户端发送数据后删除待检池
    public function update_checkTable($fd)
    {
        self::$_checkTable->del($fd);
    }

    /**
     * 将连接从连接池中移除，并移除用户信息
     * @param int $fd
     */
    public function removeConnector($fd)
    {
        // 移除连接池
        self::$_connectorTable->del($fd);
    }

    /**
     * 验证用户 包括加密解密验证、token验证、时效性验证
     * @param string $token
     * 登录凭证
     * @return boolean
     */
    public function validateConnector($token)
    {
        // TODO 根据实际情况对token进行验证，这里直接通过
        return true;
    }

    /**
     * 清理非法连接
     *
     * @param swoole_server $serv
     */
    public function clearInvalidConnection($serv)
    {
        if (self::$_checkTable) {
            // AUTH_LOG("check count0: ".count(self::$_checkTable));
            foreach (self::$_checkTable as $key => $value) {
                $connector = $this->getConnector($key);
                if (empty($connector)) {
                    AUTH_LOG("Remove and close : " . $key);

                    //连接不在连接池，从待检池移除并关闭连接
                    self::$_checkTable->del($key);
                    $serv->close($key);
                    continue;
                } else if ($connector['state'] > Clientstate::Init || !$serv->exist($key)) {
                    AUTH_LOG("Remove to be connected : " . $key);
                    //已正常连接或者连接已不存在从待检池移除
                    self::$_checkTable->del($key);
                    continue;
                }

                $createTime = $connector["createTime"];
                if ($createTime < strtotime("-5 seconds")) {
                    AUTH_LOG("Expired! Remove and close : " . $key);
                    //过期，从待检池移除并关闭连接
                    self::$_checkTable->del($key);
                    $serv->close($key);
                    continue;
                }

            }
            // AUTH_LOG("check count1: ".count(self::$_checkTable));
        }
    }

    /**
     * 保存连接到待检池
     * @param int $fd
     */
    public function saveCheckConnector($fd)
    {
        $arr = array(
            "createTime" => time(),
        );
        self::$_checkTable->set($fd, $arr);
    }

    /**
     * 清理数据
     */
    public function clearData()
    {
        // $connectorDir=ConfigHandler::getLocalConfigs("connectorDir");
        // FileHandler::deleteFiles($connectorDir);
    }

}
