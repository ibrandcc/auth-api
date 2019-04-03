<?php

/*
 * This file is part of ibrand/auth-api.
 *
 * (c) iBrand <https://www.ibrand.cc>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace iBrand\Auth\Api\Controllers;

use EasyWeChat;
use iBrand\Common\Exceptions\Exception;
use iBrand\Common\Wechat\Factory;
use iBrand\Component\User\Repository\UserBindRepository;
use iBrand\Component\User\Repository\UserRepository;
use iBrand\Component\User\UserService;

/**
 * Class MiniProgramLoginController
 * @package iBrand\Auth\Api\Controllers
 */
class MiniProgramLoginController extends Controller
{
    /**
     * @var UserRepository
     */
    protected $userRepository;
    /**
     * @var UserBindRepository
     */
    protected $userBindRepository;
    /**
     * @var UserService
     */
    protected $userService;
    /**
     * @var
     */
    protected $miniProgramService;

    /**
     * MiniProgramLoginController constructor.
     * @param UserRepository $userRepository
     * @param UserBindRepository $userBindRepository
     * @param UserService $userService
     */
    public function __construct(UserRepository $userRepository, UserBindRepository $userBindRepository, UserService $userService)
    {
        $this->userRepository = $userRepository;
        $this->userBindRepository = $userBindRepository;
        $this->userService = $userService;
    }

    /**
     * @return \Illuminate\Http\Response|mixed
     * @throws EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws Exception
     */
    public function login()
    {
        $code = request('code');

        if (empty($code)) {
            return $this->failed('missing code parameters.');
        }

        $miniProgram = $this->getMiniprogramApp();

        $result = $miniProgram->auth->session($code);

        if (!isset($result['openid'])) {
            return $this->failed('获取openid失败.');
        }

        $openid = $result['openid'];

        //1. openid 不存在相关用户和记录，直接返回 openid
        if (!$userBind = $this->userBindRepository->getByOpenId($openid)) {
            $userBind = $this->userBindRepository->create(['open_id' => $openid, 'type' => 'miniprogram',
                'app_id' => $this->getMiniprogramAppId(), ]);

            return $this->success(['open_id' => $openid]);
        }

        //2. openid 不存在相关用户，直接返回 openid
        if (!$userBind->user_id) {
            return $this->success(['open_id' => $openid]);
        }

        //3. 绑定了用户,直接返回 token
        $user = $this->userRepository->find($userBind->user_id);

        $token = $user->createToken($user->id)->accessToken;

        return $this->success(['token_type' => 'Bearer', 'access_token' => $token]);
    }

    /**
     * @return \Illuminate\Http\Response|mixed
     * @throws EasyWeChat\Kernel\Exceptions\DecryptException
     * @throws EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws Exception
     */
    public function mobileLogin()
    {
        //1. get session key.
        $code = request('code');

        $miniProgram = $this->getMiniprogramApp();

        $result = $miniProgram->auth->session($code);

        if (!isset($result['session_key'])) {
            return $this->failed('获取 session_key 失败.');
        }

        $sessionKey = $result['session_key'];

        //2. get phone number.
        $encryptedData = request('encryptedData');

        $iv = request('iv');

        $decryptedData = $miniProgram->encryptor->decryptData($sessionKey, $iv, $encryptedData);

        if (!isset($decryptedData['purePhoneNumber'])) {
            return $this->failed('获取手机号失败.');
        }

        $mobile = $decryptedData['purePhoneNumber'];

        //3. get or create user.
        if (!$user = $this->userRepository->getUserByCredentials(['mobile' => $mobile])) {
            $data = ['mobile' => $mobile];
            $user = $this->userRepository->create($data);
        }

        $token = $user->createToken($user->id)->accessToken;

        $this->userService->bindPlatform($user->id, request('open_id'), $this->getMiniprogramAppId(), 'miniprogram');

        return $this->success(['token_type' => 'Bearer', 'access_token' => $token]);
    }

    /**
     * @return \Illuminate\Http\Response|mixed
     * @throws EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws Exception
     */
    public function getOpenIdByCode()
    {
        $miniProgram = $this->getMiniprogramApp();

        $code = request('code');

        if (empty($code)) {
            return $this->failed('缺失code');
        }

        $result = $miniProgram->auth->session($code);

        if (!isset($result['openid'])) {
            return $this->failed('获取openid失败.');
        }

        $openid = $result['openid'];

        return $this->success(compact('openid'));
    }

    /**
     * @return EasyWeChat\MiniProgram\Application
     *
     * @throws Exception
     */
    protected function getMiniprogramApp(): EasyWeChat\MiniProgram\Application
    {
        $app = request('app') ?? 'default';

        if (!config('ibrand.wechat.mini_program.'.$app.'.app_id') or !config('ibrand.wechat.mini_program.'.$app.'.secret')) {
            throw new Exception('please set wechat miniprogram account.');
        }

        $options = [
            'app_id' => config('ibrand.wechat.mini_program.'.$app.'.app_id'),
            'secret' => config('ibrand.wechat.mini_program.'.$app.'.secret'),
        ];

        $miniProgram = Factory::miniProgram($options);

        return $miniProgram;
    }

    /**
     * @return \Illuminate\Config\Repository|mixed
     */
    protected function getMiniprogramAppId()
    {
        $app = request('app') ?? 'default';

        return config('ibrand.wechat.mini_program.'.$app.'.app_id');
    }
}