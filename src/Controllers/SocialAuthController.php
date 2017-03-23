<?php

namespace ZFort\SocialAuth\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use ZFort\SocialAuth\Events\SocialUserAuthenticated;
use ZFort\SocialAuth\Events\SocialUserDetached;
use ZFort\SocialAuth\Models\SocialProvider;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Auth\RedirectsUsers;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

use Laravel\Socialite\Contracts\Factory as Socialite;
use ZFort\SocialAuth\Exceptions\SocialGetUserInfoException;
use ZFort\SocialAuth\Exceptions\SocialUserAttachException;
use Laravel\Socialite\Contracts\User as SocialUser;
use Illuminate\Contracts\Auth\Authenticatable;
use ZFort\SocialAuth\SocialProviderManager;

/**
 * Class SocialAuthController
 * @package App\Http\Controllers
 *
 * Provide social auth logic
 */
class SocialAuthController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    use RedirectsUsers;

    /**
     * Redirect path
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * @var Guard auth provider instance
     */
    protected $auth;

    /**
     * @var Socialite
     */
    protected $socialite;

    /**
     * @var \ZFort\SocialAuth\Contracts\SocialAuthenticatable|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $userModel;

    /**
     * @var SocialProviderManager
     */
    protected $manager;

    /**
     * SocialAuthController constructor. Register Guard contract dependency
     * @param Guard $auth
     * @param Socialite $socialite
     */
    public function __construct(Guard $auth, Socialite $socialite)
    {
        $this->auth = $auth;
        $this->socialite = $socialite;
        $this->redirectTo = config('social-auth.redirect');

        $className = config('auth.providers.users.model');
        $this->userModel = new $className;

        $this->middleware(function ($request, $next) {
            $this->manager = new SocialProviderManager($request->route('social'));

            return $next($request);
        });
    }

    /**
     * If there is no response from the social network, redirect the user to the social auth page
     * else make create with information from social network
     *
     * @param SocialProvider $social bound by "Route model binding" feature
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function getAccount(SocialProvider $social)
    {
        $provider = $this->socialite->driver($social->slug);

        return $provider->redirect();
    }

    /**
     * Redirect callback for social network
     * @param Request $request
     * @param SocialProvider $social
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws SocialGetUserInfoException
     * @throws SocialUserAttachException
     */
    public function callback(Request $request, SocialProvider $social)
    {
        $provider = $this->socialite->driver($social->slug);

        $SocialUser = null;

        // try to get user info from social network
        try {
            $SocialUser = $provider->user();
        } catch (RequestException $e) {
            throw new SocialGetUserInfoException($social, $e->getMessage());
        }

        // if we have no social info for some reason
        if (!$SocialUser) {
            throw new SocialGetUserInfoException(
                $social,
                trans('social-auth::messages.no_user_data', ['social' => $social->label])
            );
        }

        // if user is guest
        if (!$this->auth->check()) {
            return $this->register($request, $social, $SocialUser);
        }

        // if user already attached
        if ($request->user()->isAttached($social->slug)) {
            throw new SocialUserAttachException(
                redirect($this->redirectPath())
                    ->withErrors(trans('social-auth::messages.user_already_attach', ['social' => $social->label])),
                $social
            );
        }

        //If someone already attached current socialProvider account
        if ($this->manager->socialUserQuery($SocialUser->getId())->exists()) {
            throw new SocialUserAttachException(
                redirect($this->redirectPath())
                    ->withErrors(trans('social-auth::messages.someone_already_attach')),
                $social
            );
        }

        $this->manager->attach($request->user(), $SocialUser);

        return redirect($this->redirectPath());
    }

    /**
     * Detaches social account for user
     *
     * @param Request $request
     * @param SocialProvider $social
     * @return array
     * @throws SocialUserAttachException
     */
    public function detachAccount(Request $request, SocialProvider $social)
    {
        $result = $request->user()->socials()->detach($social->id);

        if (!$result) {
            throw new SocialUserAttachException(
                back()->withErrors(trans('social-auth::messages.detach_error', ['social' => $social->label])),
                $social
            );
        }

        event(new SocialUserDetached($request->user(), $social, $result));

        return back();
    }

    /**
     * @param Request $request
     * @param SocialProvider $social
     * @param SocialUser $socialUser
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function register(Request $request, SocialProvider $social, SocialUser $socialUser)
    {
        //Checks by socialProvider identifier if user exists
        $exist_user = $this->manager->getUserByKey($socialUser->getId());

        //Checks if user exists with current socialProvider identifier, auth if does
        if ($exist_user) {
            $this->login($exist_user);

            return redirect($this->redirectPath());
        }

        //Checks if account exists with socialProvider email, auth and attach current socialProvider if does
        $exist_user = $this->userModel->where($this->userModel->getEmailField(), $socialUser->getEmail())->first();
        if ($exist_user) {
            $this->login($exist_user);

            $this->manager->attach($request->user(), $socialUser);

            return redirect($this->redirectPath());
        }

        //If account for current socialProvider data doesn't exist - create new one
        $new_user = $this->manager->createNewUser($this->userModel, $social, $socialUser);
        $this->login($new_user);

        return redirect($this->redirectPath());
    }

    /**
     * Login user
     *
     * @param Authenticatable $user
     */
    protected function login(Authenticatable $user)
    {
        $this->auth->login($user);
        event(new SocialUserAuthenticated($user));
    }
}
