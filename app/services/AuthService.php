<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\LoginsFailed;
use App\Models\Users;
use Phalcon\Db\Column;
use Phalcon\Encryption\Security\JWT\Exceptions\ValidatorException;

/**
 * Business-logic for site frontend
 *
 * @AuthService
 * @\App\Services\AuthService
 * @uses \App\Services\AbstractService
 */
class AuthService extends AbstractService
{
    /**
     * @param array $data
     * @return array
     * @throws ValidatorException
     * @throws \RedisException
     */
    public function login(array $data): array
    {
        // Get email or username and convert to small letters
        $email = strtolower($data['email']);

        // Search with $email (email or username) current user
        $user = Users::findFirst(
            [
                'conditions' => 'email = :email: OR username = :username:',
                'bind' => [
                    'email' => $email,
                    'username' => $email
                ],
                'bindTypes' => [
                    Column::BIND_PARAM_STR,
                    Column::BIND_PARAM_STR
                ],
            ]
        );

        // If user is not found
        if (!$user) {
            $this->registerUserThrottling(0);
            throw new ServiceException(
                'Wrong email or password',
                self::ERROR_WRONG_EMAIL_OR_PASSWORD
            );
        }

        // Check the password
        if (!$this->security->checkHash($data['password'], $user->password)) {
            $this->registerUserThrottling($user->id);
            throw new ServiceException(
                'Wrong email or password',
                self::ERROR_WRONG_EMAIL_OR_PASSWORD
            );
        }

        // Check if the user was flagged
        $this->checkUserFlags($user);

        // Generate JWT tokens (access and refresh)
        $tokens = $this->jwt->generateTokens($user->id, $data['remember']);

        return [
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken']
        ];

    }

    /**
     * refreshJwtTokens
     * @retrun array
     * @throws \RedisException
     * @throws ValidatorException
     */
    public function refreshJwtTokens(): array
    {

        // Get JWT refresh token from headers
        $jwt = $this->jwt->getAuthorizationToken();
        $token = $this->jwt->decode($jwt);

        $this->jwt->validateJwt($token);

        // Check if jti is in the white list (redis)
        $jti = $token->getClaims()->getPayload()['jti'];
        $this->redisService->isJtiInWhiteList($jti);

        $userId = $token->getClaims()->getPayload()['sub'];
        // Remove JTI form redis white list and from user set
        $this->redisService->removeJti($jti, $userId);

        // Determine remember me
        $tokenExpiration = $token->getClaims()->getPayload()['exp'] - $token->getClaims()->getPayload()['nbf'];
        $remember = $tokenExpiration > $this->config->auth->refreshTokenExpire ? 1 : 0;

        $newTokens = $this->jwt->generateTokens($userId, $remember);

        return [
            'accessToken' => $newTokens['accessToken'],
            'refreshToken' => $newTokens['refreshToken'],
        ];
    }

    /**
     * Get user ID
     * @retrun int
     * */
    public function userId(): int
    {
        $jwtToken = $this->jwt->getAuthorizationToken();
        return (int)$this->jwt->decode($jwtToken)->getClaims()->getPayload()['sub'];
    }


    /**
     * Verify JWT token
     *
     * @return bool
     * @throws ValidatorException
     */
    public function verifyToken(): bool
    {
        // Get JWT refresh token from headers
        $jwt = $this->jwt->getAuthorizationToken();
        $token = $this->jwt->decode($jwt);
        $this->jwt->validateJwt($token);
        return true;
    }

    /**
     * Implements login throttling
     * Reduces the effectiveness of brute force attacks
     *
     * @param int $userId
     */
    private function registerUserThrottling(int $userId)
    {
        $failedLogin = new LoginsFailed();
        $failedLogin->user_id = $userId;
        $clientIpAddress = $this->request->getClientAddress();
        $userAgent = $this->request->getUserAgent();
        $failedLogin->ip_address = empty($clientIpAddress) ? null : $clientIpAddress;
        $failedLogin->user_agent = empty($userAgent) ? null : substr($userAgent, 0, 250);
        $failedLogin->attempted = time();
        $failedLogin->save();

        $attempts = LoginsFailed::count([
            'ip_address = ?0 AND attempted >= ?1',
            'bind' => [
                $this->request->getClientAddress(),
                time() - 3600 * 6 // 6 minutes
            ]
        ]);

        switch ($attempts) {
            case 1:
            case 2:
                // no delay
                break;
            case 3:
            case 4:
                sleep(2);
                break;
            default:
                sleep(4);
                break;
        }
    }

    /**
     * Checks if the user is banned/inactive/suspended
     * @param \App\Models\Users $user
     * @throws ServiceException
     */
    private function checkUserFlags(Users $user)
    {
        if ($user->deleted_at != null) {
            throw new ServiceException(
                'The user is deleted',
                self::ERROR_ACCOUNT_DELETED
            );
        }

        if ($user->active != 1) {
            throw new ServiceException(
                'The user is inactive',
                self::ERROR_USER_NOT_ACTIVE
            );
        }
    }

}