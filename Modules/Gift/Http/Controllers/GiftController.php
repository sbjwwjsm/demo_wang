<?php

namespace Modules\Gift\Http\Controllers;

use App\Http\Constants\ApiStatus;
use App\Http\Helpers\RedisHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Gift\Http\Repositories\GiftRepository;
use Modules\Gift\Models\Gift;
use Modules\Gift\Models\User;
use Throwable;

class GiftController extends Controller
{
    /**
     * 赠送幸运礼物(按组赠送礼物)
     * User:ytx
     * DateTime:2023/2/24 15:33
     */
    public function giveGroupGift(Request $request): JsonResponse
    {

        $uid          = $request->uid;
        $to_uid       = $request->input('to_uid', ''); # '1,2,3' 多用户(全麦)
        $to_uid       = array_filter(explode(',', $to_uid));
        $gift_id      = $request->input('gift_id', 0);
        $scene        = $request->input('scene', ''); # room|black|chat|help|bar_greet|bar_dating
        $scene_id     = (int)$request->input('scene_id', 0); # 房间id|小黑屋id,聊天会话id
        $number_group = (int)$request->input('number_group', 1);# 礼物组数，默认最小组数1组

        if (in_array($uid, $to_uid)) {
            return responseError([0, '不能送给自己']);
        }

        //防止高并发 礼物信息加入redis缓存 数据存在取redis 不存在取数据库在存redis
        $giftkey       = 'luckgift' . $gift_id;
        $GiveGiftRedis = RedisHelper::get($giftkey);
        if ($GiveGiftRedis) {
            $giftInfo = json_decode($GiveGiftRedis, true);
        } else {
            $giftInfo = Gift::query()->where(['id' => $gift_id, 'status' => 1])->with('unit_price')->first();
            if (empty($giftInfo)) {
                return responseError([0, '所选礼物不存在，无法赠送']);
            }
            RedisHelper::set($giftkey, json_encode($giftInfo));
        }

        # 礼物接收者人数
        $to_uid_num = count($to_uid);

        # 礼物单价（单价*倍数=返现的币数）
        $giftUnitCoin = $giftInfo['unit_price']['coin'];

        # 礼物所需总价值
        $giftTotalNeedCoin = bcmul($giftInfo['coin'], $number_group * $to_uid_num, 8);

        # 每个人收到的礼物价值
        $eachUserGiftCoin = bcmul($giftInfo['coin'], $number_group, 8);

        # 校验用户币余额是否充足
        $userCoinBalance = User::query()->where(['id' => $uid])->value('coin');
        if ($userCoinBalance < $giftTotalNeedCoin) {
            return responseError(ApiStatus::PLATFORM_COIN_INSUFFICIENT);
        }

		# 一次性 扣除金币，单次异常是按照需求在异常再处理（这样金币就不会一次次的递减，造成用户数据卡顿时显示问题
                if ($userCoinBalance < $$eachUserGiftCoin * $to_uid_num) {
                    throw new \Illuminate\Database\QueryException('余额不足，扣款失败', [], new \PDOException());
                }
				//利用mysql 原子性 捕获超出范围异常
				try{
					$affectedRows = User::query()->where('id', $uid)->decrement('coin', $eachUserGiftCoin * $to_uid_num);
					//可以判断$affectedRows  如果框架是否可以判断
				}catch(PDOException $e){
					if ($e->getCode() == '22003') { // MySQL 错误代码 22003 表示数值超出范围
					// 捕获 MySQL 的数值超出范围异常
					throw new \Illuminate\Database\QueryException('余额不足，扣款失败', [], $e);
				}
		
        try {
            foreach ($to_uid as $v) {
                # 赠送礼物（可以协程或异步操作
                GiftRepository::Factory()->doGiveGroupGift2($uid, $v, $giftInfo, $scene, $scene_id, $number_group, $eachUserGiftCoin, $giftUnitCoin);
				
            }
            return responseSuccess();
        } catch (Throwable $e) {
            dp('礼物赠送出错,结束任务');
            return responseError([0, $e->getMessage()]);
        }
    }
}
