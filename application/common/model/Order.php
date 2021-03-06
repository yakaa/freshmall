<?php

namespace app\common\model;

use think\cache\driver\Redis;
use think\Hook;

/**
 * 订单模型
 * Class Order
 * @package app\common\model
 */
class Order extends BaseModel
{
    protected $name = 'order';

    const PRINT_ORDER_QUINE = 'order:current:print_list';

    /**
     * 订单模型初始化
     */
    public static function init()
    {
        parent::init();
        // 监听订单处理事件
        $static = new static;
        Hook::listen('order', $static);
    }


    /** 记录已经支付的订单号,等待打印机打单
     * @param null $currentNo
     */
    public function findPrintOrderNoOrCreate($orderNo = null)
    {
        $redis     = new Redis();
        $currentNo = '';
        if (!empty($orderNo)) {
            $redis->handler()->hSet(self::PRINT_ORDER_QUINE, $orderNo, $orderNo);
            return $currentNo;
        }
        if ($redis->handler()->exists(self::PRINT_ORDER_QUINE)) {
            $hKeys     = $redis->handler()->hKeys(self::PRINT_ORDER_QUINE);
            $hKey      = current($hKeys);
            $currentNo = $redis->handler()->hGet(self::PRINT_ORDER_QUINE, $hKey);
            $redis->handler()->hDel(self::PRINT_ORDER_QUINE, $hKey);
            return $currentNo;
        }
        return $currentNo;
    }

    /**
     * 订单商品列表
     * @return \think\model\relation\HasMany
     */
    public function goods()
    {
        return $this->hasMany('OrderGoods');
    }


    /**
     * @param $userId
     * @param $balance
     * @param $orderNo
     * @return bool|false|int
     * @throws \Exception
     */
    public function consumerBalance($userId, $balance, $orderNo, $mark = '', $refund = false)
    {
        if (floatval($balance) <= 0) return false;

        $filter = ['user_id' => $userId, 'wxapp_id' => self::$wxapp_id];

        $balanceModel      = Balance::get($filter);
        if (empty($balanceModel)) $balanceModel = Balance::create(array_merge($filter,['balance'=>0]));

        if ($refund) {
            $balanceModel->setInc('balance', $balance);

            return (new BalanceDetail)->save([
                'user_id'        => $userId,
                'wxapp_id'       => self::$wxapp_id,
                'balance'        => $balance,
                'trade_status'   => 'FINISHED',
                'latest_balance' => Balance::myBalance($userId, true),
                'trade_no'       => Balance::buildTradeNo(),
                'type'           => Balance::TYPE_REFUND,
                'mark'           => $mark ? $mark : '订单退款到余额-' . $orderNo,
            ]);
        }

        $balanceModel->setDec('balance', $balance);
        return (new BalanceDetail)->save([
            'user_id'        => $userId,
            'wxapp_id'       => self::$wxapp_id,
            'balance'        => '-' . $balance,
            'trade_status'   => 'FINISHED',
            'latest_balance' => Balance::myBalance($userId, true),
            'trade_no'       => Balance::buildTradeNo(),
            'type'           => Balance::TYPE_CONSUMER,
            'mark'           => $mark ? $mark : '使用余额支付订单-' . $orderNo,
        ]);
    }

    public function balanceDetailModel()
    {
        return new Balance;
    }

    /**
     * 关联订单收货地址表
     * @return \think\model\relation\HasOne
     */
    public function address()
    {
        return $this->hasOne('OrderAddress');
    }

    /**
     * 关联用户表
     * @return \think\model\relation\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('User');
    }

    /**
     * 付款状态
     * @param $value
     * @return array
     */
    public function getPayStatusAttr($value)
    {
        $status = [10 => '待付款', 20 => '已付款'];
        return ['text' => $status[$value], 'value' => $value];
    }

    /**
     * 发货状态
     * @param $value
     * @return array
     */
    public function getDeliveryStatusAttr($value)
    {
        $status = [10 => '待发货', 20 => '已发货'];
        return ['text' => $status[$value], 'value' => $value];
    }

    /**
     * 收货状态
     * @param $value
     * @return array
     */
    public function getReceiptStatusAttr($value)
    {
        $status = [10 => '待收货', 20 => '已收货'];
        return ['text' => $status[$value], 'value' => $value];
    }

    /**
     * 收货状态
     * @param $value
     * @return array
     */
    public function getOrderStatusAttr($value)
    {
        $status = [10 => '进行中', 20 => '取消', 30 => '已完成'];
        return ['text' => $status[$value], 'value' => $value];
    }

    /**
     * 生成订单号
     */
    protected function orderNo()
    {
        return date('Ymd') . substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 8);
    }

    /**
     * 订单详情
     * @param $order_id
     * @return null|static
     * @throws \think\exception\DbException
     */
    public static function detail($order_id)
    {
        return self::get($order_id, ['goods.image', 'address']);
    }



}
