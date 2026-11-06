AhaSend Bridge
==============

Provides AhaSend integration for Symfony Mailer, including API v2, on Symfony
7.4, 8.0 and 8.1.

This is a fork of [symfony/aha-send-mailer](https://github.com/symfony/aha-send-mailer)
at its 8.2 branch. On Symfony 7.4, 8.0 and 8.1 the official bridge only speaks the
legacy API v1, and AhaSend no longer issues v1 API keys. API v2 support arrives in
Symfony 8.2, as a new feature, so it does not reach earlier versions.

Installation
------------

```sh
composer require ahasend/symfony-mailer
```

That's all. This package replaces `symfony/aha-send-mailer`, which Composer removes
if it is installed. It keeps the bridge's class names and service IDs, so Symfony
wires it the way it wires the official bridge, and your DSN, webhook configuration,
event listeners and code keep working unchanged.

Configuration example:

```env
# SMTP
MAILER_DSN=ahasend+smtp://USERNAME:PASSWORD@default

# API
MAILER_DSN=ahasend+api://API_KEY:ACCOUNT_ID@default

# API (legacy v1, deprecated)
MAILER_DSN=ahasend+api://API_KEY@default
```

where:
 - `USERNAME` is your AhaSend SMTP Credentials username
 - `PASSWORD` is your AhaSend SMTP Credentials password
 - `API_KEY` is your AhaSend API key
 - `ACCOUNT_ID` is your AhaSend account ID; without it, the key is used with the legacy v1 API

Sending emails through the AhaSend v1 API is deprecated. An `ahasend+api://` DSN
without an account ID still selects that legacy API, but only for the API keys
created before v2: a v2 API key (prefixed with `aha-sk-`) without an account ID is
rejected. Use a v2 API key and add your account ID to the DSN instead.

> [!NOTE]
> As in Symfony 8.2, the ID of the [remote events][webhook] received from AhaSend
> is the `Message-ID` of the message, which the v2 API returns when sending it, so
> those events can be matched with the messages you sent. This applies to all
> AhaSend webhooks, whatever the transport used to send the messages. It used to be
> the internal ID of the event, which is still available in the payload under
> `data.id`, so **update any code that stored or compared the old ID**.

Differences from the official 8.2 bridge
----------------------------------------

The code is the official bridge's, and its test suite runs here on every supported
Symfony version. See the changes with `git diff 8.2...main`:

 - Installs on Symfony 7.4, 8.0 and 8.1. `TrackingHeader`, new in Symfony 8.2, is
   included for those versions, so you can use it there too.
 - API v2: inline images send their `content_id` in angle brackets, as the
   [API reference](https://ahasend.com/docs/api-reference) requires. Without them
   AhaSend delivers the image as a regular attachment.
 - API v2: Bcc addresses are not sent as a `Bcc` header. They are still recipients.
 - API v2: AhaSend's [SMTP special headers](https://ahasend.com/docs/smtp/special-headers)
   (`AhaSend-Tags`, `AhaSend-Track-Opens`, `AhaSend-Track-Clicks`, `AhaSend-Sandbox`,
   `AhaSend-Sandbox-Result`, `AhaSend-Metadata-Retention`, `AhaSend-Data-Retention`)
   are moved to the API's fields, so code written for SMTP keeps its meaning. A
   malformed value throws before anything is sent.

Upgrading to Symfony 8.2
------------------------

Switch back to the official bridge; your DSN and configuration stay the same:

```sh
composer remove ahasend/symfony-mailer
composer require symfony/aha-send-mailer
```

Development
-----------

```sh
composer install
vendor/bin/phpunit
```

`bin/live-check` sends a set of sandbox messages through the real API (nothing is
delivered):

```sh
AHASEND_API_KEY=aha-sk-... AHASEND_ACCOUNT_ID=... AHASEND_FROM_EMAIL=you@your-verified-domain.example bin/live-check
```

To run the tests against another Symfony version, pin it with Flex:

```sh
composer config --no-plugins allow-plugins.symfony/flex true
composer config extra.symfony.require "7.4.*"
composer require --dev --no-update symfony/flex
composer update
```

Resources
---------

 * [Symfony Mailer documentation](https://symfony.com/doc/current/mailer.html)
 * [Symfony Webhook documentation][webhook]
 * [AhaSend API reference](https://ahasend.com/docs/api-reference)

License
-------

MIT, as the official bridge. See [LICENSE](LICENSE).

[webhook]: https://symfony.com/doc/current/webhook.html
