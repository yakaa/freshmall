<?php

namespace app\api\controller;

use app\api\model\Cart as CartModel;
use app\api\model\Order as OrderModel;
use app\api\model\Wxapp as WxappModel;
use app\common\library\wechat\WxPay;
use app\common\model\Balance as BalanceModel;
use app\task\service\NotifyService;
use think\Log;

/**
 * 订单控制器
 * Class Order
 * @package app\api\controller
 */
class Order extends Controller
{
    /* @var \app\api\model\User $user */
    private $user;

    /**
     * 构造方法
     * @throws \app\common\exception\BaseException
     * @throws \think\exception\DbException
     */
    public function _initialize()
    {
        parent::_initialize();
        $this->user = $this->getUser();   // 用户信息
    }

    /**
     * 订单确认-立即购买
     * @param $goods_id
     * @param $goods_num
     * @param $goods_sku_id
     * @return array
     * @throws \app\common\exception\BaseException
     * @throws \think\exception\DbException
     * @throws \Exception
     */
    public function buyNow($goods_id, $goods_num, $goods_sku_id, $delivery_time = null)
    {
        // 商品结算信息
        $model = new OrderModel;
        $order = $model->getBuyNow($this->user, $goods_id, $goods_num, $goods_sku_id);

        if (!$this->request->isPost()) {
            return $this->renderSuccess($order);
        }
        if ($model->hasError()) {
            return $this->renderError($model->getError());
        }
        $order['remark'] = $this->request->post('remark');
        if (!isset($order['delivery_time'])) $order['delivery_time'] = $delivery_time;

        // 创建订单
        if ($model->add($this->user['user_id'], $order)) {
            // 如果 使用余额支付的
            if ($this->request->post('use_balance')) {
                try {
                    NotifyService::updateOrder($model['order_no'], BalanceModel::buildTradeNo(), true);
                    return $this->renderSuccess($model, '支付成功');
                } catch (\Exception $exception) {
                    $m = $exception->getCode() == 1 ? $exception->getMessage() : '支付失败';
                    return $this->renderError($m);
                }
            }
            NotifyService::pushOrderMegToMQ($model, '/task/notify/cancel', 'trade.order.close_days');
            // 发起微信支付
            return $this->renderSuccess([
                'payment'  => $this->wxPay($model['order_no'], $this->user['open_id']
                    , $order['order_pay_price']),
                'order_id' => $model['order_id']
            ]);

        }
        $error = $model->getError() ?: '订单创建失败';
        return $this->renderError($error);
    }


    /**
     * 订单确认-购物车结算
     * @return array
     * @throws \app\common\exception\BaseException
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \Exception
     */
    public function cart($delivery_time = null)
    {
        // 商品结算信息
        $model = new OrderModel;
        $order = $model->getCart($this->user);
        if (!$this->request->isPost()) {
            return $this->renderSuccess($order);
        }
        if (!isset($order['delivery_time'])) $order['delivery_time'] = $delivery_time;
        $order["remark"] = $this->request->post("remark");
        // 创建订单
        if ($model->add($this->user['user_id'], $order)) {
            // 清空购物车
            $Card = new CartModel($this->user['user_id']);
            $Card->clearAll();
            // 如果 使用余额支付的
            if ($this->request->post('use_balance')) {
                try {
                    NotifyService::updateOrder($model['order_no'], BalanceModel::buildTradeNo(), true);
                    return $this->renderSuccess($model, '支付成功');
                } catch (\Exception $exception) {
                    $m = $exception->getCode() == 1 ? $exception->getMessage() : '支付失败';
                    return $this->renderError($m);
                }
            }

            // 发起微信支付
            return $this->renderSuccess([
                'payment'  => $this->wxPay($model['order_no'], $this->user['open_id']
                    , $order['order_pay_price']),
                'order_id' => $model['order_id']
            ]);
        }
        $error = $model->getError() ?: '订单创建失败';
        return $this->renderError($error);
    }


    /** 打单服务
     * @return array
     */
    public function printOrder()
    {
        $orderList = [];
        try {
            $canWrite  = $this->request->get('canWrite', false);
            $connected = $this->request->get('connected', false);
            Log::info('canWrite=>' . $canWrite);
            Log::info('connected=>' . $connected);
            if (!$canWrite || $canWrite == 'false' || !$connected || $connected == 'false') {
                return $this->renderSuccess($orderList);
            }

            $model   = new OrderModel;
            $orderNo = $model->findPrintOrderNoOrCreate();
            if ($orderNo) {
                $order = $model->where('order_no', '=', $orderNo)->limit(1)->field(['order_id'])->find();
                if (isset($order->order_id) && !empty($order->order_id)) {
                    $orderList[] = OrderModel::detail($order->order_id);
                }
            }
            return $this->renderSuccess($orderList);
        } catch (\Exception $exception) {

        }
        return $this->renderSuccess($orderList);
    }

    /**
     * 构建微信支付
     * @param $order_no
     * @param $open_id
     * @param $pay_price
     * @return array
     * @throws \app\common\exception\BaseException
     * @throws \think\exception\DbException
     */
    private function wxPay($order_no, $open_id, $pay_price)
    {
        $wxConfig = WxappModel::getWxappCache();
        $WxPay    = new WxPay($wxConfig);
        return $WxPay->unifiedorder($order_no, $open_id, $pay_price);
    }


}
