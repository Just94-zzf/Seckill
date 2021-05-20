<?php

declare(strict_types=1);

namespace app\home\service;

/**
 * 秒杀逻辑
 */
class SeckillService
{
    static $redis;
    static $userId;
    static $productId;
    static $config;
    //redis
    static $REDIS_HT_KEY = 'seckill_product_%s'; //共享信息key
    static $REDIS_REMOTE_HT_KEY = 'product_%s'; //共享信息key
    static $REDIS_REMOTE_TOTAL_COUNT = 'total_count'; //商品总库存
    static $REDIS_REMOTE_USE_COUNT = 'used_count'; //已售库存
    static $REDIS_REMOTE_QUEUE = 'c_order_queue_%s'; //创建订单队列
    static $REDIS_REMOTE_SET = 'c_order_set_%s'; //用户限购集合
    static $REDIS_REMOTE_USER_LIMIT_KEY = 'user_%s_%u';//单个商品限购

    //本地
    static $APCU_LOCAL_STOCK = 'apcu_stock_%s'; //总共剩余库存
    static $APCU_LOCAL_USE = 'apcu_stock_use_%s'; //本地已售库存
    static $APCU_LOCAL_COUNT = 'apcu_stock_count_%s'; //本地分摊总库存

    public function __construct($userId, $productId, object $redis)
    {
        self::$REDIS_HT_KEY = sprintf(self::$REDIS_HT_KEY, $productId);
        self::$REDIS_REMOTE_HT_KEY = sprintf(self::$REDIS_REMOTE_HT_KEY, $productId);
        self::$APCU_LOCAL_STOCK = sprintf(self::$APCU_LOCAL_STOCK, $productId);
        self::$APCU_LOCAL_USE = sprintf(self::$APCU_LOCAL_USE, $productId);
        self::$APCU_LOCAL_COUNT = sprintf(self::$APCU_LOCAL_COUNT, $productId);
        self::$REDIS_REMOTE_QUEUE = sprintf(self::$REDIS_REMOTE_QUEUE, $productId);
        self::$REDIS_REMOTE_SET = sprintf(self::$REDIS_REMOTE_SET, $productId);
        self::$REDIS_REMOTE_USER_LIMIT_KEY = sprintf(self::$REDIS_REMOTE_USER_LIMIT_KEY, $productId, $userId);
        self::$userId = $userId;
        self::$productId = $productId;
        self::$redis = $redis;
    }

    //初始化 本地本件缓存
    private function local_stock_init()
    {
        //获取远端库存-按数量分摊
        // $stock = $this->parent_stock();
        // $average_num = (int)$stock / self::$config['server_num'] + 50;
        $average_num = $this->parent_stock();

        self::$redis->redisGetHash(self::$REDIS_REMOTE_HT_KEY, self::$APCU_LOCAL_STOCK, $average_num); //本地库存
        self::$redis->redisGetHash(self::$REDIS_REMOTE_HT_KEY, self::$APCU_LOCAL_USE, 0); //本地已用
        self::$redis->redisGetHash(self::$REDIS_REMOTE_HT_KEY, self::$APCU_LOCAL_COUNT, $average_num); //本地分摊
        self::$redis->redisGetHash(self::$REDIS_REMOTE_HT_KEY, self::$REDIS_REMOTE_TOTAL_COUNT, $average_num);
    }

    //读取数据
    public function local_stock()
    {
        return self::$redis->redisHashGetAll(self::$REDIS_REMOTE_HT_KEY);
    }

    //获取远端库存
    public function parent_stock()
    {
        return self::$redis->redisHashGet(self::$REDIS_HT_KEY, self::$REDIS_REMOTE_TOTAL_COUNT);
    }

    //抢购流程
    public function add_stock()
    {
        //判断本地缓存是否存在
        $local_stock = $this->local_stock();
        if (!$local_stock) {
            $this->local_stock_init(); //初始化
        }

        //看是否存在分布式锁 限购1个 60秒令牌
        $redis_sock = self::$redis->redisIdempotent(self::$REDIS_REMOTE_USER_LIMIT_KEY, 'ok', 60);
        if (!$redis_sock) {
            return array('msg' => '请勿重复抢购！', 'code' => 402);
        }

        //减扣本地库存
        $localUse = $this->apcu_inc();
        if ($localUse == false) {
            return array('msg' => '抢购失败！', 'code' => 404);
        }

        //同步远端库存
        $localCount = $this->incUseCount();
        if ($localCount == false) {
            return array('msg' => '抢购失败！', 'code' => 401);
        }

        //创建订单队列->远端队列
        self::$redis->redisList(self::$REDIS_REMOTE_QUEUE, (string)self::$userId);
        return array('msg' => '抢购成功', 'code' => 200);
    }

    //判断库存状态
    public function apcu_inc(): bool
    {
        $stock = $this->local_stock();
        //更改本地缓存
        $stock[self::$APCU_LOCAL_USE] = $stock[self::$APCU_LOCAL_USE] + 1;
        $stock[self::$APCU_LOCAL_STOCK] = $stock[self::$APCU_LOCAL_STOCK] - 1;

        if ($stock[self::$APCU_LOCAL_USE] > $stock[self::$APCU_LOCAL_COUNT]) {
            return false;
        }

        if ($stock[self::$APCU_LOCAL_STOCK] <= 0) {
            return false;
        }

        self::$redis->redisHashInc(self::$REDIS_REMOTE_HT_KEY, self::$APCU_LOCAL_USE, 1);
        self::$redis->redisHashInc(self::$REDIS_REMOTE_HT_KEY, self::$APCU_LOCAL_STOCK, -1);

        return true;
    }

    //库存同步
    private static function incUseCount()
    {
        $script = <<<eof
            local key = KEYS[1]
            local field1 = KEYS[2]
            local field2 = KEYS[3]
            local field1_val = redis.call('hget', key, field1)
            local field2_val = redis.call('hget', key, field2)
            if(tonumber(field1_val) > tonumber(field2_val)) then
               return redis.call('HINCRBY', key, field2, 1)
            end
            return 0
        eof;

        return self::$redis->redisEval($script, [self::$REDIS_HT_KEY, self::$REDIS_REMOTE_TOTAL_COUNT, self::$REDIS_REMOTE_USE_COUNT], 3);
    }
}
