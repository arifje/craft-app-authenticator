<?php

namespace arifje\craftappauthenticator;

use Craft;
use craft\base\Plugin;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * App Authenticator plugin
 *
 * @method static AppAuthenticator getInstance()
 */
class AppAuthenticator extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            
        });
		
		// Register pretty site routes for the AuthenticatorController
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_SITE_URL_RULES,
			function ($event) {
				$event->rules['_app-authenticator/login']            = '_app-authenticator/authenticator/login';
				$event->rules['_app-authenticator/create-token']     = '_app-authenticator/authenticator/create-token';
				$event->rules['_app-authenticator/validate']         = '_app-authenticator/authenticator/validate';
				$event->rules['_app-authenticator/login-with-token'] = '_app-authenticator/authenticator/login-with-token';
				$event->rules['_app-authenticator/logout']           = '_app-authenticator/authenticator/logout';
			}
		);

    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/4.x/extend/events.html to get started)
    }
}
